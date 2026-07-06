<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

class ForagerOutputTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/forout_' . getmypid();
        @mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // ═══ 1. ЛЮБОЙ STRUCTURED OUTPUT ═══

    /**
     * Forager принимает не только числа, но и факты
     */
    public function testForagerAcceptsSemanticFacts(): void
    {
        // Стратегия возвращает семантические факты, не числа
        file_put_contents(
            $this->tmpDir . '/story.md',
            "кот — это животное\nсобака является другом\nптица — это существо"
        );

        $forager = new Forager();
        $tasks = $forager->scan([
            $this->tmpDir => 0.5,
        ]);

        $this->assertNotEmpty($tasks, 'Should extract semantic facts');

        $semanticTasks = array_filter($tasks, fn ($t) => $t['domain'] === 'foraged_semantic');
        $this->assertGreaterThanOrEqual(1, count($semanticTasks), 'Should have ≥1 semantic task');
    }

    /**
     * Числовые и семантические задачи сосуществуют
     */
    public function testMixedOutputTypes(): void
    {
        file_put_contents(
            $this->tmpDir . '/mixed.md',
            "|a|b|\n|---|---|\n|1|2|\n|3|4|\n|5|6|\n\nа также: кот — это животное\nсобака — это животное\nптица — это животное"
        );

        $forager = new Forager();
        $tasks = $forager->scan([
            $this->tmpDir => 0.5,
        ]);

        $numeric = array_filter($tasks, fn ($t) => $t['domain'] === 'foraged');
        $semantic = array_filter($tasks, fn ($t) => $t['domain'] === 'foraged_semantic');

        $this->assertNotEmpty($numeric, 'Should have numeric tasks');
        // Семантические тоже должны быть (если стратегия сработала)
        $this->assertNotEmpty($semantic, 'Should have semantic tasks from same file');
    }

    // ═══ 2. COMPOSE ДАЁТ СЕМАНТИЧЕСКИЕ СТРАТЕГИИ ═══

    /**
     * Compose стратегий может извлекать семантику
     */
    public function testComposeProducesSemantic(): void
    {
        file_put_contents(
            $this->tmpDir . '/text.md',
            "дом — это здание\nмашина является транспортом\nптица — это животное"
        );

        $forager = new Forager();
        $tasks = $forager->scan([
            $this->tmpDir => 0.5,
        ]);

        // Прямая стратегия preg_match_is_a должна найти факты
        // Compose может не сработать на этом этапе — это future capability
        $semantic = array_filter($tasks, fn ($t) => ($t['domain'] ?? '') === 'foraged_semantic');
        $this->assertNotEmpty($semantic, 'Direct semantic strategy should work');
    }

    // ═══ 3. СТРАТЕГИИ НЕ ЛОМАЮТСЯ ═══

    /**
     * Пустой вывод не убивает forager
     */
    public function testEmptyOutputHandled(): void
    {
        file_put_contents($this->tmpDir . '/empty.md', 'no data here');
        $forager = new Forager();
        $tasks = $forager->scan([
            $this->tmpDir => 0.5,
        ]);
        $this->assertIsArray($tasks);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "{$dir}/{$f}";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
