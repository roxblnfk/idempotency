<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * Thrown when a terminal/renew mutation is rejected by the storage CAS because the caller is no
 * longer the owner of the lease (its lease expired and the key was re-acquired).
 *
 * If the side-effect has already happened when this is raised, it is a signal that the lock TTL is
 * too small.
 *
 * @api
 */
final class LeaseLostException extends IdempotencyException
{
}
