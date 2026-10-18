<?php

declare(strict_types=1);

/**
 * WORD-CUM-001 / EVO-FIX-001 FIX-1: целостность fitness при смене мира.
 *
 * transitionReevaluate(): при переходе в новый мир ПЕРЕСЧИТЫВАЕТ train/valid
 * всех переносимых особей на данных нового мира, проставляет world_id и
 * fitness_hash, дебетует eval-пул. Сравнение особей с разными
 * world_id/fitness_hash запрещено (assertComparable бросает).
 *
 * Подключается и из evolution.php, и из тестов (EvoFix001Test).
 */

use BeeSwarm\Core\WordCodec;

final class WordCumTransition
{
    /**
     * Хеш fitness-данных мира: id + все формулы (train/valid/final).
     */
    public static function fitnessHash(int $w, array $world): string
    {
        $parts = [(string) $w];
        foreach (['train', 'valid', 'final'] as $role) {
            foreach ($world['tasks'][$role] as $t) {
                $parts[] = (string) $t['formula'];
            }
        }

        return md5(implode('|', $parts));
    }

    /**
     * Защитный гард: все особи выборки обязаны иметь ОДИН fitness_hash.
     *
     * @param list<array> $inds
     */
    public static function assertComparable(array $inds): void
    {
        $seen = [];
        foreach ($inds as $ind) {
            $h = $ind['fitness_hash'] ?? null;
            if ($h === null) {
                throw new RuntimeException('fitness integrity: особь без fitness_hash');
            }
            $seen[$h] = true;
        }
        if (count($seen) > 1) {
            throw new RuntimeException('fitness integrity: смешение особей разных миров (' . implode(',', array_keys($seen)) . ')');
        }
    }

    /**
     * Переоценка переносимой популяции на данных нового мира (FIX-1).
     *
     * @param array<int, list<array>> $pops   task_index => population
     * @param array<int, array>       $tasks  task_index => task data нового мира
     * @param string                  $arm    A|B|C|D (D — AST, остальные — геномы)
     * @param int|null                $evals  счётчик пула (by-ref)
     * @param int                     $pool   размер пула
     *
     * @return array обновлённые pops (train/valid нового мира, world_id, fitness_hash)
     */
    public static function reevaluate(array $pops, array $tasks, int $w, string $hash, string $arm, ?array $dict, int &$evals, int $pool): array
    {
        foreach ($tasks as $ti => $task) {
            $rowsAll = array_merge($task['train'], $task['held']);
            $yAll = array_merge($task['y_train'], $task['y_held']);
            foreach ($pops[$ti] ?? [] as $pi => $ind) {
                if ($evals + count($rowsAll) > $pool) {
                    // пул исчерпан: особь помечается непригодной для сравнения
                    $pops[$ti][$pi]['train'] = null;
                    $pops[$ti][$pi]['valid'] = null;
                    $pops[$ti][$pi]['world_id'] = $w;
                    $pops[$ti][$pi]['fitness_hash'] = $hash;

                    continue;
                }
                $ast = isset($ind['ast']) && is_array($ind['ast'])
                    ? $ind['ast']
                    : ((! isset($ind['g']) || ! is_array($ind['g']) || $arm === 'D' ? null : WordCodec::decode($ind['g'], $dict)));
                if ($ast === null) {
                    // нет исполнимой формы (пул исчерпан на decode) — особь не сравнима
                    $pops[$ti][$pi]['train'] = null;
                    $pops[$ti][$pi]['valid'] = null;
                    $pops[$ti][$pi]['world_id'] = $w;
                    $pops[$ti][$pi]['fitness_hash'] = $hash;

                    continue;
                }
                $pops[$ti][$pi]['train'] = GenomeEvolveLib::rmse($ast, $task['train'], $task['y_train']);
                $pops[$ti][$pi]['valid'] = GenomeEvolveLib::rmse($ast, $task['held'], $task['y_held']);
                $pops[$ti][$pi]['world_id'] = $w;
                $pops[$ti][$pi]['fitness_hash'] = $hash;
                $evals += count($rowsAll);
            }
        }

        return $pops;
    }
}
