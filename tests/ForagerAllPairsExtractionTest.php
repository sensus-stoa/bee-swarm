<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\SemanticFactInserter;
use BeeSwarm\Forager\StreamingAccumulator;

/**
 * Story E1-FIX Phase 4c: All-Pairs Column Extraction
 *
 * Вместо sliding windows (пропускают edge columns),
 * все пары из первых 4 колонок — как в Scanner::processNumericRows().
 */
class ForagerAllPairsExtractionTest extends TestCase
{
    /**
     * RED: Из 4 колонок → 6 задач (все пары C(4,2)).
     * Каждая задача: nFeat=1.
     *
     * Predicted: count mismatch — sliding windows дают ≤3 задачи, не 6.
     */
    public function testAllPairsFromFourColumns(): void
    {
        $strategies = [
            'explode_lines' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $parts = preg_split('/[\s,;]+/', trim($l));
                    $nums = array_filter($parts, 'is_numeric');
                    if (count($nums) >= 2) {
                        $rows[] = array_map('floatval', $nums);
                    }
                }
                return $rows;
            },
        ];
        $acc = new StreamingAccumulator($strategies, new SemanticFactInserter());
        $dir = sys_get_temp_dir() . '/allpairs_test_' . uniqid();
        mkdir($dir);

        // 4 колонки × 20 строк
        $content = '';
        for ($i = 0; $i < 20; $i++) {
            $content .= implode(' ', [
                $i + 1, ($i + 1) * 2, ($i + 1) * 3, ($i + 1) * 0.5,
            ]) . "\n";
        }
        file_put_contents("{$dir}/data.txt", $content);

        $tasks = $acc->scan([$dir => 1]);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        // C(4,2) = 6 пар
        $this->assertCount(6, $tasks, '4 columns must produce all 6 pairs');

        // Каждая задача должна иметь nFeat=1 (2 колонки: feature + target)
        foreach ($tasks as $task) {
            $this->assertCount(2, $task['data'][0], 'Each task must be a pair [feature, target]');
        }

        // Все пары должны быть уникальны по имени
        $names = array_column($tasks, 'name');
        $this->assertCount(6, array_unique($names), 'All pair names must be unique');
    }

    /**
     * RED: Edge columns (0 и 1) тоже становятся target'ами.
     * В sliding windows col0 и col1 никогда не были target.
     */
    public function testEdgeColumnsBecomeTargets(): void
    {
        $strategies = [
            'explode_lines' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $parts = preg_split('/[\s,;]+/', trim($l));
                    $nums = array_filter($parts, 'is_numeric');
                    if (count($nums) >= 2) {
                        $rows[] = array_map('floatval', $nums);
                    }
                }
                return $rows;
            },
        ];
        $acc = new StreamingAccumulator($strategies, new SemanticFactInserter());
        $dir = sys_get_temp_dir() . '/edge_test_' . uniqid();
        mkdir($dir);
        $content = "1 10 100 1000\n2 20 200 2000\n3 30 300 3000\n4 40 400 4000\n5 50 500 5000\n6 60 600 6000\n7 70 700 7000\n8 80 800 8000\n9 90 900 9000\n10 100 1000 10000\n";
        file_put_contents("{$dir}/edge.txt", $content);

        $tasks = $acc->scan([$dir => 1]);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        // Собираем все target-колонки (последний элемент в data[0])
        $targetColumns = [];
        foreach ($tasks as $task) {
            // target — последнее значение в первой строке
            $targetColumns[] = $task['data'][0][1]; // [feature, target]
        }

        // Колонки 1,2,3 должны быть target (c2 всегда > c1 → col0 никогда не target)
        // Это поведение идентично Scanner::processNumericRows()
        $this->assertContains(10.0, $targetColumns, 'Column 1 must be a target in some pair');
        $this->assertContains(100.0, $targetColumns, 'Column 2 must be a target in some pair');
        $this->assertContains(1000.0, $targetColumns, 'Column 3 must be a target in some pair');

        // col0 — только feature, не target (c2 > c1)
        $this->assertNotContains(1.0, $targetColumns, 'Column 0 is feature-only (c2 > c1)');
    }
}
