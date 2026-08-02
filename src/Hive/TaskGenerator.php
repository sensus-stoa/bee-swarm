<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * TaskGenerator — генерация задач (foraged + базовые синтетические).
 *
 * Извлечён из Hive::getTasks(). D15 Phase 1: базовая структура.
 */
class TaskGenerator
{
    /**
     * Сгенерировать задачи: foraged + базовые + cross-pair.
     *
     * @param array $foragedTasksGlobal глобальный список foraged задач
     * @param array $baseTasks дополнительные задачи
     * @return array<int, array{name: string, data: array, domain: string}>
     */
    public function generate(array $foragedTasksGlobal, array $baseTasks): array
    {
        $tasks = $this->createBaseTasks();
        $tasks = array_merge($tasks, $baseTasks);

        // E1-FIX: cross-pairing из текстовых атомов
        $crossTasks = $this->crossPairTasks($foragedTasksGlobal);
        $tasks = array_merge($tasks, $crossTasks);

        return array_merge($tasks, $foragedTasksGlobal);
    }

    /**
     * Базовые синтетические задачи (логика + арифметика).
     *
     * @return array<int, array{name: string, data: array, domain: string}>
     */
    private function createBaseTasks(): array
    {
        return [
            [
                'name' => 'logic_AND',
                'data' => [[0, 0, 0], [0, 1, 0], [1, 0, 0], [1, 1, 1], [0, 0, 0], [1, 0, 0], [0, 1, 0], [1, 1, 1], [0, 1, 0], [1, 1, 1]],
                'domain' => 'logic',
            ],
            [
                'name' => 'logic_OR',
                'data' => [[0, 0, 0], [0, 1, 1], [1, 0, 1], [1, 1, 1], [0, 0, 0], [1, 1, 1], [0, 1, 1], [1, 0, 1], [0, 1, 1], [1, 1, 1]],
                'domain' => 'logic',
            ],
            [
                'name' => 'arith_mul',
                'data' => [[2, 3, 6], [4, 5, 20], [1, 7, 7], [3, 3, 9], [5, 2, 10], [6, 1, 6], [2, 4, 8], [3, 6, 18], [1, 9, 9], [4, 2, 8]],
                'domain' => 'arithmetic',
            ],
        ];
    }

    /**
     * Cross-pairing: текстовые атомы → X/y пары.
     *
     * @param array $foragedTasksGlobal
     * @return array<int, array{name: string, data: array, domain: string}>
     */
    private function crossPairTasks(array $foragedTasksGlobal): array
    {
        $txtTasks = array_filter($foragedTasksGlobal, fn ($t) => str_starts_with($t['name'] ?? '', 'foraged_txt_'));
        if (count($txtTasks) < 2) {
            return [];
        }

        $atoms = [];
        foreach ($txtTasks as $t) {
            $name = $t['name'];
            foreach ($t['data'] as $row) {
                $val = $row[0] ?? null;
                if ($val !== null) {
                    $atoms[$name][] = (float) $val;
                }
            }
        }

        return \BeeSwarm\Core\TextAtomCrossPairer::crossPair($atoms, 'text_pairs');
    }
}
