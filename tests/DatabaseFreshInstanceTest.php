<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

/**
 * EXP-061 infra: fresh :memory: DB per test.
 *
 * Owner principle (11.11): "для каждого теста чистую базу поднимать... то
 * что меняется должно быть свежее". The house TestCase deletes only the
 * KNOWN tables (the 6-table list) — any test's own CREATE TABLE survives
 * into the next test of the same paratest worker (the shared
 * Database::get() singleton). Database::reset() in setUp = the full fresh
 * :memory: (a brand-new PDO = an empty schema, the DDL re-applied on get).
 *
 * The execution-order contract: phpunit runs the test methods of a file in
 * the declaration order — testWritesProbeTable runs FIRST, the fresh-check
 * SECOND, in the SAME worker process (the shared static).
 */
final class DatabaseFreshInstanceTest extends TestCase
{
    public function testWritesProbeTable(): void
    {
        Database::get()->exec('CREATE TABLE db_fresh_probe (id INTEGER)');
        Database::get()->exec('INSERT INTO db_fresh_probe VALUES (1)');
        $n = (int) Database::get()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE name='db_fresh_probe'")
            ->fetchColumn();
        $this->assertSame(1, $n, 'sanity: the probe table exists in THIS test');
        $this->assertSame(1, (int) Database::get()
            ->query('SELECT COUNT(*) FROM db_fresh_probe')->fetchColumn(), 'the probe row landed');
    }

    public function testProbeTableGoneInFreshDb(): void
    {
        // Runs in the SAME worker process after testWritesProbeTable.
        // Desired: the DB = fresh per test -> the probe table = gone.
        $n = (int) Database::get()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE name='db_fresh_probe'")
            ->fetchColumn();
        $this->assertSame(
            0,
            $n,
            'DB state from the previous test method SURVIVED: Database::reset() must run per test '
            . '(a fresh :memory: instance per test, the known-table DELETE list is not enough)'
        );
    }
}
