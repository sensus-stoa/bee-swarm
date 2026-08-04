<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // GUARD: prevent test leakage into production DB
        // :memory: — in-memory test DB (S1.10); test-пути — файловые тестовые БД
        $dbPath = getenv('SWARM_DB_PATH');
        if ($dbPath !== ':memory:' && (! $dbPath || ! str_contains($dbPath, 'test'))) {
            $this->markTestSkipped(
                'SWARM_DB_PATH must point to a test database (:memory: or test path). ' .
                'Run with phpunit.xml or set SWARM_DB_PATH=:memory:'
            );
        }
        Database::get();

        // Use test fixtures for forager instead of scanning home directory
        if (! getenv('FORAGER_SOURCES')) {
            $fixturesDir = __DIR__ . '/fixtures/forager';
            if (is_dir($fixturesDir)) {
                putenv('FORAGER_SOURCES=' . $fixturesDir);
            }
        }
    }

    /**
     * ARCH-V2: Assert no RNG poisoning leaked from this test.
     *
     * §0.5: srand(42) in one test poisons array_rand() for ALL subsequent
     * tests in the same process. This tearDown catches the leak immediately
     * on the guilty test — so you know exactly which test caused it.
     *
     * If this assertion fires:
     *   1. The test whose tearDown() fails is the LEAKER.
     *   2. Look for srand() without restore() in the code that test exercises.
     *   3. Use RngIsolation::deterministicSeed(N) + ->restore() pattern.
     *
     * Tests that intentionally manage RNG state (like RngIsolationTest itself)
     * should clean up in their own tearDown before this assertion runs.
     */
    protected function tearDown(): void
    {
        \BeeSwarm\Infra\RngIsolation::assertClean();
        parent::tearDown();
    }
}
