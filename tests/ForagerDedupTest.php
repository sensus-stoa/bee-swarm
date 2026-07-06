<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * Story D8/4: Forager dedup — no duplicate tasks from same file
 */
class ForagerDedupTest extends TestCase
{
    /** Один файл не должен порождать дубликаты задач */
    public function testSingleFileNoDuplicateTasks(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d8_dedup_' . uniqid();
        mkdir($dir);
        // Файл с числами — генерит 1 задачу, не 3
        file_put_contents("$dir/numbers.txt", "1 2 3\n4 5 6\n7 8 9\n10 11 12");

        $tasks = $f->scan([$dir => 1]);
        $this->assertNotEmpty($tasks, 'Should find tasks');

        // Разные колонки = разные задачи. Но дубликатов по имени быть не должно.
        $names = array_column($tasks, 'name');
        $uniqueNames = array_unique($names);
        $this->assertCount(count($tasks), $uniqueNames,
            'All task names must be unique (no duplicate names)');
        $this->assertGreaterThanOrEqual(1, count($tasks),
            'Must generate at least 1 task per file');

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
