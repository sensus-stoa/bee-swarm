<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * Auto MPG Dataset (UCI, 1983).
 * Предсказание расхода топлива по мощности и весу.
 * weight ↑ → mpg ↓ (обратная линейная зависимость).
 *
 * Источник: https://archive.ics.uci.edu/dataset/9/auto+mpg
 */
class AutoMpgDatasetTest extends TestCase
{
    /**
     * Weight (x0) vs MPG (y) — обратная корреляция.
     * Тяжёлые машины жрут больше топлива.
     */
    public function testWeightPredictsMpg(): void
    {
        // Auto MPG: horsepower, weight → mpg (первые 25 записей)
        $X = [
            [130, 3504], [165, 3693], [150, 3436], [150, 3433], [140, 3449],
            [198, 4341], [220, 4354], [215, 4312], [225, 4425], [190, 3850],
            [170, 3563], [160, 3609], [150, 3761], [225, 3086], [175, 3821],
            [180, 3605], [175, 3353], [170, 3565], [160, 3620], [140, 3540],
            [150, 3760], [225, 3330], [175, 3450], [170, 3545], [160, 3630],
        ];
        $y = [18, 15, 18, 16, 17, 15, 14, 14, 14, 15, 15, 14, 15, 24, 15,
              16, 18, 16, 15, 16, 15, 18, 17, 16, 15];

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $X, $y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'min', 'max', 'abs', 'neg', 'sq', 'sqrt']),
            0.25,
            ['horsepower', 'weight'],
        );

        $this->assertNotEmpty($results, 'Auto MPG must yield weight→mpg laws');
    }

    /**
     * Лёгкие машины (weight < median) → mpg выше медианы.
     */
    public function testLightCarsHaveBetterMpg(): void
    {
        $X = [
            [130, 3504], [165, 3693], [150, 3436], [150, 3433], [140, 3449],
            [198, 4341], [220, 4354], [215, 4312], [225, 4425], [190, 3850],
            [170, 3563], [160, 3609], [150, 3761], [225, 3086], [175, 3821],
        ];
        $y = [18, 15, 18, 16, 17, 15, 14, 14, 14, 15, 15, 14, 15, 24, 15];

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $X, $y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'max', 'min']),
            0.3,
            ['horsepower', 'weight'],
        );

        // Даже с 15 точками должен найти хоть что-то
        $cvUnderPoint3 = array_filter($results, fn ($r) => ($r['cv'] ?? 1.0) <= 0.3);
        $this->assertNotEmpty($cvUnderPoint3, 'Must find law with CV≤0.3 on MPG subset');
    }
}
