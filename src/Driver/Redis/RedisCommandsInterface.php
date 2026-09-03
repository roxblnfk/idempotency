<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis;

/**
 * The three Redis commands the lease storage issues, decoupled from any client library.
 *
 * Bind an implementation in the container to run {@see RedisLeaseConfig} over the Redis client your
 * application already has (phpredis, Credis, ...). With no binding the factory falls back to a
 * `\Predis\ClientInterface` binding through {@see PredisCommands}.
 *
 * Implementations must surface a server-side script failure as an exception rather than a falsy
 * return: the storage reads `0` as a legitimate "CAS rejected" outcome.
 *
 * @api
 */
interface RedisCommandsInterface
{
    /**
     * EVAL with the given KEYS and ARGV; returns the script's reply (an int for the lease scripts).
     *
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed;

    /**
     * HGETALL; an empty array when the key is missing.
     *
     * @return array<string, string>
     */
    public function hgetall(string $key): array;

    /**
     * PTTL in milliseconds; -1 without an expiry, -2 for a missing key.
     */
    public function pttl(string $key): int;
}
