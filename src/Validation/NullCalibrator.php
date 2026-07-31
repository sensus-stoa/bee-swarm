<?php

declare(strict_types=1);

namespace BeeSwarm\Validation;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * NullCalibrator — shuffle-based null-distribution calibration.
 *
 * Story V0: Runtime Null-Calibration.
 * Определяет пер-датасет статистический порог ε_null (FPR=0 floor)
 * через N пермутаций целевого вектора y.
 *
 * Открытие = CV_train < ε_null И CV_holdout < ε_null
 * (заменяет hardcoded CV≤0.01).
 */
class NullCalibrator
{
    private const DEFAULT_PERMS = 100;

    private const PERCENTILE = 0.99;

    private const FALLBACK_EPSILON = 0.01;

    /**
     * Калибровка порога для конкретной задачи.
     *
     * @param array $X матрица признаков (n × m)
     * @param array $y целевой вектор (длины n)
     * @param Grammar $grammar грамматика (должна быть ограничена до BASE_OPS для предотвращения OOM)
     * @param int $nPerms число перемешиваний (по умолчанию 100)
     * @return float ε_null — 99-й перцентиль лучшего CV по пермутациям
     */
    public static function calibrate(array $X, array $y, Grammar $grammar, int $nPerms = self::DEFAULT_PERMS): float
    {
        if (empty($X) || empty($y) || count($y) < 2) {
            return self::FALLBACK_EPSILON;
        }

        $bestCvs = [];
        $nActual = 0;

        for ($i = 0; $i < $nPerms; $i++) {
            $shuffled = $y;
            shuffle($shuffled);

            [$found, $cv] = Search::find($X, $shuffled, $grammar, 1);  // depth=1: shuffle-данные не содержат структуры

            // Исключаем NaN и 9.99 (ошибка)
            if (is_nan($cv) || $cv >= 9.0) {
                continue;
            }

            $bestCvs[] = $cv;
            $nActual++;
        }

        if ($nActual < 2) {
            return self::FALLBACK_EPSILON;
        }

        sort($bestCvs);
        $idx = (int) min((int) ceil($nActual * self::PERCENTILE) - 1, $nActual - 1);
        $idx = max(0, $idx);

        return $bestCvs[$idx];
    }
}
