<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\SemanticFactInserter;
use BeeSwarm\Forager\StreamingAccumulator;
use BeeSwarm\Infra\Database;

/**
 * Story E1-FIX Phase 4: Forager Narrow Extraction
 *
 * Сейчас: все числовые колонки → одна задача (nFeat=249 → tMin=1245 → фильтр)
 * Будет: колонки разбиваются на пары → каждая задача проходит tMin.
 */
class ForagerNarrowExtractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * RED: Широкий CSV (6 колонок, много строк) → narrow-задачи с ≤3 фич.
     *
     * Predicted: TypeError / assertion failure — задачи имеют >3 features.
     */
    public function testWideCsvProducesNarrowTasks(): void
    {
        $strategies = [
            'str_getcsv' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $r = str_getcsv($l);
                    if (count($r) >= 2) {
                        $rows[] = $r;
                    }
                }
                $numRows = array_filter($rows, fn ($r) => count(array_filter($r, 'is_numeric')) >= 2);
                return array_values($numRows);
            },
        ];
        $factInserter = new SemanticFactInserter();
        $acc = new StreamingAccumulator($strategies, $factInserter);

        // Симулируем metrics.jsonl: 6 числовых колонок, 20 строк
        $dir = sys_get_temp_dir() . '/narrow_test_' . uniqid();
        mkdir($dir);
        $csv = '';
        for ($i = 0; $i < 20; $i++) {
            $csv .= implode(',', [
                $i + 1,           // col0
                ($i + 1) * 2,     // col1
                ($i + 1) * 3,     // col2
                ($i + 1) * 0.5,   // col3
                sin($i),          // col4
                cos($i),          // col5
            ]) . "\n";
        }
        file_put_contents("{$dir}/metrics.csv", $csv);

        $tasks = $acc->scan([$dir => 1]);

        // Очистка
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $this->assertNotEmpty($tasks, 'Must produce tasks from 20-row CSV');

        // Каждая задача должна иметь ≤3 features (nFeat ≤3 → tMin ≤ max(10, 3×5)=15)
        foreach ($tasks as $task) {
            $data = $task['data'];
            $this->assertNotEmpty($data, 'Task must have data');

            $nFeat = count($data[0]) - 1;  // last column is target
            $this->assertLessThanOrEqual(
                3,
                $nFeat,
                "Task {$task['name']} has {$nFeat} features, expected ≤3 for narrow extraction"
            );
        }

        // Должно быть больше одной задачи (пары колонок)
        $this->assertGreaterThan(1, count($tasks), 'Wide CSV must produce multiple narrow tasks');
    }

    /**
     * RED: Узкий файл (2 колонки) → без изменений, одна задача.
     */
    public function testNarrowCsvStaysUnchanged(): void
    {
        $strategies = [
            'str_getcsv' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $r = str_getcsv($l);
                    if (count($r) >= 2) {
                        $rows[] = $r;
                    }
                }
                $numRows = array_filter($rows, fn ($r) => count(array_filter($r, 'is_numeric')) >= 2);
                return array_values($numRows);
            },
        ];
        $factInserter = new SemanticFactInserter();
        $acc = new StreamingAccumulator($strategies, $factInserter);

        $dir = sys_get_temp_dir() . '/narrow_test2_' . uniqid();
        mkdir($dir);
        $csv = "1,10\n2,20\n3,30\n4,40\n5,50\n6,60\n7,70\n8,80\n9,90\n10,100\n";
        file_put_contents("{$dir}/narrow.csv", $csv);

        $tasks = $acc->scan([$dir => 1]);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $this->assertCount(1, $tasks, '2-column CSV must produce exactly 1 task');
        // Для 2-колоночного CSV: data[0] = [feature, target] → 2 элемента
        $this->assertCount(2, $tasks[0]['data'][0], 'Must be [feature, target] pairs');
    }
}
