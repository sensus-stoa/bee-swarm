<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use BeeSwarm\Database;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // GUARD: prevent test leakage into production DB
        $dbPath = getenv('SWARM_DB_PATH');
        if (!$dbPath || !str_contains($dbPath, 'test')) {
            $this->markTestSkipped(
                'SWARM_DB_PATH must point to a test database. ' .
                'Run with phpunit.xml or set SWARM_DB_PATH=data/test_swarm.db'
            );
        }
        Database::get();
    }
}
