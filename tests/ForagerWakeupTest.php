<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager;

/**
 * Story 02c: Forager Integration (Infrastructure Prerequisite)
 *
 * Forager должен доставлять ≥1 новый домен ИЛИ ≥5 новых задач.
 * Источники данных (sources): файлы, сеть, LLM — может быть несколько
 * одновременно. Тесты изолированы от реальных путей.
 *
 * @group disabled
 */
class ForagerWakeupTest extends TestCase
{
    /** hasNewContent() — метод для проверки достаточности новых данных */
    public function test_has_new_content_exists(): void
    {
        $forager = new Forager();
        $this->assertTrue(method_exists($forager, 'hasNewContent'),
            'Forager must implement hasNewContent() for plateau exit');
    }

    /** Пустая директория — hasNewContent() = false */
    public function test_empty_scan_no_new_content(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);

        $forager->scan([$tmpDir]);
        $this->assertFalse($forager->hasNewContent(),
            'Empty directory must not claim new content');

        rmdir($tmpDir);
    }

    /** После consume — повторный скан без изменений не даёт нового */
    public function test_consume_then_rescan_no_new_content(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents("$tmpDir/data.txt", "x,y\n1,2\n3,4\n5,6\n7,8\n9,10\n");

        // Первый скан — есть контент
        $forager->scan([$tmpDir]);
        $this->assertTrue($forager->hasNewContent(), 'First scan finds content');

        // Потребили
        $forager->markContentConsumed();
        $this->assertFalse($forager->hasNewContent(), 'Flag reset after consume');

        // Повторный скан без изменений в файлах — нового нет
        $forager->scan([$tmpDir]);
        $this->assertFalse($forager->hasNewContent(),
            'Repeat scan without file changes must not claim new content');

        unlink("$tmpDir/data.txt");
        rmdir($tmpDir);
    }

    /** markContentConsumed() сбрасывает флаг новизны */
    public function test_mark_content_consumed_resets_flag(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents("$tmpDir/data.txt", "x,y\n1,2\n3,4\n5,6\n7,8\n9,10\n");

        $forager->scan([$tmpDir]);
        $this->assertTrue($forager->hasNewContent(), 'Should find tasks in file');

        $forager->markContentConsumed();
        $this->assertFalse($forager->hasNewContent(), 'Flag reset after consume');

        unlink("$tmpDir/data.txt");
        rmdir($tmpDir);
    }
}
