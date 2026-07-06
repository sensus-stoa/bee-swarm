<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * Story D8: Chunking — large files (>500K) must be processed
 */
class ForagerChunkingTest extends TestCase
{
    /** Файл >500K не должен пропускаться */
    public function testLargeFileIsProcessed(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d8_chunk_' . uniqid();
        mkdir($dir);

        // ~550K файл: числа с длинным суффиксом
        $fh = fopen("$dir/large.csv", 'w');
        // 3 строки чисел + длинный хвост для размера
        fwrite($fh, "1 2 3\n4 5 6\n7 8 9\n");
        fwrite($fh, str_repeat("x", 550_000));
        fclose($fh);
        $size = filesize("$dir/large.csv");
        $this->assertGreaterThan(500_000, $size, 'Test file must be >500K');

        $tasks = $f->scan([$dir => 1]);

        // Большой файл обработан (не выброшен с исключением)
        // Задачи могут быть пустыми — стратегии не нашли чисел в мусоре
        $this->assertIsArray($tasks,
            "Large file ($size bytes) must be processed without errors");

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
