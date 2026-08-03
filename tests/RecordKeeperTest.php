<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RecordKeeper;
use BeeSwarm\Infra\Database;

class RecordKeeperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get();
    }

    public function testRecordSavesLawClassIdentity(): void
    {
        $keeper = new RecordKeeper();
        $keeper->preloadKnown();

        $result = $keeper->record(
            ['atom' => '(x0 / x1)', 'cv' => 0.001, 'mode' => 'search', 'class' => 'IDENTITY'],
            ['name' => 'ratio_identity', 'content' => '', 'col_labels' => []],
            'arithmetic'
        );

        $this->assertTrue($result['inserted']);

        $row = Database::get()->query(
            "SELECT law_class FROM laws WHERE name='ratio_identity'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertEquals('IDENTITY', $row['law_class']);
    }

    public function testRecordDefaultsToEmpirical(): void
    {
        $keeper = new RecordKeeper();
        $keeper->preloadKnown();

        $result = $keeper->record(
            ['atom' => '(x0 + x1)', 'cv' => 0.05, 'mode' => 'search'],
            ['name' => 'sum_empirical', 'content' => '', 'col_labels' => []],
            'arithmetic'
        );

        $this->assertTrue($result['inserted']);

        $row = Database::get()->query(
            "SELECT law_class FROM laws WHERE name='sum_empirical'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertEquals('EMPIRICAL', $row['law_class']);
    }

    public function testRecordDedupPreservesClass(): void
    {
        $keeper = new RecordKeeper();
        $keeper->preloadKnown();

        $keeper->record(
            ['atom' => '(x0 * x1)', 'cv' => 0.0, 'mode' => 'search', 'class' => 'IDENTITY'],
            ['name' => 'dedup_test', 'content' => '', 'col_labels' => []],
            'arithmetic'
        );

        $result2 = $keeper->record(
            ['atom' => '(x0 * x1)', 'cv' => 0.0, 'mode' => 'compose', 'class' => 'IDENTITY'],
            ['name' => 'dedup_test', 'content' => '', 'col_labels' => []],
            'arithmetic'
        );

        $this->assertFalse($result2['inserted']);
    }

    public function testMigrationAddsLawClassColumn(): void
    {
        $db = Database::get();

        $db->exec("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES ('migration_test', 'x0', 0.1, 'test')");

        $row = $db->query(
            "SELECT law_class FROM laws WHERE name='migration_test'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertEquals('EMPIRICAL', $row['law_class']);
    }
}
