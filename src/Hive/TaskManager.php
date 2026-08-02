<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * TaskManager — генерация и фильтрация задач.
 *
 * Извлечён из Hive::getTasks(). Phase 2: только базовые задачи.
 */
class TaskManager
{
    /**
     * @return array<int, array{name: string, domain: string, data: list<list<float>>}>
     */
    public function getBaseTasks(): array
    {
        return [
            [
                'name' => 'AND',
                'domain' => 'logic',
                'data' => [[0, 0, 0], [0, 1, 0], [1, 0, 0], [1, 1, 1], [0, 0, 0], [1, 0, 0], [0, 1, 0], [1, 1, 1], [0, 1, 0], [1, 0, 0]],
            ],
            [
                'name' => 'ADD',
                'domain' => 'arithmetic',
                'data' => [[1, 2, 3], [3, 4, 7], [5, 6, 11], [7, 8, 15], [9, 10, 19], [2, 5, 7], [4, 1, 5], [6, 3, 9], [8, 7, 15], [10, 0, 10]],
            ],
            [
                'name' => 'MUL',
                'domain' => 'arithmetic',
                'data' => [[1, 2, 2], [2, 3, 6], [3, 4, 12], [4, 5, 20], [5, 6, 30], [1, 3, 3], [2, 4, 8], [3, 2, 6], [4, 1, 4], [5, 0, 0]],
            ],
            [
                'name' => 'OR',
                'domain' => 'logic',
                'data' => [[0, 0, 0], [0, 1, 1], [1, 0, 1], [1, 1, 1], [0, 0, 0], [1, 0, 1], [0, 1, 1], [1, 1, 1], [0, 1, 1], [1, 0, 1]],
            ],
            [
                'name' => 'XOR',
                'domain' => 'logic',
                'data' => [[0, 0, 0], [0, 1, 1], [1, 0, 1], [1, 1, 0], [0, 0, 0], [1, 0, 1], [0, 1, 1], [1, 1, 0], [0, 1, 1], [1, 0, 1]],
            ],
        ];
    }
}
