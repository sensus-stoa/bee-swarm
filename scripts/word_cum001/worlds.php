<?php

declare(strict_types=1);

/**
 * WORD-CUM-001 CHECKPOINT B: замороженные миры (AMENDMENT v2 §A1-A3, A6).
 *
 * Истинные формулы существуют ТОЛЬКО здесь и в confirmatory-манифесте.
 * Runtime эволюции получает данные (строки+y) и — ограниченно — ключи
 * train/validation задач для birth-gate duplicate-check (A3).
 * Final-ключи: только запечатанная однократная оценка + разовый аудит чистоты.
 *
 * Все законы — базовая грамматика xN, источники REAL (GENOME-001/002 манифесты)
 * или помеченные структурные варианты семейств.
 */

final class WordCumWorlds
{
    /**
     * Миры: train (2 задачи — эволюция), valid (2 — независимая метрика),
     * final (1 — запечатан, однократная оценка).
     *
     * @return list<array{id: string, tasks: array<string, list<array{role: string, formula: string, source: string}>>}>
     */
    public static function worlds(): array
    {
        return [
            ['id' => 'W0-ens', 'tasks' => [
                'train' => [
                    ['role' => 'train-a', 'formula' => '((x0+x1)+(x0−x2))', 'source' => 'V011_ENSEMBLE'],
                    ['role' => 'train-b', 'formula' => '(((x0+x1)×x2)+x3)', 'source' => 'experiments-log'],
                ],
                'valid' => [
                    ['role' => 'valid-a', 'formula' => '(((x0−x1)×(x0+x1))+x2)', 'source' => 'семейство (структурный вариант, T-ens2 форма)'],
                    ['role' => 'valid-b', 'formula' => '((x0+x1)+(x3−x4))', 'source' => 'семейство (binding-вариант)'],
                ],
                'final' => [
                    ['role' => 'final', 'formula' => '(((x0+x1)+(x0−x2))×x4)', 'source' => 'семейство (обёртка, ЗАПЕЧАТАН)'],
                ],
            ]],
            ['id' => 'W1-kin', 'tasks' => [
                'train' => [
                    ['role' => 'train-a', 'formula' => '((x0×x3)+((x1×x4)+(x2×x5)))', 'source' => 'EXP040 kinetic'],
                    ['role' => 'train-b', 'formula' => '((x0×(x1×x1))+((x0×(x2×x2))+(x0×(x3×x3))))', 'source' => 'EXP040 kinetic dot'],
                ],
                'valid' => [
                    ['role' => 'valid-a', 'formula' => '((x0×x2)+((x1×x3)+(x4×x5)))', 'source' => 'семейство (binding-вариант)'],
                    ['role' => 'valid-b', 'formula' => '((x0×x1)+(x2×x3))', 'source' => 'семейство (структурный вариант)'],
                ],
                'final' => [
                    ['role' => 'final', 'formula' => '((x0×x4)+((x1×x5)+(x2×x3))+x0)', 'source' => 'семейство (обёртка, ЗАПЕЧАТАН)'],
                ],
            ]],
            ['id' => 'W2-comp', 'tasks' => [
                'train' => [
                    ['role' => 'train-a', 'formula' => '((x0−x2)+((x0+x1)/(x0−x1)))', 'source' => 'T-t2l (g2-закон развёрнутый, post-MOL-001)'],
                    ['role' => 'train-b', 'formula' => '((x0−x1)+((x2+x3)/(x2−x3)))', 'source' => 'семейство (binding-вариант)'],
                ],
                'valid' => [
                    ['role' => 'valid-a', 'formula' => '((x0−x1)+((x2×x3)/(x2+x3)))', 'source' => 'семейство (инверсия отношения)'],
                    ['role' => 'valid-b', 'formula' => '((x0×x1)+((x2+x3)/(x2−x3)))', 'source' => 'семейство (гибрид kin×ratio)'],
                ],
                'final' => [
                    ['role' => 'final', 'formula' => '((x0−x4)+((x1+x2)/(x1−x2))+(x3×x5))', 'source' => 'двухуровневая композиция (ЗАПЕЧАТАН)'],
                ],
            ]],
        ];
    }

    /** Данные задачи: reuse GENOME-EvolveLib::makeTask (6 колонок, resample null-строк). */
    public static function task(string $formula, int $seed): array
    {
        return GenomeEvolveLib::makeTask($formula, $seed);
    }
}
