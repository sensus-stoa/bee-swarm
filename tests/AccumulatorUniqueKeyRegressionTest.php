<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Forager\StreamingAccumulator;
use BeeSwarm\Forager\SemanticFactInserter;

/**
 * V0.8.5 Regression: PRIMARY KEY(pattern, row_json) коллапсил
 * одинаковые значения из разных файлов → GROUP BY COUNT(*) < tMin
 * → foraged_txt_* задачи не создавались.
 *
 * Фикс: PRIMARY KEY(pattern, row_json, source_path).
 */
class AccumulatorUniqueKeyRegressionTest extends TestCase
{
    /**
     * 15 файлов с одинаковым значением [5] → до фикса GROUP BY давал 1,
     * после фикса должен дать 15 (≥ tMin=10).
     */
    public function testIdenticalValuesAcrossFilesAreNotCollapsed(): void
    {
        // Регистрируем текст-атом
        AtomRegistry::addDiscoveredTextAtom('preg_match', 'testword');

        // Создаём StreamingAccumulator с пустыми стратегиями
        $acc = new StreamingAccumulator([], new SemanticFactInserter());

        // 15 временных файлов с одинаковым контентом
        $tmpFiles = [];
        $tmpDir = sys_get_temp_dir() . '/bee_test_' . uniqid();
        mkdir($tmpDir);
        for ($i = 0; $i < 15; $i++) {
            $path = $tmpDir . "/file_{$i}.md";
            file_put_contents($path, "testword testword testword testword testword");
            $tmpFiles[] = $path;
        }

        $tasks = $acc->scan([$tmpDir => 1]);

        // Должна быть хотя бы одна foraged_txt_* задача с ≥10 строками
        $txtTasks = array_filter($tasks, fn ($t) => str_starts_with($t['name'] ?? '', 'foraged_txt_'));

        // Очистка
        foreach ($tmpFiles as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        $this->assertNotEmpty($txtTasks, '15 files with identical text atom values must produce foraged_txt_ task');
        $task = reset($txtTasks);
        $this->assertGreaterThanOrEqual(10, count($task['data']),
            'GROUP BY must count files, not unique values. Got ' . count($task['data']) . ' rows');
    }

    /**
     * Разные значения из разных файлов — все должны сохраниться.
     */
    public function testDifferentValuesAcrossFilesAreAllPreserved(): void
    {
        AtomRegistry::addDiscoveredTextAtom('preg_match', 'item');

        $acc = new StreamingAccumulator([], new SemanticFactInserter());

        $tmpDir = sys_get_temp_dir() . '/bee_test_' . uniqid();
        mkdir($tmpDir);
        $expected = [];
        for ($i = 0; $i < 12; $i++) {
            $count = $i + 1;
            $content = str_repeat("item ", $count);
            $path = $tmpDir . "/file_{$i}.md";
            file_put_contents($path, $content);
            $expected[] = (float) $count;
        }

        $tasks = $acc->scan([$tmpDir => 1]);

        foreach ($tmpDir ? glob("$tmpDir/*") : [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        $txtTasks = array_filter($tasks, fn ($t) => str_starts_with($t['name'] ?? '', 'foraged_txt_'));
        $this->assertNotEmpty($txtTasks);
        $task = reset($txtTasks);
        $this->assertCount(12, $task['data'], 'All 12 files must produce distinct rows');
    }

    /**
     * Меньше tMin файлов → задача НЕ создаётся.
     */
    public function testBelowTMinNoTaskCreated(): void
    {
        AtomRegistry::addDiscoveredTextAtom('preg_match', 'rareword');

        $acc = new StreamingAccumulator([], new SemanticFactInserter());

        $tmpDir = sys_get_temp_dir() . '/bee_test_' . uniqid();
        mkdir($tmpDir);
        for ($i = 0; $i < 5; $i++) {
            file_put_contents($tmpDir . "/file_{$i}.md", "rareword");
        }

        $tasks = $acc->scan([$tmpDir => 1]);

        foreach ($tmpDir ? glob("$tmpDir/*") : [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        $txtTasks = array_filter($tasks, fn ($t) => str_starts_with($t['name'] ?? '', 'foraged_txt_'));
        $this->assertEmpty($txtTasks, '5 files < tMin=10 must not create task');
    }

    /**
     * Один файл с несколькими разными значениями (numeric case) —
     * все значения должны сохраниться.
     */
    public function testMultipleNumericValuesPerFilePreserved(): void
    {
        AtomRegistry::addDiscoveredTextAtom('match_label', 'GI');

        $acc = new StreamingAccumulator([], new SemanticFactInserter());

        $tmpDir = sys_get_temp_dir() . '/bee_test_' . uniqid();
        mkdir($tmpDir);
        // 12 файлов с GI: от 1.0 до 12.0
        for ($i = 0; $i < 12; $i++) {
            file_put_contents($tmpDir . "/file_{$i}.md", "GI: " . ($i + 1.0));
        }

        $tasks = $acc->scan([$tmpDir => 1]);

        foreach ($tmpDir ? glob("$tmpDir/*") : [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        $txtTasks = array_filter($tasks, fn ($t) => str_starts_with($t['name'] ?? '', 'foraged_txt_'));
        $this->assertNotEmpty($txtTasks);
        $task = reset($txtTasks);
        // 12 разных значений из 12 файлов
        $this->assertGreaterThanOrEqual(10, count($task['data']),
            '12 numeric values across 12 files must be preserved');
    }
}
