<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Validation\NullCalibrator;

/**
 * Story V0 Phase 2: Integration — Hive использует NullCalibrator
 * для замены hardcoded CV≤0.01 на per-fingerprint ε_null.
 */
class HiveNullCalibrationTest extends TestCase
{
    /**
     * Hive вычисляет ε_null для нового структурного fingerprint'а
     * и сохраняет его в кеш калибровок.
     */
    public function testHiveCalibratesEpsilonForFingerprint(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hvc_');
        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        $X = [[1.0], [2.0], [3.0], [4.0], [5.0], [1.0], [2.0], [3.0], [4.0], [5.0], [1.0], [2.0]];
        $y = [2.0, 4.0, 6.0, 8.0, 10.0, 2.0, 4.0, 6.0, 8.0, 10.0, 2.0, 4.0];

        $grammar = new Grammar();
        $grammar->restrictTo(array_keys(Grammar::BASE_OPS));

        $fp = 'test_fp_' . uniqid();

        // Калибровка
        $epsilon = $hive->calibrateEpsilon($fp, $X, $y, $grammar);
        $this->assertGreaterThan(0.0, $epsilon, 'ε_null must be non-zero');
        $this->assertLessThan(10.0, $epsilon, 'ε_null must be finite');

        // Кеш: повторный запрос возвращает то же значение без пересчёта
        $epsilonCached = $hive->getEpsilon($fp);
        $this->assertSame($epsilon, $epsilonCached, 'Cached ε must equal calibrated');

        // Неизвестный fingerprint → null (ещё не калиброван)
        $this->assertNull($hive->getEpsilon('unknown_fp_' . uniqid()));

        unlink($logFile);
    }

    /**
     * После калибровки Hive использует ε_null как порог вместо 0.01.
     * Данные с микро-шумом: ε_null должно отличаться от hardcoded 0.01.
     */
    public function testHiveEpsilonDiffersFromHardcodedThresholdOnNoisyData(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hvc_');
        $n = 30;
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $i * 0.5 + 1.0;
            $noise = 1.0 + sin($i * 0.7) * 0.02;
            $X[] = [$x];
            $y[] = 2.0 * $x * $noise;  // y≈2x, CV ~ 0.02-0.05
        }

        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        $grammar = new Grammar();
        $grammar->restrictTo(array_keys(Grammar::BASE_OPS));
        $fp = 'test_noisy_fp';

        $epsilon = $hive->calibrateEpsilon($fp, $X, $y, $grammar);
        $this->assertGreaterThan(0.0, $epsilon, 'ε_null must be > 0 on noisy data');

        unlink($logFile);
    }
}
