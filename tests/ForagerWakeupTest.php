<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * Story 02c: Forager Integration (Infrastructure Prerequisite)
 *
 * Forager должен доставлять ≥1 новый домен ИЛИ ≥5 новых задач.
 * Источники данных (sources): файлы, сеть, LLM — может быть несколько.
 * Тесты изолированы от реальных путей.
 */
class ForagerWakeupTest extends TestCase
{
    /**
     * hasNewContent() — метод для проверки достаточности новых данных
     */
    public function testHasNewContentExists(): void
    {
        $forager = new Forager();
        $this->assertTrue(
            method_exists($forager, 'hasNewContent'),
            'Forager must implement hasNewContent() for plateau exit'
        );
    }

    /**
     * Пустая директория — hasNewContent() = false
     */
    public function testEmptyScanNoNewContent(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);

        $forager->scan([
            $tmpDir => 1,
        ]);
        $this->assertFalse(
            $forager->hasNewContent(),
            'Empty directory must not claim new content'
        );

        rmdir($tmpDir);
    }

    /**
     * Данные найдены — hasNewContent() = true (≥5 задач)
     */
    public function testScanWithDataHasNewContent(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents("{$tmpDir}/data.txt", "x,y\n1,2\n3,4\n5,6\n7,8\n9,10\n");

        $forager->scan([
            $tmpDir => 1,
        ]);
        $this->assertTrue($forager->hasNewContent(), 'Should find tasks in file');

        unlink("{$tmpDir}/data.txt");
        rmdir($tmpDir);
    }

    /**
     * markContentConsumed() сбрасывает флаг новизны
     */
    public function testMarkContentConsumedResetsFlag(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents("{$tmpDir}/data.txt", "x,y\n1,2\n3,4\n5,6\n7,8\n9,10\n");

        $forager->scan([
            $tmpDir => 1,
        ]);
        $this->assertTrue($forager->hasNewContent(), 'Should find tasks in file');

        $forager->markContentConsumed();
        $this->assertFalse($forager->hasNewContent(), 'Flag reset after consume');

        unlink("{$tmpDir}/data.txt");
        rmdir($tmpDir);
    }

    /**
     * getNewDomainCount() — число уникальных доменов
     */
    public function testGetNewDomainCount(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents("{$tmpDir}/data.txt", "x,y\n1,2\n3,4\n5,6\n7,8\n9,10\n");

        $forager->scan([
            $tmpDir => 1,
        ]);
        $this->assertSame(
            1,
            $forager->getNewDomainCount(),
            'All foraged tasks share one domain'
        );

        unlink("{$tmpDir}/data.txt");
        rmdir($tmpDir);
    }

    /**
     * getNewTaskCount() — число новых задач
     */
    public function testGetNewTaskCount(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents("{$tmpDir}/data.txt", "x,y\n1,2\n3,4\n5,6\n7,8\n9,10\n");

        $forager->scan([
            $tmpDir => 1,
        ]);
        $this->assertGreaterThan(
            0,
            $forager->getNewTaskCount(),
            'Should report task count from last scan'
        );

        unlink("{$tmpDir}/data.txt");
        rmdir($tmpDir);
    }

    /**
     * BUG: повторный скан тех же данных — hasNewContent() = false
     */
    public function testRescanSameDirNoNewContent(): void
    {
        $forager = new Forager();
        $tmpDir = sys_get_temp_dir() . '/bee_swarm_forager_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents("{$tmpDir}/data.txt", "x,y\n1,2\n3,4\n5,6\n7,8\n9,10\n");

        // Первый скан — есть новое
        $forager->scan([
            $tmpDir => 1,
        ]);
        $this->assertTrue($forager->hasNewContent(), 'First scan finds content');

        // Потребили
        $forager->markContentConsumed();

        // Второй скан тех же файлов — нового НЕТ
        $forager->scan([
            $tmpDir => 1,
        ]);
        $this->assertFalse(
            $forager->hasNewContent(),
            'BUG: rescan of same files must NOT claim new content'
        );

        unlink("{$tmpDir}/data.txt");
        rmdir($tmpDir);
    }
}
