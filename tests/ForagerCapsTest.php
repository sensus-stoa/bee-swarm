<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * Story D8: Forager caps — maxTotal removal
 */
class ForagerCapsTest extends TestCase
{
    /**
     * После удаления maxTotal=30 — больше 1 задачи с нескольких файлов
     */
    public function testForagerProcessesMultipleFiles(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d8_caps_' . uniqid();
        mkdir($dir);
        // Несколько маленьких файлов
        for ($i = 1; $i <= 5; $i++) {
            $h = fopen("{$dir}/file{$i}.txt", 'w');
            fwrite($h, "{$i} " . ($i * 2) . ' ' . ($i * 3) . "\n");
            fwrite($h, ($i + 1) . ' ' . ($i + 3) . ' ' . ($i + 5) . "\n");
            fwrite($h, ($i + 2) . ' ' . ($i + 6) . ' ' . ($i + 10) . "\n");
            fclose($h);
        }
        $tasks = $f->scan([
            $dir => 1,
        ]);
        $this->assertGreaterThan(0, count($tasks), 'Should find tasks in multiple files');

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    /**
     * Те же файлы → тот же fingerprint (дедупликация работает)
     */
    public function testSameFilesSameFingerprint(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d8_caps_fp_' . uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/test.txt", "1 2 3\n4 5 6\n7 8 9");

        $first = $f->scan([
            $dir => 1,
        ]);
        $this->assertNotEmpty($first, 'Should find tasks');
        $this->assertTrue($f->hasNewContent(), 'First scan: has new content');

        $f->markContentConsumed();
        $f->scan([
            $dir => 1,
        ]);
        $this->assertFalse($f->hasNewContent(), 'Same files: hasNewContent=false');

        unlink("{$dir}/test.txt");
        rmdir($dir);
    }
}
