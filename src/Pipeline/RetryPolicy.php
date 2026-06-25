<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * Read by the retry middleware, NOT by the core. The core knows nothing about
 * backoff and does not store the attempt counter (the queue counts attempts for Jobs; the client
 * for HTTP). Composition mirrors Temporal.
 *
 * @api
 */
final class RetryPolicy
{
    /**
     * @param int<0, max> $maximumAttempts 0 = unlimited (the real ceiling is the transport's)
     * @param int<0, max> $initialIntervalMs
     * @param float $backoffCoefficient
     * @param int<0, max> $maximumIntervalMs
     * @param list<class-string<\Throwable>> $nonRetryableExceptions
     */
    public function __construct(
        public readonly int $maximumAttempts = 0,
        public readonly int $initialIntervalMs = 1000,
        public readonly float $backoffCoefficient = 2.0,
        public readonly int $maximumIntervalMs = 100_000,
        public readonly array $nonRetryableExceptions = [],
    ) {}

    /**
     * Backoff delay (ms) before the given 1-based attempt, capped at the maximum interval.
     *
     * @param int<1, max> $attempt
     */
    public function delayFor(int $attempt): int
    {
        $delay = (float) $this->initialIntervalMs * \pow($this->backoffCoefficient, $attempt - 1);

        return (int) \min($delay, (float) $this->maximumIntervalMs);
    }

    public function isRetryable(\Throwable $e): bool
    {
        foreach ($this->nonRetryableExceptions as $nonRetryable) {
            if ($e instanceof $nonRetryable) {
                return false;
            }
        }

        return true;
    }
}
