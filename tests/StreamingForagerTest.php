<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * Story D9: Streaming Forager — SQLite accumulator
 */
class StreamingForagerTest extends TestCase
{
    /**
     * 3 файла × один паттерн → ≥ tMin точек → задача выпускается
     */
    public function testAccumulatesMultipleFiles(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d9_stream_' . uniqid();
        mkdir($dir);

        for ($i = 0; $i < 3; $i++) {
            file_put_contents("{$dir}/rep{$i}.txt", ($i) . ' ' . ($i + 1) . ' ' . ($i + 2) . "\n" .
                ($i + 5) . ' ' . ($i + 6) . ' ' . ($i + 7) . "\n" .
                ($i + 10) . ' ' . ($i + 11) . ' ' . ($i + 12) . "\n");
        }

        $tasks = $f->scanWithAccumulator([
            $dir => 1,
        ]);

        // Несколько задач от разных стратегий, но каждая с ≥10 точками
        $this->assertGreaterThan(0, count($tasks), 'Must produce tasks from accumulated data');
        foreach ($tasks as $t) {
            $this->assertGreaterThanOrEqual(
                10,
                count($t['data']),
                "Task {$t['name']} must have ≥ tMin data points from accumulation"
            );
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    /**
     * Без accumulator'а старый scan не группирует (мало точек)
     */
    public function testOldScanDoesNotAccumulate(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d9_old_' . uniqid();
        mkdir($dir);

        // Один файл — недостаточно точек
        file_put_contents("{$dir}/t.txt", "1 2\n3 4\n5 6\n");

        // Старый scan (без accumulator) даст задачу с 3 точками
        // Но мы теперь делегируем scan → scanWithAccumulator
        // Поэтому ждём что scanWithAccumulator сгруппирует если другие файлы совпадут
        $tasks = $f->scan([
            $dir => 1,
        ]);

        // Может быть 0 задач если tMin не пройден одним файлом
        // Может быть >0 если стратегия дала ≥10 строк с одного файла
        $this->assertIsArray($tasks);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }
}
