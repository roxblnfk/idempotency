<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Spiral\Idempotency\Exception\CachedDomainFailureException;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProviderInterface;
use Spiral\Idempotency\IdempotencyInterface;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Lease\LeaseManagerInterface;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\FailureClassifierInterface;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * Batteries-included AtLeastOnce driver: wraps the operation in lease + CAS via a
 * {@see LeaseManagerInterface}, serializes the result through a {@see SerializerInterface}, and
 * classifies failures with a {@see FailureClassifierInterface}.
 *
 * This bundles the terminal/classify behaviour that the framework integration would otherwise split
 * across middlewares; the decomposition into separate interceptors is the transport
 * layer's concern and reuses the very same primitives.
 *
 * @internal Built per storage alias by the bootloader; consumers resolve {@see IdempotencyInterface}
 *           from the {@see \Spiral\Idempotency\IdempotencyRegistry}. Not part of the public API.
 */
final class LeaseIdempotency implements IdempotencyInterface, GuaranteeProviderInterface
{
    private readonly SerializerInterface $serializer;
    private readonly FailureClassifierInterface $classifier;

    /**
     * @param int<1, max> $lockTtl PROCESSING lock TTL, seconds (short)
     * @param int<1, max> $retentionTtl COMPLETED retention TTL, seconds (long)
     * @param positive-int $acquireRetryLimit bound on AcquireRetry loops
     */
    public function __construct(
        private readonly LeaseManagerInterface $manager,
        private readonly int $lockTtl = 30,
        private readonly int $retentionTtl = 86400,
        ?SerializerInterface $serializer = null,
        ?FailureClassifierInterface $classifier = null,
        private readonly int $acquireRetryLimit = 3,
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
        $this->classifier = $classifier ?? new DefaultFailureClassifier();
    }

    public function guarantee(): Guarantee
    {
        return Guarantee::AtLeastOnce;
    }

    public function execute(string $key, \Closure $operation): mixed
    {
        $attempts = 0;
        do {
            $result = $this->manager->acquire($key, $this->lockTtl);

            if ($result instanceof Acquired) {
                return $this->run($result, $operation);
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

    private function run(Acquired $lease, \Closure $operation): mixed
    {
        $context = new LeaseContext($lease->key);

        try {
            $value = $operation($context);
        } catch (\Throwable $e) {
            $this->terminateFailure($lease, $e);
            throw $e;
        }

        $this->manager->complete($lease->key, $lease->token, true, $this->encode($value), $this->retentionTtl);

        return $value;
    }

    private function terminateFailure(Acquired $lease, \Throwable $e): void
    {
        match ($this->classifier->classify($e)) {
            // Domain: a valid (negative) outcome — cache a lightweight snapshot for idempotent replay.
            FailureKind::Domain => $this->manager->complete(
                $lease->key,
                $lease->token,
                false,
                $this->encodeFailure($e),
                $this->retentionTtl,
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
