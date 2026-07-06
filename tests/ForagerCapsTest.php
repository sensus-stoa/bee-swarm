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
     * После удаления maxTotal=30 — больше 30 задач
     */
    public function testForagerReturnsMoreThan30Tasks(): void
    {
        $f = new Forager();
        $dir = getenv('HOME') . '/Documents/the_lair/ExoCortex/Journal';
        $tasks = $f->scan([
            $dir => 1,
        ]);

        $this->assertGreaterThan(
            30,
            count($tasks),
            'After removing maxTotal cap, forager must return >30 tasks from 1499 files'
        );
    }

    /**
     * Те же файлы → тот же fingerprint (дедупликация работает)
     */
    public function testSameFilesSameFingerprint(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d8_test_' . uniqid();
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
        ]); // re-scan
        $this->assertFalse($f->hasNewContent(), 'Same files: hasNewContent=false');

        unlink("{$dir}/test.txt");
        rmdir($dir);
    }
}
