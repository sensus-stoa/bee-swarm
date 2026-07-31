<?php
declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * TextAtomCrossPairer — превращает одиночные значения текстовых атомов в X/y пары.
 *
 * Проблема: forager извлекает отдельные значения метрик (GI=7.2, DQ=6.0...).
 * Каждое — одна колонка. CV→0 требует ≥2 колонок (features + target) и ≥10 строк.
 *
 * Решение: cross-pair — для каждого файла собрать ВСЕ текстовые атомы,
 * и для каждой пары (A→B) создать задачу: X=[A], y=[B].
 */
class TextAtomCrossPairer
{
    /**
     * @param array<string, list<float>> $atoms atom_name → [values...]
     * @param string $domain label for generated tasks
     * @return list<array{name: string, data: list<list<float>>, domain: string}>
     */
    public static function crossPair(array $atoms, string $domain): array
    {
        // Нужно минимум 3 точки с ≥2 разными атомами
        if (count($atoms) < 2) {
            return [];
        }

        $tasks = [];
        $names = array_keys($atoms);

        foreach ($names as $featureName) {
            foreach ($names as $targetName) {
                if ($featureName === $targetName) continue;

                $rows = self::alignRows(
                    $atoms[$featureName],
                    $atoms[$targetName]
                );

                if (count($rows) < 3) continue;

                $tasks[] = [
                    'name' => "txt_pair_{$featureName}_to_{$targetName}",
                    'data' => $rows,
                    'domain' => $domain,
                ];
            }
        }

        return $tasks;
    }

    /**
     * Выровнять два списка значений в пары [feature, target].
     *
     * @param list<float> $features
     * @param list<float> $targets
     * @return list<list<float>>
     */
    private static function alignRows(array $features, array $targets): array
    {
        $n = min(count($features), count($targets));
        if ($n < 3) return [];

        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [(float) $features[$i], (float) $targets[$i]];
        }
        return $rows;
    }
}
