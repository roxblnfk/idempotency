<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Common;

use Cycle\Schema\Generator\RenderModifiers;
use Cycle\Schema\Generator\RenderTables;
use Cycle\Schema\Generator\ValidateEntities;
use Cycle\Schema\Registry;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\CycleAtMostOnceConfig;
use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
use Spiral\Idempotency\Driver\Cycle\Internal\Schema\DefaultSchemaNaming;
use Spiral\Idempotency\Driver\Cycle\Internal\Schema\IdempotencyTablesGenerator;
use Spiral\Idempotency\Driver\Cycle\Schema\SchemaNamingInterface;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactoryInterface;
use Testo\Assert;
use Testo\Codecov\Covers;

/**
 * The ORM schema generator that materialises the idempotency tables as Cycle roles. Runs against the
 * driver matrix so column-type / index rendering is verified per dialect. These scenarios build
 * schema objects and never write rows, so no unique key is needed.
 */
#[Covers(IdempotencyTablesGenerator::class)]
abstract class CycleSchemaTestCase extends DatabaseTestCase
{
    public function registersOneRolePerCycleStorage(): void
    {
        $registry = $this->registry($this->config());

        Assert::true($registry->hasEntity('idempotency:payments'));
        Assert::true($registry->hasEntity('idempotency:orders'));

        Assert::same($registry->getTable($registry->getEntity('idempotency:payments')), 'lease_tbl');
        Assert::same($registry->getTable($registry->getEntity('idempotency:orders')), 'inbox_tbl');
    }

    public function leaseRoleCarriesAllColumns(): void
    {
        $fields = $this->registry($this->config())->getEntity('idempotency:payments')->getFields();

        foreach (['key', 'state', 'token', 'success', 'result', 'expire_time', 'create_time'] as $column) {
            Assert::true($fields->has($column), "missing column {$column}");
        }
        Assert::true($fields->get('key')->isPrimary());
    }

    public function inboxRoleCarriesItsColumns(): void
    {
        $fields = $this->registry($this->config())->getEntity('idempotency:orders')->getFields();

        foreach (['key', 'result', 'create_time'] as $column) {
            Assert::true($fields->has($column), "missing column {$column}");
        }
        Assert::true($fields->get('key')->isPrimary());
    }

    public function leaseTableRendersPrimaryKeyAndIndex(): void
    {
        $registry = $this->registry($this->config());
        $entity = $registry->getEntity('idempotency:payments');

        (new ValidateEntities())->run($registry);
        (new RenderTables())->run($registry);
        (new RenderModifiers())->run($registry);

        $table = $registry->getTableSchema($entity);

        Assert::same($table->getPrimaryKeys(), ['key']);
        Assert::true($table->hasColumn('expire_time'));
        Assert::true($table->hasIndex(['expire_time']));
    }

    public function registersAtMostOnceStorageAsRole(): void
    {
        $config = new IdempotencyConfig([
            'storages' => [
                'guards' => new CycleAtMostOnceConfig(table: 'at_most_once_tbl'),
            ],
        ]);

        $registry = $this->registry($config);

        Assert::true($registry->hasEntity('idempotency:guards'));

        $entity = $registry->getEntity('idempotency:guards');
        Assert::same($registry->getTable($entity), 'at_most_once_tbl');

        $fields = $entity->getFields();
        foreach (['key', 'result', 'create_time'] as $column) {
            Assert::true($fields->has($column), "missing column {$column}");
        }
        Assert::true($fields->get('key')->isPrimary());
    }

    public function nonCycleStorageIsIgnored(): void
    {
        $foreign = new class extends StorageConfig {
            public function guarantee(): Guarantee
            {
                return Guarantee::AtLeastOnce;
            }

            public function factory(): string
            {
                return StorageFactoryInterface::class;
            }
        };

        $config = new IdempotencyConfig([
            'storages' => [
                'payments' => new CycleLeaseConfig(table: 'lease_tbl'),
                'remote' => $foreign,
            ],
        ]);

        $registry = $this->registry($config);

        // The Cycle storage still becomes a role; the foreign one is skipped entirely.
        Assert::true($registry->hasEntity('idempotency:payments'));
        Assert::false($registry->hasEntity('idempotency:remote'));
    }

    public function customNamingIsApplied(): void
    {
        $naming = new class implements SchemaNamingInterface {
            public function role(string $alias, StorageConfig $config): string
            {
                return 'idem_' . $alias;
            }
        };

        $registry = $this->registry($this->config(), $naming);

        Assert::true($registry->hasEntity('idem_payments'));
        Assert::true($registry->hasEntity('idem_orders'));
    }

    private function registry(IdempotencyConfig $config, ?SchemaNamingInterface $naming = null): Registry
    {
        $registry = new Registry($this->manager());

        (new IdempotencyTablesGenerator($config, $naming ?? new DefaultSchemaNaming()))->run($registry);

        return $registry;
    }

    private function config(): IdempotencyConfig
    {
        return new IdempotencyConfig([
            'storages' => [
                'payments' => new CycleLeaseConfig(table: 'lease_tbl'),
                'orders' => new CycleInboxConfig(table: 'inbox_tbl'),
            ],
        ]);
    }
}
