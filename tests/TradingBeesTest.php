<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Trading\TradingHive;
use PHPUnit\Framework\TestCase;

/**
 * FIN-005 TRADING-BEES (14.08, v4): пчёлы торгуют, без законов.
 * Селекция: итоговый OOS-PnL > 0 → выживает; убыток 3-6 поколений → смерть.
 * Null = ДИАГНОСТИКА: на чистом шуме случайные пчёлы вымирают (~0-5%).
 * Позитивный контроль: встроенный BNB-эффект находится ТОРГОВЛЕЙ.
 */
class TradingBeesTest extends TestCase
{
    private function noiseRet(int $n, int $seed): array
    {
        srand($seed);
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            $r[] = (rand() / getrandmax() - 0.5) * 0.06; // ±3%, знакопеременный
        }
        return $r;
    }

    /** RED: на чистом шуме отбор НЕ обогащает (диагностика давления) */
    public function testNoiseExtinction(): void
    {
        $windows = [
            $this->noiseRet(2000, 1),
            $this->noiseRet(2000, 2),
        ];
        $hive = new TradingHive(popSize: 100);
        $res = $hive->evolve($windows, generations: 40);
        $this->assertLessThanOrEqual(120.0, $res['total_energy'],
            'на чистом шуме энергия не создаётся системно (≤ старт + 20% шума): '
            . round($res['total_energy'], 2));
    }

    /** RED: встроенный BNB-эффект рой находит ТОРГУЯ (OOS-PnL > 0) */
    public function testEmbeddedEffectFound(): void
    {
        // train: шум; test: шум + BNB-подобный эффект (r5-перегрев → 20 дней вниз)
        srand(7);
        $train = $this->noiseRet(1500, 3);
        // r5 (лаг: прошлые 5 дней) по train → порог Q80
        $r5 = [];
        for ($i = 5; $i < 1500; $i++) {
            $r5[] = array_sum(array_slice($train, $i - 5, 5)) / 5;
        }
        sort($r5);
        $q80 = $r5[(int) (count($r5) * 0.8)];
        // test-ряд: шум + BNB-эффект с РЕФРАКТЕРОМ: при r5>=Q80 —
        // ровно 20 дней сдвига -0.4%/день (даже если r5 упал)
        $test = [];
        $shiftActive = 0;
        for ($i = 0; $i < 600; $i++) {
            $v = (rand() / getrandmax() - 0.5) * 0.06;
            if ($shiftActive > 0) {
                $v -= 0.02;
                $shiftActive--;
            } elseif ($i >= 5
                && array_sum(array_slice($test, $i - 5, 5)) / 5 >= $q80) {
                $shiftActive = 20;
            }
            $test[] = $v;
        }
        $hive = new TradingHive(popSize: 100);
        $res = $hive->evolve([$train, $test], generations: 40);
        $best = 0.0;
        $bestG = null;
        foreach ($res['survivors'] as $bee) {
            if ($bee['oos_pnl'] > $best) {
                $best = $bee['oos_pnl'];
                $bestG = $bee['genome'];
            }
        }
        $this->assertGreaterThan(0.0, $best,
            'встроенный эффект должен быть найден торговлей: лучший OOS-PnL > 0');
        // СТРОГОСТЬ: эффект найден СИЛЬНО (закон сохранения энергии не даёт
        // суммарной энергии расти — честная метрика: чистый PnL лучшей пчелы)
        $bestClean = 0.0;
        foreach ($res['survivors'] as $b) {
            $bestClean = max($bestClean, $b['clean_pnl']);
        }
        $this->assertGreaterThan(1.0, $bestClean,
            'эффект должен дать чистый PnL > 1.0 у лучшей пчелы (best_clean='
            . round($bestClean, 2) . ')');
    }
}
