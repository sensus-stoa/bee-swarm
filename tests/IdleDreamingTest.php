<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\IdleDreamer;

/**
 * Story S2.2: Idle-Time Dreaming (§2.5-децим)
 *
 * Phase 1: IdleDreamer пытается compose с расширенной грамматикой.
 * Не гарантирует открытие — гарантирует что метод не падает и
 * использует существующую инфраструктуру.
 */
class IdleDreamingTest extends TestCase
{
    /**
     * Phase 1: IdleDreamer не падает на реальных данных.
     * Возвращает null или валидное открытие.
     */
    public function testIdleDreamerHandlesRealData(): void
    {
        // ADD data — известно что решается через '+'
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [2, 5], [4, 1], [6, 3], [8, 7], [10, 0]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10];

        $tasks = [
            ['name' => 'ADD', 'domain' => 'arithmetic', 'X' => $X, 'y' => $y],
        ];

        $dreamer = new IdleDreamer();
        $result = $dreamer->dream($tasks, 0.01);

        // Может найти, может нет — главное что не упал
        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('atom', $result);
            $this->assertArrayHasKey('cv', $result);
            $this->assertLessThanOrEqual(0.01, $result['cv']);
        }
        // null — легитимный результат
        $this->assertTrue($result === null || is_array($result));
    }

    /**
     * Phase 1: на шумных данных возвращает null.
     */
    public function testIdleDreamerReturnsNullOnNoise(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 20; $i++) {
            $X[] = [(float) mt_rand(0, 1000), (float) mt_rand(0, 1000)];
            $y[] = (float) mt_rand(0, 1000);
        }

        $tasks = [
            ['name' => 'NOISE', 'domain' => 'test', 'X' => $X, 'y' => $y],
        ];

        $dreamer = new IdleDreamer();
        $result = $dreamer->dream($tasks, 0.001);

        $this->assertNull($result, 'Must not hallucinate on pure noise');
    }

    /**
     * Phase 1: пустой список задач → null.
     */
    public function testIdleDreamerReturnsNullForEmptyTasks(): void
    {
        $dreamer = new IdleDreamer();
        $result = $dreamer->dream([], 0.01);

        $this->assertNull($result);
    }

    /**
     * Phase 1: IdleDreamer не падает с задачами без признаков (одна колонка).
     */
    public function testIdleDreamerHandlesSingleColumnData(): void
    {
        $X = [[1.0], [2.0], [3.0], [4.0], [5.0]];
        $y = [2.0, 4.0, 6.0, 8.0, 10.0];

        $tasks = [
            ['name' => 'DBL', 'domain' => 'test', 'X' => $X, 'y' => $y],
        ];

        $dreamer = new IdleDreamer();
        $result = $dreamer->dream($tasks, 0.01);

        // Одноколоночные данные могут не дать compose, но метод не должен падать
        $this->assertTrue($result === null || is_array($result));
    }

    /**
     * Phase 1: domain сохраняется из задачи, на которой найдено открытие.
     *
     * Review fix: domain всегда был 'dream' — теперь передаётся от задачи.
     */
    public function testDreamResultPreservesDomainFromTask(): void
    {
        // ADD data в arithmetic
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [2, 5], [4, 1], [6, 3], [8, 7], [10, 0]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10];

        $tasks = [
            ['name' => 'ADD', 'domain' => 'arithmetic', 'X' => $X, 'y' => $y],
            ['name' => 'NOISE', 'domain' => 'noise', 'X' => [[1, 1], [2, 2]], 'y' => [0, 0]],
        ];

        $dreamer = new IdleDreamer();
        $result = $dreamer->dream($tasks, 0.01);

        if ($result !== null) {
            $this->assertArrayHasKey('domain', $result, 'Result must preserve domain');
            $this->assertArrayHasKey('task_name', $result, 'Result must preserve task name');
            $this->assertNotEquals('dream', $result['domain'], 'Domain must be from matching task, not hardcoded');
        }
    }
}
