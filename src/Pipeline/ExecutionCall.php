<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\IdempotencyContext;

/**
 * Context of the execution (inner) pipeline — the domain phase that runs the operation under the
 * guarantee (classify / retry / renewal → operation). Carries the driver-built
 * {@see IdempotencyContext} (resolved key + transactional connection), the business operation and the
 * per-call options.
 *
 * Sibling of {@see IdempotencyCall} (the resolution pipeline's context), but deliberately WITHOUT the
 * transport object: the inner pipeline is transport-agnostic by type, not by discipline.
 *
 * @api
 */
final readonly class ExecutionCall
{
    /**
     * @param \Closure(IdempotencyContext): mixed $operation the business function, invoked at the terminal
     */
    public function __construct(
        public IdempotencyContext $context,
        public \Closure $operation,
        public ExecuteOptions $options,
    ) {}
}
