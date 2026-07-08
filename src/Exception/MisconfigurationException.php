<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

use Yiisoft\FriendlyException\FriendlyExceptionInterface;

/**
 * Thrown when the idempotency wiring is misconfigured — e.g. a storage alias declares
 * `guarantee: ExactlyOnce` over a driver without transactional capability, a transport has no
 * configured middleware list, or an explicit `#[Idempotent]` key-path resolves to nothing. Fail-fast:
 * surfaced at bootstrap or on the first affected call, never silently altering dedup behaviour.
 *
 * Friendly ({@see FriendlyExceptionInterface}): throw sites pass an actionable
 * {@see self::getSolution()} that error handlers (e.g. the yiisoft error-handler bridge) render on
 * the error screen next to the message.
 *
 * @api
 */
final class MisconfigurationException extends IdempotencyException implements FriendlyExceptionInterface
{
    public function __construct(
        string $message,
        private readonly ?string $solution = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getName(): string
    {
        return 'Idempotency misconfiguration';
    }

    public function getSolution(): ?string
    {
        return $this->solution;
    }
}
