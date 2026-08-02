<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * TaskGenerator — генерация задач (foraged + базовые + cross-pair).
 *
 * Извлечён из Hive::getTasks(). D15: делегирует TaskManager для базовых задач.
 */
class TaskGenerator
{
    /**
     * Сгенерировать задачи: базовые (TaskManager) + cross-pair + foraged.
     *
     * @param array $foragedTasksGlobal глобальный список foraged задач
     * @param array $crossTasks дополнительные cross-pair задачи (не используется — TaskGenerator сам делает cross-pair)
     * @return array<int, array{name: string, data: array, domain: string}>
     */
    public function generate(array $foragedTasksGlobal, array $crossTasks = []): array
    {
        // Базовые синтетические задачи — делегируем TaskManager
        $tm = new TaskManager();
        $tasks = $tm->getBaseTasks();

        // E1-FIX: cross-pairing из текстовых атомов
        $cross = $this->crossPairTasks($foragedTasksGlobal);
        $tasks = array_merge($tasks, $cross);

        // Foraged задачи — основной источник данных
        return array_merge($tasks, $foragedTasksGlobal);
    }

    /**
     * Cross-pairing: текстовые атомы → X/y пары.
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
