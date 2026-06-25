<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\ORM\EntityManagerInterface;
use Spiral\Idempotency\IdempotencyContext;

/**
 * Context passed to the operation by a Cycle-backed driver that owns a transaction (the inbox).
 *
 * The side-effect MUST be written through this context so it commits atomically with the dedup
 * record — either as ORM entities via {@see self::entityManager()} (a scoped Unit of Work flushed
 * inside the transaction) or as raw DBAL through {@see self::database()}. In an operation closure,
 * narrow to this contract to reach them:
 *
 *     function (IdempotencyContext $ctx) {
 *         \assert($ctx instanceof CycleContext);
 *         $ctx->entityManager()->persist($order);   // or $ctx->database()->insert(...)->run();
 *     }
 *
 * @api
 */
interface CycleContext extends IdempotencyContext
{
    /**
     * Connection bound to the current transaction.
     */
    public function database(): DatabaseInterface;

    /**
     * Scoped Entity Manager (Unit of Work) bound to the current transaction; flushed before commit.
     */
    public function entityManager(): EntityManagerInterface;
}
