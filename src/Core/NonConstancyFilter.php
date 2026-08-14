<?php
declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * NON-CONSTANCY (10.08, ЭКСП-026/MOEX; refactor 11.08):
 * константные псевдозаконы (K1≡1.0, K2≡2.0 + фича) проходят CV через
 * shift-нормализацию: знакопеременный y → shift=min(y)−1 → ratio
 * сглаживается. Формула, не зависящая от ПОРЯДКА y, имеет t/null≈1.0.
 *
 * NULL-ФИЛЬТР (вариант B стори): CV формулы на ЦИКЛИЧЕСКИ сдвинутом y
 * (детерминированные взаимно-простые шаги, mt не трогаем!) — если сигнал
 * НЕ лучше шума (t >= nullCv × ratio) — REJECT (нет информации).
 * Медиана 5 перестановок: одна реализация флуктуирует ±0.03.
 *
 * Разделение классов: константа — валидный ФИЗИЧЕСКИЙ закон грамматики,
 * но НЕ рыночный эффект. Фильтр отделяет первое от второго.
 */
final class NonConstancyFilter
{
    public const DEFAULT_RATIO = 0.55;
    public const NULL_PERMUTATIONS = 5;

    /**
     * Медианный null-CV: CV формулы на 5 циклических перестановках y_test.
     * Шаги взаимно-простые с nTest (иначе повторы → null искажён, флак 10.08).
     *
     * @param array<int,array<int,float>> $X_test
     * @param array<int,float> $y_test
     * @param array<int,array<int,float>>|null $X_train — для R-статистик (фикс. по train)
     * @param array<string,mixed> $extraOps
     * @param array<string,mixed> $opDefs
     */
    public static function nullMedianCv(
        string $name,
        array $X_test,
        array $y_test,
        float $trainStd,
        int $n,
        ?array $colLabels,
        ?array $X_train,
        array $extraOps,
        array $opDefs
    ): float {
        $nTest = count($y_test);
        if ($nTest < 2) {
            return 9.99;
        }
        $nulls = [];
        for ($r = 0; $r < self::NULL_PERMUTATIONS; $r++) {
            $step = 2 + $r;
            while ($step < $nTest && $nTest % $step === 0) {
                $step++;
            }
            if ($step >= $nTest) {
                $step = $nTest - 1;
            }
            $nullY = [];
            for ($i = 0; $i < $nTest; $i++) {
                $nullY[] = $y_test[($i * $step) % $nTest];
            }
            $nulls[] = Search::testCv(
                $name, $X_test, $nullY, $trainStd, $n,
                $colLabels, $X_train, $extraOps, $opDefs
            );
        }
        sort($nulls);
        return $nulls[(int) (count($nulls) / 2)]; // медиана
    }

    /**
     * Относительный критерий («обратный Парето»): сигнал должен держать
     * ≤ratio (0.55) от уровня шума — минимум 45% диссипации.
     */
    public static function rejects(float $t, float $nullCv, float $ratio = self::DEFAULT_RATIO): bool
    {
        return $t >= $nullCv * $ratio;
    }
}
