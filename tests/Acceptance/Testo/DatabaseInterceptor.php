<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Testo;

use Psr\Log\LoggerInterface;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\CaseResult;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Exception\SkipTest;
use Testo\Common\Messenger;
use Testo\Core\Value\Status;
use Testo\Core\Value\TestType;
use Testo\Filter\Group;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestCaseRunInterceptor;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Runs each driver-bound test case against the connection its `#[Group('driver-<x>')]` selects.
 *
 *  - per case: resolve the driver, connect, create the tables once, expose the connection via {@see Db};
 *  - per test: skip (not fail) if that database was unreachable.
 *
 * Unlike the Cycle ActiveRecord harness there is NO per-test transaction wrapping: the inbox driver
 * runs in {@see \Cycle\Transaction\TransactionMode::Exclusive} (throws under an open outer transaction)
 * and the dedup tests assert a real top-level COMMIT. Isolation is by unique key per test instead.
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_CLOSE_TO_TEST, testType: TestType::Test)]
final readonly class DatabaseInterceptor implements TestCaseRunInterceptor, TestRunInterceptor
{
    private LoggerInterface $logger;

    public function __construct(
        private ConnectionPool $pool,
        Messenger $messenger,
    ) {
        $this->logger = $messenger->channel('Query.sql');
    }

    #[\Override]
    public function runTestCase(CaseInfo $info, callable $next): CaseResult
    {
        $driver = self::resolveDriver($info->definition->reflection);

        // Not a driver-bound case — leave it untouched.
        if ($driver === null) {
            return $next($info);
        }

        $manager = $this->pool->manager($driver);

        try {
            $manager->database('default')->getDriver()->connect();
        } catch (\Throwable) {
            // Database down: don't bind a connection, so every test of this case reports as skipped.
            return $next($info);
        }

        // Forward every SQL statement to the Testo Messenger so a failing test shows the SQL it ran.
        $manager->setLogger($this->logger);

        if (!$this->pool->isPrepared($driver)) {
            Schema::prepare($manager->database('default'));
            $this->pool->markPrepared($driver);
        }

        Db::use($manager);
        try {
            return $next($info);
        } finally {
            Db::reset();
        }
    }

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $driver = self::resolveDriver($info->caseInfo->definition->reflection);

        if ($driver === null) {
            return $next($info);
        }

        // The case scope never bound a connection → the database is unavailable.
        if (!Db::isBound()) {
            return new TestResult(
                info: $info,
                status: Status::Skipped,
                failure: new SkipTest(\sprintf('Database `%s` is not available.', $driver->value)),
            );
        }

        return $next($info);
    }

    private static function resolveDriver(?\ReflectionClass $class): ?DatabaseDriver
    {
        for ($current = $class; $current !== null && $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getAttributes(Group::class) as $attribute) {
                foreach ($attribute->newInstance()->names as $name) {
                    $driver = DatabaseDriver::fromGroup($name);
                    if ($driver !== null) {
                        return $driver;
                    }
                }
            }
        }

        return null;
    }
}
