<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\StreamingAccumulator;
use BeeSwarm\Forager\SemanticFactInserter;

/**
 * FORAGER-ENV-PARAMS (09.08): параметризация аккумулятора для FIN-EXP-001.
 *
 * Три параметра вместо хардкода (анти-хардкод: параметр→env, НЕ константа):
 * - FORAGER_MAX_ROWS  (default 200) — лимит строк данных на паттерн.
 *   Для финансов: 13.5k строк на паттерн, 200 = 1.5% (смещение выборки).
 * - FORAGER_MAX_COLS  (default 4)   — число колонок, участвующих в задачах.
 *   Для финансов: future_ret20/vol20/контрольные признаки вне первых 4 = мимо.
 * - FORAGER_COMBO_CAP (default 50)  — лимит комбо X для арности (защита OOM).
 *   Лексикографический кап режет дальние колонки — при 15 колонках нужен больше.
 *
 * Дефолты сохраняют прежнее поведение (обратная совместимость).
 */
class ForagerEnvParamsTest extends TestCase
{
    private function csvStrategy(): array
    {
        return [
            'str_getcsv' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $r = str_getcsv($l);
                    if (count($r) >= 2) {
                        $rows[] = $r;
                    }
                }
                return array_values(array_filter($rows, fn ($r) => count(array_filter($r, 'is_numeric')) >= 2));
            },
        ];
    }

    /**
     * RED: CSV 250 строк → по умолчанию задача содержит ≤200 строк (LIMIT 200).
     */
    public function testMaxRowsDefaultCapsAt200(): void
    {
        $dir = sys_get_temp_dir() . '/fenv_rows_' . uniqid();
        mkdir($dir);
        $f = fopen($dir . '/data.csv', 'w');
        fputcsv($f, ['x0', 'x1']);
        for ($i = 1; $i <= 250; $i++) {
            fputcsv($f, [$i, $i * 2]);
        }
        fclose($f);

        $acc = new StreamingAccumulator($this->csvStrategy(), new SemanticFactInserter());
        $tasks = $acc->scan([$dir => 1]);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $this->assertNotEmpty($tasks, 'tasks must exist');
        foreach ($tasks as $t) {
            $this->assertLessThanOrEqual(
                200,
                count($t['data']),
                'default FORAGER_MAX_ROWS must cap task data at 200 rows'
            );
        }
    }

    /**
     * GREEN-цель: FORAGER_MAX_ROWS=500 → все 250 строк в задаче.
     */
    public function testMaxRowsEnvRaisesCap(): void
    {
        $dir = sys_get_temp_dir() . '/fenv_rows2_' . uniqid();
        mkdir($dir);
        $f = fopen($dir . '/data.csv', 'w');
        fputcsv($f, ['x0', 'x1']);
        for ($i = 1; $i <= 250; $i++) {
            fputcsv($f, [$i, $i * 2]);
        }
        fclose($f);

        putenv('FORAGER_MAX_ROWS=500');
        try {
            $acc = new StreamingAccumulator($this->csvStrategy(), new SemanticFactInserter());
            $tasks = $acc->scan([$dir => 1]);
        } finally {
            putenv('FORAGER_MAX_ROWS');
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $this->assertNotEmpty($tasks, 'tasks must exist');
        foreach ($tasks as $t) {
            $this->assertSame(
                250,
                count($t['data']),
                'FORAGER_MAX_ROWS=500 must keep all 250 rows'
            );
        }
    }

    /**
     * RED: 6-колоночный CSV → по умолчанию задачи только из первых 4 колонок.
     */
    public function testMaxColsDefaultFour(): void
    {
        $dir = sys_get_temp_dir() . '/fenv_cols_' . uniqid();
        mkdir($dir);
        $f = fopen($dir . '/wide.csv', 'w');
        fputcsv($f, ['a', 'b', 'c', 'd', 'e', 'f']);
        for ($i = 1; $i <= 20; $i++) {
            fputcsv($f, [$i, $i * 2, $i * 3, $i * 4, $i * 5, $i * 6]);
        }
        fclose($f);

        $acc = new StreamingAccumulator($this->csvStrategy(), new SemanticFactInserter());
        $tasks = $acc->scan([$dir => 1]);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $names = array_column($tasks, 'name');
        foreach ($names as $n) {
            $this->assertDoesNotMatchRegularExpression(
                '/_c[4-9]|c[4-9]c/',
                $n,
                "default FORAGER_MAX_COLS=4 must not create tasks with columns 4+ (task: {$n})"
            );
        }
        $this->assertNotEmpty($tasks, 'tasks must exist');
    }

    /**
     * GREEN-цель: FORAGER_MAX_COLS=6 → пара (col4,col5) попадает в задачи.
     */
    public function testMaxColsEnvIncludesFarColumns(): void
    {
        $dir = sys_get_temp_dir() . '/fenv_cols2_' . uniqid();
        mkdir($dir);
        $f = fopen($dir . '/wide.csv', 'w');
        fputcsv($f, ['a', 'b', 'c', 'd', 'e', 'f']);
        for ($i = 1; $i <= 20; $i++) {
            fputcsv($f, [$i, $i * 2, $i * 3, $i * 4, $i * 5, $i * 6]);
        }
        fclose($f);

        putenv('FORAGER_MAX_COLS=6');
        try {
            $acc = new StreamingAccumulator($this->csvStrategy(), new SemanticFactInserter());
            $tasks = $acc->scan([$dir => 1]);
        } finally {
            putenv('FORAGER_MAX_COLS');
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $names = implode(' ', array_column($tasks, 'name'));
        $this->assertStringContainsString('c4c5', $names, 'FORAGER_MAX_COLS=6 must create pair task (col4,col5)');
    }

    /**
     * RED: FORAGER_COMBO_CAP=3 при 6 колонках → X-комбо арности 2 только первые 3
     * ((0,1),(0,2),(0,3)); задача X=[c2,c3]→y НЕ должна существовать.
     */
    public function testComboCapLimitsArityCombos(): void
    {
        $dir = sys_get_temp_dir() . '/fenv_cap_' . uniqid();
        mkdir($dir);
        $f = fopen($dir . '/wide.csv', 'w');
        fputcsv($f, ['a', 'b', 'c', 'd', 'e', 'f']);
        for ($i = 1; $i <= 20; $i++) {
            fputcsv($f, [$i, $i * 2, $i * 3, $i * 4, $i * 5, $i * 6]);
        }
        fclose($f);

        putenv('FORAGER_COMBO_CAP=3');
        putenv('MAX_ARITY=2');
        try {
            $acc = new StreamingAccumulator($this->csvStrategy(), new SemanticFactInserter());
            $tasks = $acc->scan([$dir => 1]);
        } finally {
            putenv('FORAGER_COMBO_CAP');
            putenv('MAX_ARITY');
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $names = implode(' ', array_column($tasks, 'name'));
        // Арность-комбо (X→y) имеют суффикс '->cY'; all-pairs (без стрелки) капом НЕ режутся.
        // Первые 3 комбо из C(6,2) лексикографически: (0,1),(0,2),(0,3) → 'c0c1->', 'c0c2->', 'c0c3->'
        $this->assertStringContainsString('c0c1->', $names, 'first combo (c0,c1) must exist as arity task');
        $this->assertStringContainsString('c0c3->', $names, 'third combo (c0,c3) must exist as arity task');
        // 4-е комбо (0,4) и (1,2) — НЕ должны появиться при капе 3
        $this->assertStringNotContainsString('c0c4->', $names, 'cap=3 must cut combo (c0,c4)');
        $this->assertStringNotContainsString('c1c2->', $names, 'cap=3 must cut combo (c1,c2)');
    }
}
