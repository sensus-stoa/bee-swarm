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
     * Попытаться найти закон через расширенный compose на всех задачах.
     *
     * @param array $tasks массив ['name' => string, 'domain' => string, 'X' => array, 'y' => array]
     * @param float $cvThreshold порог CV для открытия
     * @return array{atom: string, cv: float, mode: string, domain: string, task_name: string}|null открытие или null
     */
    public function dream(array $tasks, float $cvThreshold = 0.01): ?array
    {
        if (empty($tasks)) {
            return null;
        }

        // Все известные grammar ops
        $grammar = (new Grammar())->all();
        if (count($grammar) < 2) {
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

            $found = AtomRegistry::discoverCompose($X, $y, $grammar, $cvThreshold);
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
