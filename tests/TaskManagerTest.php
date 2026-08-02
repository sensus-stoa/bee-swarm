<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\TaskManager;

/**
 * Story D14 Phase 2: TaskManager — извлечение getTasks() из Hive.
 */
class TaskManagerTest extends TestCase
{
    /**
     * TaskManager генерирует базовые задачи (ADD, MUL, AND, OR, XOR).
     *
     * Predicted: FAIL — класс TaskManager не существует.
     */
    public function testGeneratesBaseTasks(): void
    {
        $manager = new TaskManager();
        $tasks = $manager->getBaseTasks();

        $this->assertNotEmpty($tasks, 'Must generate base tasks');
        $names = array_column($tasks, 'name');
        $this->assertContains('ADD', $names);
        $this->assertContains('MUL', $names);
        $this->assertContains('AND', $names);
    }

    /**
     * Каждая задача имеет 'data' с X и y.
     *
     * Predicted: FAIL — класс не существует.
     */
    public function testTasksHaveDataFormat(): void
    {
        $manager = new TaskManager();
        $tasks = $manager->getBaseTasks();

        foreach ($tasks as $task) {
            $this->assertArrayHasKey('data', $task);
            $this->assertArrayHasKey('name', $task);
            $this->assertArrayHasKey('domain', $task);
            $this->assertNotEmpty($task['data']);
            // Каждая строка: features + target
            $this->assertGreaterThanOrEqual(2, count($task['data'][0]));
        }
    }
}
