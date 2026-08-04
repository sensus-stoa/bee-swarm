<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Core\Grammar;

/**
 * Story S1.6-GRADIENT: Signal Gradient Reward
 *
 * Три зоны: ОТКРЫТИЕ (+2.0) / СИГНАЛ (+0.5) / ШУМ (0).
 */
class SignalGradientRewardTest extends TestCase
{
    /**
     * RED: CV < ε → rewardDiscovery +2.0 (уже работает).
     * Проверяем что rewardDiscovery существует и меняет энергию.
     */
    public function testDiscoveryFullReward(): void
    {
        $bee = new Bee(Grammar::baseOpNames(), 10.0);
        $before = $bee->energy();

        $bee->rewardDiscovery();

        $this->assertEqualsWithDelta(12.0, $bee->energy(), 0.001,
            'rewardDiscovery must give +2.0 energy');
    }

    /**
     * RED: rewardSignal(+0.5) — НЕ существует.
     *
     * Predicted: Error — метод не определён.
     */
    public function testSignalRewardMethod(): void
    {
        $bee = new Bee(Grammar::baseOpNames(), 10.0);

        // Метод должен существовать
        $this->assertTrue(method_exists($bee, 'rewardSignal'),
            'Bee must have rewardSignal() method');

        $before = $bee->energy();
        $bee->rewardSignal();
        $this->assertEqualsWithDelta(10.5, $bee->energy(), 0.001,
            'rewardSignal must give +0.5 energy');
    }

    /**
     * RED: null_floor доступен через NullCalibrator.
     */
    public function testNullFloorAvailable(): void
    {
        $this->assertTrue(method_exists(\BeeSwarm\Validation\NullCalibrator::class, 'getNullFloor'),
            'NullCalibrator must expose null_floor for signal zone');
    }
}
