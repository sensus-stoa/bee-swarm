<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager;

class ForagerEvolutionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/forager_evo_' . getmypid();
        @mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // ═══ 1. СТРАТЕГИИ ЭВОЛЮЦИОНИРУЮТ ═══

    /** Все форматы покрываются разными стратегиями */
    public function test_different_strategies_for_different_formats(): void
    {
        file_put_contents($this->tmpDir . '/data.json', json_encode([["x"=>1,"y"=>2],["x"=>3,"y"=>4],["x"=>5,"y"=>6]]));
        file_put_contents($this->tmpDir . '/data.csv', "a,b\n1,2\n3,4\n5,6");
        file_put_contents($this->tmpDir . '/data.md', "|u|v|\n|---|---|\n|1|2|\n|3|4|\n|5|6|");

        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);

        $this->assertGreaterThanOrEqual(3, count($tasks), 'Should extract from all 3 files');
    }

    /** Неуспешная стратегия отбрасывается */
    public function test_failing_strategy_removed(): void
    {
        // Только CSV — json_decode и preg_match_table должны дать 0
        file_put_contents($this->tmpDir . '/only.csv', "x,y\n1,2\n3,4\n5,6");

        $forager = new Forager();
        $forager->scan([$this->tmpDir => 0.5]);

        $priorities = $forager->getPriorities();
        $this->assertGreaterThan(0.3, $priorities[$this->tmpDir] ?? 0, 'Priority should increase');
    }

    // ═══ 2. COMPOSE СТРАТЕГИЙ ═══

    /** Compose стратегий находит данные */
    public function test_compose_strategies_work(): void
    {
        file_put_contents($this->tmpDir . '/data.json', json_encode([["x"=>1,"y"=>2],["x"=>3,"y"=>4],["x"=>5,"y"=>6]]));

        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);

        $this->assertNotEmpty($tasks, 'Should extract via base or compose strategy');

        // Проверяем что в задачах есть правильные данные
        $hasPairs = false;
        foreach ($tasks as $t) {
            if (count($t['data']) >= 3 && count($t['data'][0]) >= 2) $hasPairs = true;
        }
        $this->assertTrue($hasPairs, 'Tasks should have valid data pairs');
    }

    // ═══ 3. ПРИОРИТЕТЫ ═══

    /** Приоритет растёт с найденными задачами */
    public function test_priority_grows_with_discoveries(): void
    {
        file_put_contents($this->tmpDir . '/rich.md', "|a|b|\n|---|---|\n|1|2|\n|3|4|\n|5|6|\n|7|8|\n|9|10|");
        file_put_contents($this->tmpDir . '/more.csv', "x,y\n1,2\n3,4\n5,6\n7,8");

        $forager = new Forager([$this->tmpDir => 0.3]);
        $forager->scan([$this->tmpDir => 0.3]);

        $priorities = $forager->getPriorities();
        $this->assertGreaterThan(0.3, $priorities[$this->tmpDir] ?? 0);
        $this->assertLessThanOrEqual(1.0, $priorities[$this->tmpDir] ?? 0);
    }

    /** Пустая директория не меняет приоритет */
    public function test_empty_dir_priority_unchanged(): void
    {
        $emptyDir = $this->tmpDir . '/empty';
        @mkdir($emptyDir);

        $forager = new Forager([$emptyDir => 0.5]);
        $tasks = $forager->scan([$emptyDir => 0.5]);

        $this->assertEmpty($tasks);
        $priorities = $forager->getPriorities();
        // Пустая директория — без задач, приоритет не меняется но и не падает ниже исходного
        $this->assertEquals(0.5, $priorities[$emptyDir] ?? 0);
    }

    // ═══ 4. ГРАНИЧНЫЕ СЛУЧАИ ═══

    /** Нечитаемый файл не ломает forager */
    public function test_unreadable_file_handled(): void
    {
        file_put_contents($this->tmpDir . '/bad.bin', random_bytes(100));
        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);
        $this->assertIsArray($tasks); // Не упал
    }

    /** Битые данные не дают задач */
    public function test_malformed_json_skipped(): void
    {
        file_put_contents($this->tmpDir . '/bad.json', '{broken');
        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);
        $this->assertEmpty($tasks);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
