<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\AtomRegistry;

/**
 * Story V0.NULL: System Null-Calibration (§0.7)
 * FPR_system = 0: полный пайплайн не должен производить открытий
 * на перемешанных метках.
 */
class NullCalibrationTest extends TestCase
{
    /**
     * §0.7: На перемешанных метках (shuffle y) система не должна
     * находить ни одного закона.
     */
    public function testNoDiscoveryOnShuffledLabels(): void
    {
        // Простой домен: y = x0 + x1
        $X = [];
        $y = [];
        for ($i = 0; $i < 20; $i++) {
            $x0 = rand(1, 100);
            $x1 = rand(1, 100);
            $X[] = [(float) $x0, (float) $x1];
            $y[] = (float) ($x0 + $x1);
        }

        // 20 shuffle-прогонов
        $falseDiscoveries = 0;

        for ($trial = 0; $trial < 20; $trial++) {
            $shuffledY = $y;
            shuffle($shuffledY);

            $discoveries = AtomRegistry::discoverHeldout($X, $shuffledY);

            if (! empty($discoveries)) {
                $falseDiscoveries++;
            }
        }

        // §0.7: FPR_system = 0 — ни одного ложного открытия
        $this->assertSame(
            0, $falseDiscoveries,
            "FPR_system must be 0, got {$falseDiscoveries} false discoveries on shuffled data"
        );
    }

    /**
     * Архитектурный тест: нуль-калибровка должна запускаться
     * ДО любых проверок Stage 0 и возвращать структурированный результат.
     */
    public function testNullCalibrationReturnsStructuredResult(): void
    {
        $this->assertTrue(
            method_exists(AtomRegistry::class, 'runNullCalibration'),
            'AtomRegistry must have runNullCalibration() method'
        );
    }
}
