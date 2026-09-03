<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis;

/**
 * {@see RedisCommands} over a predis client. Requires `predis/predis`.
 *
 * @api
 */
final readonly class PredisCommands implements RedisCommands
{
    public function __construct(
        private \Predis\ClientInterface $client,
    ) {}

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->client->eval($script, \count($keys), ...$keys, ...$args);
    }

    public function hgetall(string $key): array
    {
        /** @var array<string, string> */
        return $this->client->hgetall($key);
    }

    public function pttl(string $key): int
    {
        return (int) $this->client->pttl($key);
    }
}
