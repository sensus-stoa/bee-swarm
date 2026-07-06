<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Infra\Database;

/**
 * Story D9: KG population through accumulator
 *
 * @group disabled
 */
class ForagerKgTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure clean KG
        Database::get()->exec('DELETE FROM knowledge_graph');
    }

    /** Аккумулятор должен добавлять факты в KG через семантические стратегии */
    public function testAccumulatorPopulatesKg(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d9_kg_' . uniqid();
        mkdir($dir);

        // Файл с «X — это Y» паттерном для preg_match_is_a
        file_put_contents("$dir/sem.txt", "человек — это живое_существо\nкошка — это животное\n");

        $f->scanWithAccumulator([$dir => 1]);

        $count = Database::get()->query('SELECT COUNT(*) FROM knowledge_graph')->fetchColumn();
        $this->assertGreaterThan(0, (int)$count,
            'Accumulator must populate KG with semantic facts from preg_match_is_a');

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
