<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\StreamingAccumulator;
use BeeSwarm\Forager\SemanticFactInserter;

/**
 * FORAGER-ARITY (09.08): параметрическая размерность задач — k = 1..MAX_ARITY
 * (env, default 3), C(n,k) комбинаций с капом. НЕ хардкод на каждую
 * размерность (анти-паттерн QUAD/L5): один параметр закрывает все арности.
 */
class ForagerArityTest extends TestCase
{
    public function testQuadTaskCreatedWithMaxArity3(): void
    {
        // Файл с 4 колонками + MAX_ARITY=3 → задачи с 3 фичами (C(4,3)=4)
        $dir = sys_get_temp_dir() . '/forager_arity_' . uniqid();
        mkdir($dir);
        $f = fopen($dir . '/quad.csv', 'w');
        fputcsv($f, ['x0', 'x1', 'x2', 'y']);
        for ($i = 1; $i <= 15; $i++) {
            fputcsv($f, [$i, $i * 2, $i * 3, ($i + $i * 2) * $i * 3]);
        }
        fclose($f);

        putenv('MAX_ARITY=3');
        try {
            $strategies = [
                'numeric' => function (string $content): array {
                    $rows = [];
                    $lines = explode("\n", trim($content));
                    array_shift($lines); // header
                    foreach ($lines as $l) {
                        $parts = preg_split('/[\s,;]+/', trim($l));
                        $nums = array_filter($parts, 'is_numeric');
                        if (count($nums) >= 2) {
                            $rows[] = array_map('floatval', array_values($nums));
                        }
                    }
                    return $rows;
                },
            ];
            $acc = new StreamingAccumulator($strategies, new SemanticFactInserter());
            $tasks = $acc->scan([$dir => 1]);
        } finally {
            putenv('MAX_ARITY');
        }

        // Ищем задачу с 3 фичами: X=[x0,x1,x2] → y
        $found = false;
        foreach ($tasks as $t) {
            $data = $t['data'] ?? [];
            $nFeat = count($data[0] ?? []) - 1;
            if ($nFeat === 3) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'MAX_ARITY=3 must create 3-feature tasks');
    }

    public function testArityCapped(): void
    {
        // MAX_ARITY=2: НЕ создаёт 3-фичевые задачи (пары+тройки = 2 фичи)
        $dir = sys_get_temp_dir() . '/forager_arity2_' . uniqid();
        mkdir($dir);
        $f = fopen($dir . '/quad.csv', 'w');
        fputcsv($f, ['x0', 'x1', 'x2', 'y']);
        for ($i = 1; $i <= 15; $i++) {
            fputcsv($f, [$i, $i * 2, $i * 3, $i + $i * 2 + $i * 3]);
        }
        fclose($f);

        putenv('MAX_ARITY=2');
        try {
            $strategies = [
                'numeric' => function (string $content): array {
                    $rows = [];
                    $lines = explode("\n", trim($content));
                    array_shift($lines); // header
                    foreach ($lines as $l) {
                        $parts = preg_split('/[\s,;]+/', trim($l));
                        $nums = array_filter($parts, 'is_numeric');
                        if (count($nums) >= 2) {
                            $rows[] = array_map('floatval', array_values($nums));
                        }
                    }
                    return $rows;
                },
            ];
            $acc = new StreamingAccumulator($strategies, new SemanticFactInserter());
            $tasks = $acc->scan([$dir => 1]);
        } finally {
            putenv('MAX_ARITY');
        }

        foreach ($tasks as $t) {
            $data = $t['data'] ?? [];
            $nFeat = count($data[0] ?? []) - 1;
            $this->assertLessThanOrEqual(2, $nFeat,
                'MAX_ARITY=2 must not create 3-feature tasks');
        }
        $this->assertNotEmpty($tasks, 'tasks must exist');
    }
}
