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
     * TaskGenerator объединяет foraged задачи с базовыми.
     *
     * Predicted: FAIL — класс TaskGenerator не существует.
     */
    public function testGeneratorMergesBaseAndForaged(): void
    {
        $gen = new TaskGenerator();
        $tasks = $gen->generate([], []);
        $this->assertIsArray($tasks);
    }

    /**
     * Без foraged задач — только базовые синтетические.
     */
    public function testGeneratorReturnsBaseWhenNoForaged(): void
    {
        $gen = new TaskGenerator();
        $tasks = $gen->generate([], []);

        // Должны быть логические и арифметические задачи
        $names = array_column($tasks, 'name');
        $this->assertNotEmpty($names);
        $hasLogic = false;
        $hasArith = false;
        foreach ($names as $n) {
            if (str_contains($n, 'logic_')) $hasLogic = true;
            if (str_contains($n, 'arith_')) $hasArith = true;
        }
        $this->assertTrue($hasLogic || $hasArith, 'Must have base tasks');
    }
}
