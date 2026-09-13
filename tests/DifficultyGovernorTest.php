<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\DifficultyGovernor;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * §2.6 Environmental Pressure — WU-2: адаптация сложности.
 *
 * Контракт протокола: окно исходов (20), ≥90% решённых → сложность РАСТЁТ
 * (next depth, больше фич), ≤10% → ПАДАЕТ. Floor 1. Среда удерживается на
 * границе возможностей популяции. Смена → окно сбрасывается (свежий замер
 * на новом уровне); без смены окно скользящее.
 *
 * Отдельно: env_state персистентность, фильтр генерации (D → min nFeat)
 * с fallback-гвардом, live-path фильтр в getTasks.
 */
final class DifficultyGovernorTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();  // строит DDL (включая env_state)
        putenv('FORAGER_SOURCES=:');
        $this->logFile = tempnam(sys_get_temp_dir(), 'diff_');
    }

    protected function tearDown(): void
    {
        putenv('FORAGER_SOURCES');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * Скормить говернеру серию исходов.
     *
     * @return list<string|null> события по каждому исходу
     */
    private function feed(DifficultyGovernor $g, int $solved, int $total): array
    {
        $events = [];
        for ($i = 0; $i < $total; $i++) {
            $events[] = $g->recordOutcome($i < $solved);
        }

        return $events;
    }

    /**
     * 18/20 = 90% → rise, D=2.
     */
    public function testRiseAtExactly90Percent(): void
    {
        $g = new DifficultyGovernor();
        $events = $this->feed($g, 18, 20);

        self::assertContains('rise', $events, '18/20 = 0.9 >= 0.9 → рост');
        self::assertSame(2, $g->difficulty());
    }

    /**
     * 17/20 = 85% < 90% → держим.
     */
    public function testHoldBelow90Percent(): void
    {
        $g = new DifficultyGovernor();
        $events = $this->feed($g, 17, 20);

        self::assertNotContains('rise', $events);
        self::assertNotContains('fall', $events);
        self::assertSame(1, $g->difficulty());
    }

    /**
     * 2/20 = 10% → fall (после предварительного rise до D=2).
     */
    public function testFallAtExactly10Percent(): void
    {
        $g = new DifficultyGovernor();
        $this->feed($g, 18, 20);  // rise → D=2
        $events = $this->feed($g, 2, 20);

        self::assertContains('fall', $events, '2/20 = 0.1 <= 0.1 → падение');
        self::assertSame(1, $g->difficulty());
    }

    /**
     * 3/20 = 15% > 10% → держим (не fall).
     */
    public function testHoldAbove10Percent(): void
    {
        $g = new DifficultyGovernor();
        $this->feed($g, 18, 20);  // D=2
        $events = $this->feed($g, 3, 20);

        self::assertNotContains('fall', $events);
        self::assertSame(2, $g->difficulty());
    }

    /**
     * Floor: D=1, 0/20 → fall-ветка срабатывает, но D не падает ниже 1, события нет.
     */
    public function testFloorAtOne(): void
    {
        $g = new DifficultyGovernor();
        $events = $this->feed($g, 0, 20);

        self::assertNotContains('fall', $events, 'флор → смены нет, лог молчит (честный verify)');
        self::assertSame(1, $g->difficulty());
    }

    /**
     * После смены окно пустое: один исход не даёт события.
     */
    public function testWindowResetsAfterChange(): void
    {
        $g = new DifficultyGovernor();
        $this->feed($g, 18, 20);  // rise, окно сброшено

        self::assertNull($g->recordOutcome(true), 'окно после смены пустое');
        self::assertNull($g->recordOutcome(true));
        self::assertSame(2, $g->difficulty());
    }

    /**
     * Персистентность: rise до D=2 → новый инстанс видит 2 из env_state.
     */
    public function testPersistenceAcrossInstances(): void
    {
        $g = new DifficultyGovernor();
        $this->feed($g, 18, 20);
        self::assertSame(2, $g->difficulty());

        $fresh = new DifficultyGovernor();
        self::assertSame(2, $fresh->difficulty(), 'D восстановлен из env_state');
    }

    /**
     * Фильтр при D=1 — no-op (ничего не режем).
     */
    public function testFilterDisabledAtLevelOne(): void
    {
        $tasks = [
            [
                'name' => 'a',
                'data' => [[1.0, 2.0]],
            ],
            [
                'name' => 'b',
                'data' => [[1.0, 2.0, 3.0]],
            ],
        ];

        self::assertSame($tasks, DifficultyGovernor::filterByDifficulty($tasks, 1));
    }

    /**
     * Фильтр при D=2 режет nFeat=1, держит nFeat>=2; text-задачи не режем.
     */
    public function testFilterKeepsHardTasks(): void
    {
        $tasks = [
            [
                'name' => 'easy',
                'domain' => 'metrics',
                'data' => [[1.0, 2.0]],
            ],
            [
                'name' => 'ok1',
                'domain' => 'metrics',
                'data' => [[1.0, 2.0, 3.0]],
            ],
            [
                'name' => 'ok2',
                'domain' => 'metrics',
                'data' => [[1.0, 2.0, 4.0]],
            ],
            [
                'name' => 'ok3',
                'domain' => 'metrics',
                'data' => [[1.0, 2.0, 5.0]],
            ],
            [
                'name' => 'txt',
                'domain' => 'foraged_semantic',
                'content' => 'x',
            ],
        ];
        $kept = DifficultyGovernor::filterByDifficulty($tasks, 2);
        $names = array_column($kept, 'name');

        self::assertNotContains('easy', $names, 'nFeat=1 отсечена при D=2');
        self::assertContains('ok1', $names);
        self::assertContains('ok2', $names);
        self::assertContains('ok3', $names);
        self::assertContains('txt', $names, 'без data — не режем (semantic)');
    }

    /**
     * Fallback: фильтр опустошил бы пул → возвращаем всё (среда не голодает).
     */
    public function testFilterFallbackOnStarvation(): void
    {
        $tasks = [
            [
                'name' => 'easy1',
                'domain' => 'metrics',
                'data' => [[1.0, 2.0]],
            ],
            [
                'name' => 'easy2',
                'domain' => 'metrics',
                'data' => [[3.0, 4.0]],
            ],
        ];

        self::assertSame(
            $tasks,
            DifficultyGovernor::filterByDifficulty($tasks, 2),
            'осталось < MIN_POOL — фильтр возвращает исходный пул'
        );
    }

    /**
     * Live-path: getTasks применяет фильтр сложности (Hive reflection).
     */
    public function testGetTasksAppliesDifficultyFilter(): void
    {
        $hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $hive->run();

        // Говернер через публичный API доведён до D=2, инжектится в Hive.
        $gov = new DifficultyGovernor();
        $this->feed($gov, 18, 20);
        self::assertSame(2, $gov->difficulty());
        $prop = new \ReflectionProperty(Hive::class, 'difficulty');
        $prop->setAccessible(true);
        $prop->setValue($hive, $gov);

        $poolProp = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $poolProp->setAccessible(true);
        $poolProp->setValue($hive, $this->poolForFilterTest());

        $m = new \ReflectionMethod(Hive::class, 'getTasks');
        $m->setAccessible(true);
        $tasks = $m->invokeArgs($hive, [true]);  // skipGenerated: путь корпуса

        $names = array_column($tasks, 'name');
        self::assertNotContains('envp_1f', $names, 'nFeat=1 отфильтрована при D=2 в живом пути');
        self::assertContains('envp_2f_a', $names, 'nFeat=2 выживает при D=2');
    }

    /**
     * Пул: 1-feat (12 строк — проходит filterInsufficient) и три 2-feat.
     *
     * @return array<int, array<string, mixed>>
     */
    private function poolForFilterTest(): array
    {
        $rows1f = [];
        $rows2f = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows1f[] = [(float) $i, 2.0 * $i];
            $rows2f[] = [(float) $i, (float) $i, 2.0 * $i];
        }

        return [
            [
                'name' => 'envp_1f',
                'domain' => 'metrics',
                'data' => $rows1f,
            ],
            [
                'name' => 'envp_2f_a',
                'domain' => 'metrics',
                'data' => $rows2f,
            ],
            [
                'name' => 'envp_2f_b',
                'domain' => 'metrics',
                'data' => $rows2f,
            ],
            [
                'name' => 'envp_2f_c',
                'domain' => 'metrics',
                'data' => $rows2f,
            ],
        ];
    }
}
