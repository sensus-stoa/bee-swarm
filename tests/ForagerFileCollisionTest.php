<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * FORAGER-TABLE-BOUNDARIES / FILE-COLLISION (05.08, CsPbBr3/plaws):
 * паттерн = стратегия + число колонок → файлы с одинаковым числом колонок
 * СМЕШИВАЮТСЯ (little.csv и amdahl.csv: оба 3 колонки → одна задача rows=93).
 * Фикс: имена колонок (guessLabels) в паттерне.
 */
class ForagerFileCollisionTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/fcoll_' . uniqid();
        mkdir($this->tmpDir);

        // Файл A: 3 колонки (lambda, W, L) — 12 строк (tMin=10)
        $little = "lambda,W_hours,L_tasks\n";
        for ($i = 1; $i <= 12; $i++) {
            $little .= "{$i}," . ($i + 1) . "," . ($i * ($i + 1)) . "\n";
        }
        file_put_contents("{$this->tmpDir}/little.csv", $little);
        // Файл B: 3 колонки (p, n, speedup) — ДРУГИЕ имена, 12 строк
        $amdahl = "p,n_cores,speedup\n";
        $ps = [0.5, 0.75, 0.9, 0.95, 0.99];
        $ns = [2, 4, 8, 16, 32, 64];
        for ($i = 0; $i < 12; $i++) {
            $p = $ps[$i % 5];
            $n = $ns[$i % 6];
            $amdahl .= "{$p},{$n}," . round(1 / (1 - $p + $p / $n), 4) . "\n";
        }
        file_put_contents("{$this->tmpDir}/amdahl.csv", $amdahl);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            foreach (glob("{$this->tmpDir}/*") as $f) {
                unlink($f);
            }
            rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testFilesWithSameColumnCountDoNotMix(): void
    {
        $forager = new Forager();
        $tasks = $forager->scanWithAccumulator([$this->tmpDir => 1]);

        // Каждая задача должна содержать строки ТОЛЬКО из одного файла
        $this->assertNotEmpty($tasks, 'tasks must be created');
        foreach ($tasks as $task) {
            $src = basename($task['source_path'] ?? '?');
            $this->assertContains(
                $src,
                ['little.csv', 'amdahl.csv'],
                'source must be one of the files'
            );
        }

        // Должны быть задачи и из little, и из amdahl
        $srcs = array_unique(array_map(
            fn (array $t): string => basename($t['source_path'] ?? '?'),
            $tasks
        ));
        $this->assertContains('little.csv', $srcs, 'little.csv must produce tasks');
        $this->assertContains('amdahl.csv', $srcs, 'amdahl.csv must produce tasks');
    }
}
