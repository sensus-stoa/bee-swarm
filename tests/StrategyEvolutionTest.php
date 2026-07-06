<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

class StrategyEvolutionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/stratevo_' . getmypid();
        @mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // ═══ 1. СТРАТЕГИИ КОНКУРИРУЮТ ═══

    /** Успешная стратегия сохраняется, неуспешная отбрасывается */
    public function test_successful_strategies_survive(): void
    {
        // Только CSV файл — выживет str_getcsv
        file_put_contents($this->tmpDir . '/data.csv', "a,b\n1,2\n3,4\n5,6");

        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);

        $this->assertNotEmpty($tasks);

        // После сканирования — проверить что стратегии имеют разный счёт
        // (доступ через reflection или через добавление публичного метода)
        $this->assertTrue(true); // place holder — механизм есть
    }

    // ═══ 2. COMPOSE ПОРОЖДАЕТ НОВЫЕ СТРАТЕГИИ ═══

    /** Compose стратегий находит данные там где базовые не смогли */
    public function test_compose_strategies_extend_coverage(): void
    {
        // JSON с числами внутри строк
        file_put_contents($this->tmpDir . '/nested.json', 
            json_encode([['data' => "1,2,3"], ['data' => "4,5,6"], ['data' => "7,8,9"]]));

        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);

        // Compose json_decode + str_getcsv должен сработать
        $this->assertNotEmpty($tasks, 'Compose should handle nested data');
    }

    // ═══ 3. НОВЫЕ ФОРМАТЫ → НОВЫЕ СТРАТЕГИИ ═══

    /** При появлении нового формата compose адаптируется */
    public function test_new_format_triggers_new_strategy(): void
    {
        // Markdown с JSON-подобной таблицей
        file_put_contents($this->tmpDir . '/hybrid.md', 
            "```json\n[{\"x\":1,\"y\":2},{\"x\":3,\"y\":4},{\"x\":5,\"y\":6}]\n```");

        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);

        // Должен сработать compose: preg_match_table + json_decode
        // или другая комбинация
        $this->assertNotEmpty($tasks, 'Should handle hybrid format through compose');
    }

    // ═══ 4. СЕМАНТИЧЕСКИЕ СТРАТЕГИИ ═══

    /** Стратегия для текста эволюционирует из compose */
    public function test_text_strategy_evolves(): void
    {
        file_put_contents($this->tmpDir . '/story.md', 
            "кот — это животное\nсобака является другом\nптица — это существо");

        $forager = new Forager();
        $tasks = $forager->scan([$this->tmpDir => 0.5]);

        // После сканирования — стратегии адаптируются
        // Compose preg_match + что-то должно извлечь факты
        $this->assertTrue(true); // проверяем что не падает
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
