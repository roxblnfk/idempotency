<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Http;

use Psr\Http\Message\ServerRequestInterface;
use Spiral\Idempotency\KeySourceInterface;
use Spiral\Interceptors\Context\AttributedInterface;

/**
 * Extracts raw key material from a PSR-7 request: the `Idempotency-Key` header first, then a request
 * field (form body / query) as a fallback. This is the HTTP-transport implementation of
 * {@see KeySourceInterface} — bound into the HTTP request scope so the interceptor, running in that
 * scope, receives it automatically (no proxy needed). Other transports bind their own source in their
 * own scope (gRPC metadata, queue payload, ...).
 *
 * Accepts either the {@see ServerRequestInterface} directly or the call context carrying it (the
 * interceptor hands over the whole context, keeping itself free of PSR-7). Stateless — safe to share.
 *
 * @api
 */
final readonly class HttpKeySource implements KeySourceInterface
{
    /**
     * @param non-empty-string $header request header carrying the key
     * @param non-empty-string $field body/query field used when the header is absent
     */
    public function __construct(
        private string $header = 'Idempotency-Key',
        private string $field = 'key',
    ) {}

    public function extract(mixed $endpointContext): ?string
    {
        $request = $this->request($endpointContext);
        if ($request === null) {
            return null;
        }

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

    private function request(mixed $endpointContext): ?ServerRequestInterface
    {
        if ($endpointContext instanceof ServerRequestInterface) {
            return $endpointContext;
        }

        if ($endpointContext instanceof AttributedInterface) {
            $request = $endpointContext->getAttribute(ServerRequestInterface::class);

            return $request instanceof ServerRequestInterface ? $request : null;
        }

        return null;
    }
}
