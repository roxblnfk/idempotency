<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Http;

use Psr\Http\Message\ServerRequestInterface;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Interceptors\Context\AttributedInterface;

/**
 * HTTP resolution middleware: extracts the raw key from a PSR-7 request in the call context (the
 * `Idempotency-Key` header, then a body/query field) and normalizes it via the shared
 * {@see KeyResolverInterface}. Replaces the old scope-bound `HttpKeySource` — the request now travels
 * in {@see IdempotencyCall::$context}, so no scope binding is needed; add this middleware to the HTTP
 * pipeline in config instead.
 *
 * Pass-through when the key is already resolved (e.g. from the attribute's arg path) or when the
 * context is not an HTTP request (type-guard, so it is inert in a non-HTTP stack).
 *
 * @api
 */
final readonly class HttpKeyMiddleware implements ResolutionMiddleware
{
    /**
     * @param non-empty-string $header request header carrying the key
     * @param non-empty-string $field body/query field used when the header is absent
     */
    public function __construct(
        private KeyResolverInterface $resolver,
        private string $header = 'Idempotency-Key',
        private string $field = 'key',
    ) {}

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        if ($call->key !== null) {
            return $next($call);
        }

        $request = $this->request($call->context);
        if ($request === null) {
            return $next($call);
        }

        $raw = $this->extract($request)
            ?? throw new MissingKeyException(\sprintf(
                'Idempotency key is required: pass the "%s" header or the "%s" body/query field.',
                $this->header,
                $this->field,
            ));

        return $next($call->withKey($this->resolver->resolve($raw)));
    }

    private function extract(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine($this->header);
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        if (\is_array($body) && isset($body[$this->field]) && \is_scalar($body[$this->field])) {
            return (string) $body[$this->field];
        }

        $query = $request->getQueryParams();

        return isset($query[$this->field]) && \is_scalar($query[$this->field])
            ? (string) $query[$this->field]
            : null;
    }

    private function request(mixed $context): ?ServerRequestInterface
    {
        if ($context instanceof ServerRequestInterface) {
            return $context;
        }

        if ($context instanceof AttributedInterface) {
            $request = $context->getAttribute(ServerRequestInterface::class);

            return $request instanceof ServerRequestInterface ? $request : null;
        }

        return null;
    }
}
