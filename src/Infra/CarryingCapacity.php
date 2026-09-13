<?php

declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * §2.6 Spec Addendum: implicit carrying capacity.
 *
 * Критерий: N_max / N_median ≤ 3 за период наблюдения. Экстинкции (N=0)
 * исключаются из расчёта (SEED_SPAWN-восстановления — те же N=0-срезы).
 * >3 → FAIL: шумовая флуктуация или взрывной рост, популяция нестабильна.
 *
 * Чистый анализатор: без I/O, переиспользуется verify_1_6 и юнит-тестами.
 */
final class CarryingCapacity
{
    /**
     * Минимум наблюдений после фильтрации, иначе — inconclusive.
     */
    public const MIN_OBSERVATIONS = 2;

    /**
     * Граница нестабильности: ratio выше — FAIL.
     */
    public const RATIO_LIMIT = 3.0;

    /**
     * @param list<int> $popSizes
     * @return array{pass: bool, max: int, median: float, ratio: float, excluded: int, inconclusive: bool, reason?: string}
     */
    public static function analyze(array $popSizes): array
    {
        $filtered = array_values(array_filter(
            $popSizes,
            static fn (int $n): bool => $n > 0
        ));
        $excluded = count($popSizes) - count($filtered);

        if (count($filtered) < self::MIN_OBSERVATIONS) {
            return self::inconclusive(max($filtered ?: [0]), $excluded);
        }

        $median = self::median($filtered);
        $max = max($filtered);
        $ratio = $max / $median;

        return [
            'pass' => $ratio <= self::RATIO_LIMIT,
            'inconclusive' => false,
            'max' => $max,
            'median' => $median,
            'ratio' => $ratio,
            'excluded' => $excluded,
        ];
    }

    /**
     * @return array{pass: bool, max: int, median: float, ratio: float, excluded: int, inconclusive: bool, reason: string}
     */
    private static function inconclusive(int $max, int $excluded): array
    {
        return [
            'pass' => false,
            'max' => $max,
            'median' => 0.0,
            'ratio' => 0.0,
            'excluded' => $excluded,
            'inconclusive' => true,
            'reason' => 'insufficient observations after extinction filter',
        ];
    }

    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
