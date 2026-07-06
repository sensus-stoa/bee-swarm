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

        // ~600K файл: длинный префикс + таблица чисел в конце
        $fh = fopen("$dir/large.csv", 'w');
        // Long garbage prefix to push file past 500K
        fwrite($fh, str_repeat("x", 550_000) . "\n");
        // Actual data at the end
        fwrite($fh, "1 2 3\n4 5 6\n7 8 9\n10 11 12\n");
        fclose($fh);
        $size = filesize("$dir/large.csv");
        $this->assertGreaterThan(500_000, $size, 'Test file must be >500K');

        $tasks = $f->scan([$dir => 1]);

        // Должен найти хотя бы одну задачу из большого файла
        $largeTasks = array_filter($tasks, fn($t) => str_contains($t['name'], 'large.csv'));
        $this->assertNotEmpty($largeTasks,
            "Large file ($size bytes) must produce tasks — currently skipped at >500K");

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
