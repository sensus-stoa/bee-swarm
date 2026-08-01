<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Story D2: Hive class (agenda.php → OOP)
 */
class HiveTest extends TestCase
{
    /**
     * Hive можно создать без аргументов (использует defaults)
     */
    public function testHiveConstructsWithDefaults(): void
    {
        $hive = new Hive();
        $this->assertInstanceOf(Hive::class, $hive);
    }

    /**
     * Hive принимает внешние зависимости
     */
    public function testHiveConstructsWithDependencies(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        $hive = new Hive($plateau, $forager);
        $this->assertInstanceOf(Hive::class, $hive);
    }

    /**
     * tick() возвращает количество задач
     */
    public function testTickReturnsTaskCount(): void
    {
        $hive = new Hive(maxTicks: 1);
        $status = $hive->tick();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('tasks_processed', $status);
        $this->assertGreaterThan(0, $status['tasks_processed']);
    }

    /**
     * run() с maxTicks=1 выполняет ровно 1 тик
     */
    public function testRunWithMaxTicks(): void
    {
        $hive = new Hive(maxTicks: 1);
        $totalTicks = $hive->run();
        $this->assertSame(1, $totalTicks);
    }

    /**
     * S1.11: laws-таблица содержит колонки source_path и content_sample
     */
    public function testLawsTableHasSourceColumns(): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        $cols = $db->query('PRAGMA table_info(laws)')->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $this->assertContains('source_path', $names, 'laws table must have source_path column');
        $this->assertContains('content_sample', $names, 'laws table must have content_sample column');
    }

    /**
     * S1.11 Phase 2: lawsWithSources возвращает законы с source-метаданными
     */
    public function testLawsWithSourcesReturnsSourceMetadata(): void
    {
        $hive = new Hive(maxTicks: 0);
        $hive->run();

        // Вставить тестовый закон с source-данными
        $db = \BeeSwarm\Infra\Database::get();
        $db->prepare('INSERT OR IGNORE INTO laws (name,formula,cv,domain,source_path,content_sample) VALUES (?,?,?,?,?,?)')
            ->execute(['test-law', '(x0+x1)', 0.01, 'test', '/tmp/test.csv', 'col1,col2\n1,2\n3,4']);

        $laws = $hive->lawsWithSources('test');
        $this->assertGreaterThan(0, count($laws), 'Must return laws for test domain');
        $this->assertArrayHasKey('source_path', $laws[0], 'Law must have source_path');
        $this->assertArrayHasKey('content_sample', $laws[0], 'Law must have content_sample');
        $this->assertSame('/tmp/test.csv', $laws[0]['source_path']);
    }

    /**
     * S1.11 Phase 2: старые законы без source — source_path пустая строка
     */
    public function testLawsWithoutSourceReturnEmptyString(): void
    {
        $hive = new Hive(maxTicks: 0);
        $hive->run();

        // Вставить закон БЕЗ source-данных (как старые записи)
        $db = \BeeSwarm\Infra\Database::get();
        $db->prepare('INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)')
            ->execute(['old-law', '(x0-x1)', 0.05, 'test_legacy']);

        $laws = $hive->lawsWithSources('test_legacy');
        $this->assertCount(1, $laws, 'Must return the old law');
        $this->assertSame('', $laws[0]['source_path'] ?? '', 'Old law source_path must be empty string');
    }
}
