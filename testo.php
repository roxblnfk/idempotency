<?php

declare(strict_types=1);

use Spiral\Idempotency\Tests\Acceptance\Testo\DatabasePlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        // Driver-agnostic tests; no database connection required.
        new SuiteConfig(name: 'Unit', location: ['tests/Unit']),

        // The same scenarios against the SQL driver matrix (SQLite / Postgres / MySQL). Only the
        // concrete #[Group('driver-<x>')] subclasses under Driver/ are discovered; the abstract
        // scenarios in Common/ and the harness in Testo/ are not test cases.
        new SuiteConfig(
            name: 'Acceptance',
            location: ['tests/Acceptance/Driver'],
            plugins: [new DatabasePlugin()],
        ),
    ],
);
