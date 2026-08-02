<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;

/**
 * IdleDreamer — кросс-доменный поиск в idle-тактах (§2.5-децим).
 *
 * Phase 1: когда foundAny=false, IdleDreamer пытается compose
 * с РАСШИРЕННОЙ грамматикой (все ops из БД, не только BASE_OPS)
 * на всех доступных задачах. Если находит — возвращает открытие.
 */
class IdleDreamer
{
    /**
     * Подготовить задачи для dreaming: извлечь X,y из data, отфильтровать insufficient.
     *
     * @param array $tasks сырые задачи из getTasks()
     * @return array<int, array{name: string, domain: string, X: array, y: array}>
     */
    public static function prepareTasks(array $tasks): array
    {
        $dreamTasks = [];
        foreach ($tasks as $t) {
            $data = $t['data'] ?? [];
            if (empty($data) || count($data[0] ?? []) < 2) {
                continue;
            }
            $X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
            $y = array_column($data, count($data[0]) - 1);
            $nFeat = count($X[0] ?? []);
            if (count($y) < max(10, $nFeat * 5)) {
                continue;
            }
            $dreamTasks[] = [
                'name' => $t['name'] ?? 'unknown',
                'domain' => $t['domain'] ?? 'unknown',
                'X' => $X,
                'y' => $y,
            ];
        }
        return $dreamTasks;
    }

    /**
     * Попытаться найти закон через расширенный compose на всех задачах.
     *
     * @param array $tasks массив ['name' => string, 'domain' => string, 'X' => array, 'y' => array]
     * @param float $cvThreshold порог CV для открытия
     * @param string[] $grammarOps операции грамматики для compose (per-bee + BASE_OPS)
     * @return array{atom: string, cv: float, mode: string, domain: string, task_name: string}|null открытие или null
     */
    public function dream(array $tasks, float $cvThreshold = 0.01, array $grammarOps = []): ?array
    {
        if (empty($tasks)) {
            return null;
        }

        // Использовать переданные grammar ops (per-bee §2.3) или общую БД (legacy)
        if (empty($grammarOps)) {
            $grammarOps = (new Grammar())->all();
        }
        if (count($grammarOps) < 2) {
            return null;
        }

        // Пробуем на каждой задаче
        foreach ($tasks as $task) {
            $X = $task['X'];
            $y = $task['y'];
            $nFeat = count($X[0] ?? []);
            $tMin = max(10, $nFeat * 5);
            if (count($y) < $tMin) {
                continue;
            }

            $found = AtomRegistry::discoverCompose($X, $y, $grammarOps, $cvThreshold);
            if (! empty($found)) {
                $best = $found[0];
                $best['mode'] = 'dream';
                $best['domain'] = $task['domain'] ?? 'unknown';
                $best['task_name'] = $task['name'] ?? 'unknown';
                return $best;
            }
        }

        return null;
    }
}
