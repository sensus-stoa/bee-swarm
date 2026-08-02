<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\TaskGenerator;

/**
 * Story D15: TaskGenerator — извлечение getTasks() из Hive.
 */
class TaskGeneratorTest extends TestCase
{
    /**
     * TaskGenerator возвращает базовые задачи через TaskManager.
     */
    public function testGeneratorReturnsBaseTasks(): void
    {
        $gen = new TaskGenerator();
        $tasks = $gen->generate([]);

        $this->assertIsArray($tasks);
        $this->assertNotEmpty($tasks, 'Must have base tasks from TaskManager');
        $names = array_column($tasks, 'name');
        $hasLogic = false;
        foreach ($names as $n) {
            if (str_contains($n, 'logic_') || str_contains($n, 'AND') || str_contains($n, 'OR')) {
                $hasLogic = true;
                break;
            }
        }
        $this->assertTrue($hasLogic, 'Must have logic tasks from TaskManager');
    }

    /**
     * Размер данных соответствует TaskManager (≥5 задач).
     */
    public function testBaseTaskCount(): void
    {
        $gen = new TaskGenerator();
        $tasks = $gen->generate([]);
        $this->assertGreaterThanOrEqual(5, count($tasks), 'TaskManager must provide ≥5 base tasks');
    }

    /**
     * Cross-pair не добавляет задачи без foraged_txt_*.
     */
    public function testNoCrossPairWithoutTextAtoms(): void
    {
        $gen = new TaskGenerator();
        $tasks = $gen->generate([]);
        $names = array_column($tasks, 'name');
        foreach ($names as $n) {
            $this->assertStringNotContainsString('txt_pair', $n, 'No cross-pair without text atoms');
        }
    }
}
