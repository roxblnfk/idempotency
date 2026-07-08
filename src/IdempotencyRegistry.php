<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

use Spiral\Idempotency\Exception\MisconfigurationException;

/**
 * Resolves a semantic storage alias (from the {@see Attribute\Idempotent} attribute / config) to a
 * concrete {@see IdempotencyInterface} driver.
 *
 * At registration it verifies — fail-fast — that the driver can actually back the *declared*
 * guarantee. This is the checkable part: not "will the handler reach the guarantee" (not
 * statically provable), but "does the alias lie about the driver's capability".
 *
 * @api
 */
final class IdempotencyRegistry
{
    /** @var array<non-empty-string, IdempotencyInterface> */
    private array $drivers = [];

    public function register(string $alias, IdempotencyInterface $driver, Guarantee $declared): void
    {
        if ($driver instanceof GuaranteeProviderInterface && !$driver->guarantee()->satisfies($declared)) {
            throw new MisconfigurationException(
                \sprintf(
                    'Storage alias "%s" declares guarantee %s, but driver %s can only provide %s.',
                    $alias,
                    $declared->name,
                    $driver::class,
                    $driver->guarantee()->name,
                ),
                'Lower the declared guarantee of the alias to the driver\'s capability '
                . '(e.g. `Guarantee::AtLeastOnce` for a lease driver), or back the alias with a driver '
                . 'that can provide it (the inbox driver for ExactlyOnce).',
            );
        }

        /** @var non-empty-string $alias */
        $this->drivers[$alias] = $driver;
    }

    public function get(string $alias): IdempotencyInterface
    {
        return $this->drivers[$alias] ?? throw new MisconfigurationException(
            \sprintf('No idempotency storage registered under alias "%s".', $alias),
            \sprintf(
                'Register the "%s" alias under `storages` in `config/idempotency.php`, or fix the '
                . '`storage:` argument of the #[Idempotent] attribute.',
                $alias,
            ),
        );
    }

    public function has(string $alias): bool
    {
        return isset($this->drivers[$alias]);
    }
}
