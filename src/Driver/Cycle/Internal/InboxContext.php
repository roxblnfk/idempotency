<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Cycle\Database\DatabaseInterface;
use Cycle\ORM\EntityManagerInterface;
use Spiral\Idempotency\Driver\Cycle\CycleContext;

/**
 * Context handed to the operation closure by the inbox (ExactlyOnce) driver. Carries the transactional
 * connection and the scoped Entity Manager (both bound to the inbox transaction); the side-effect MUST
 * be written through one of them so it commits atomically with the inbox dedup record.
 *
 * @internal Created by the driver; users only ever see the {@see CycleContext} contract.
 */
final readonly class InboxContext implements CycleContext
{
    /**
     * @param non-empty-string $key idempotency key of the current operation
     */
    public function __construct(
        private string $key,
        private DatabaseInterface $database,
        private EntityManagerInterface $entityManager,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function database(): DatabaseInterface
    {
        return $this->database;
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
