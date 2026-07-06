<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Spiral\Idempotency\Exception\CachedDomainFailureException;
use Spiral\Idempotency\Exception\ClassifiedException;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProviderInterface;
use Spiral\Idempotency\IdempotencyInterface;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Lease\LeaseManagerInterface;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\FailureClassifierInterface;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * The AtLeastOnce lease handler (the boundary between the two pipelines, spec-pipeline §0): it owns
 * `acquire` + the guaranteed terminal transition, and runs the operation through the
 * {@see $execution} pipeline (classify / retry / ... ) in between.
 *
 * Failure classification lives in the execution pipeline (a {@see \Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware}),
 * which rethrows a {@see ClassifiedException} carrying the {@see FailureKind}; this handler reads that
 * kind to pick the terminal transition. When no classifier ran (bare throwable), it falls back to its
 * own {@see $classifier}, so the handler stays correct with or without that middleware.
 *
 * @internal Built per storage alias by the factory; consumers resolve {@see IdempotencyInterface}
 *           from the {@see \Spiral\Idempotency\IdempotencyRegistry}. Not part of the public API.
 */
final readonly class LeaseIdempotency implements IdempotencyInterface, GuaranteeProviderInterface
{
    private SerializerInterface $serializer;
    private FailureClassifierInterface $classifier;

    /**
     * @param Pipeline<ExecutionCall> $execution wraps the operation (classify / retry / ...)
     * @param int<1, max> $lockTtl PROCESSING lock TTL, seconds (short)
     * @param int<1, max> $retentionTtl COMPLETED retention TTL, seconds (long)
     * @param positive-int $acquireRetryLimit bound on AcquireRetry loops
     */
    public function __construct(
        private LeaseManagerInterface $manager,
        private Pipeline $execution,
        private int $lockTtl = 30,
        private int $retentionTtl = 86400,
        ?SerializerInterface $serializer = null,
        ?FailureClassifierInterface $classifier = null,
        private int $acquireRetryLimit = 3,
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
        $this->classifier = $classifier ?? new DefaultFailureClassifier();
    }

    public function guarantee(): Guarantee
    {
        return Guarantee::AtLeastOnce;
    }

    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        $options ??= new ExecuteOptions();
        /** @var int<1, max> $lockTtl */
        $lockTtl = $options->lockTtl ?? $this->lockTtl;
        /** @var int<1, max> $retentionTtl */
        $retentionTtl = $options->ttl ?? $this->retentionTtl;

        $attempts = 0;
        do {
            $result = $this->manager->acquire($key, $lockTtl);

            if ($result instanceof Acquired) {
                return $this->run($result, $operation, $options, $retentionTtl);
            }

            if ($result instanceof AlreadyCompleted) {
                return $this->replay($result);
            }

            if ($result instanceof Locked) {
                throw new LockedException($result);
            }

            // AcquireRetry — loop and try acquire again.
        } while (++$attempts < $this->acquireRetryLimit);

        throw new IdempotencyException(\sprintf(
            'acquire() kept resolving to AcquireRetry for key "%s" after %d attempts.',
            $key,
            $attempts,
        ));
    }

    /**
     * @param int<1, max> $retentionTtl
     */
    private function run(Acquired $lease, \Closure $operation, ExecuteOptions $options, int $retentionTtl): mixed
    {
        $call = new ExecutionCall(new LeaseContext($lease->key), $operation, $options);

        try {
            $value = $this->execution->process(
                $call,
                static fn(ExecutionCall $c): mixed => ($c->operation)($c->context),
            );
        } catch (\Throwable $e) {
            $this->terminateFailure($lease, $e, $retentionTtl);
            // Surface the original throwable, not the ClassifiedException wrapper.
            throw $e instanceof ClassifiedException ? ($e->getPrevious() ?? $e) : $e;
        }

        if ($value instanceof Uncacheable) {
            // Transient outcome (e.g. 5xx): don't cache, release the key so a retry re-runs.
            $this->manager->abort($lease->key, $lease->token);

            return $value->value;
        }

        $this->manager->complete($lease->key, $lease->token, true, $this->encode($value), $retentionTtl);

        return $value;
    }

    /**
     * @param int<1, max> $retentionTtl
     */
    private function terminateFailure(Acquired $lease, \Throwable $e, int $retentionTtl): void
    {
        // The kind comes from the classifier middleware (via ClassifiedException); fall back to our own
        // classifier when the operation threw a bare throwable (no classifier middleware in the stack).
        $original = $e instanceof ClassifiedException ? ($e->getPrevious() ?? $e) : $e;
        $kind = $e instanceof ClassifiedException ? $e->kind : $this->classifier->classify($e);

        match ($kind) {
            // Domain: a valid (negative) outcome — cache a lightweight snapshot for idempotent replay.
            FailureKind::Domain => $this->manager->complete(
                $lease->key,
                $lease->token,
                false,
                $this->encodeFailure($original),
                $retentionTtl,
            ),
            // Bug: unrecoverable, do not re-enqueue; report happens outside.
            FailureKind::Bug => $this->manager->error($lease->key, $lease->token),
            // Infrastructure: free the key, the transport/client retries.
            FailureKind::Infrastructure => $this->manager->abort($lease->key, $lease->token),
        };
    }

    private function replay(AlreadyCompleted $completed): mixed
    {
        $decoded = $this->decode(\is_string($completed->result) ? $completed->result : null);

        if ($completed->success) {
            return $decoded;
        }

        // Cached domain failure — rethrow a deterministic snapshot of the original outcome.
        if (\is_array($decoded) && isset($decoded['class'], $decoded['message'])) {
            throw new CachedDomainFailureException((string) $decoded['class'], (string) $decoded['message']);
        }

        throw new IdempotencyException(\sprintf(
            'Cached domain failure for key "%s" is missing its snapshot.',
            $completed->key,
        ));
    }

    private function encode(mixed $value): ?string
    {
        // FireOnce / void result — no serialization.
        return $value === null ? null : (string) $this->serializer->serialize($value);
    }

    /**
     * Lightweight, always-serializable snapshot of a domain failure (class + message), avoiding the
     * fragility of serializing the throwable object itself (its trace may capture closures).
     */
    private function encodeFailure(\Throwable $e): string
    {
        return (string) $this->serializer->serialize([
            'class' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    private function decode(?string $blob): mixed
    {
        return $blob === null ? null : $this->serializer->unserialize($blob);
    }
}
