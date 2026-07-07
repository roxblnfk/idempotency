<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

use Spiral\Idempotency\Pipeline\FailureKind;

/**
 * Wraps the operation's throwable with the {@see FailureKind} a classifier middleware assigned to it,
 * carrying that decision out to the driver handler. The handler reads {@see $kind} to choose the
 * terminal transition (Domain → complete(success=false), Infrastructure → abort, Bug → error); the
 * original throwable is available via {@see \Throwable::getPrevious()}.
 *
 * Extends {@see \RuntimeException} rather than {@see IdempotencyException} on purpose: a user middleware
 * sitting between the classifier and the driver handler with a {@see IdempotencyException} catch-all must
 * not swallow this internal marker and lose the {@see FailureKind} it carries.
 *
 * @internal Channel between the classifier middleware and the driver handler; not part of the public API.
 */
final class ClassifiedException extends \RuntimeException
{
    public function __construct(
        public readonly FailureKind $kind,
        \Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
