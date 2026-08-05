<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Validation\NullCalibrator;

/**
 * Story V0: Runtime Null-Calibration
 *
 * NullCalibrator — shuffle-based FPR=0 floor.
 * N пермутаций y → лучший CV на шуме → ε_null.
 * Без этого CV≤0.01 — недостижимый порог на реальных данных.
 */
class NullCalibratorTest extends TestCase
{
    private Grammar $grammar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grammar = new Grammar();
        $this->grammar->restrictTo(array_keys(Grammar::BASE_OPS));
    }

    /**
     * На чистых случайных данных (X и y независимы) ε_null должен быть СУЩЕСТВЕННО больше нуля —
     * шум нельзя «обучить» до CV→0, но перебор ВСЕГДА найдёт лучшее-чем-ноль.
     */
    public function testCalibrateReturnsNonZeroEpsilonOnNoise(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 50; $i++) {
            $X[] = [mt_rand() / mt_getrandmax()];
            $y[] = mt_rand() / mt_getrandmax();
        }

        $epsilon = NullCalibrator::calibrate($X, $y, $this->grammar, 30);

        $this->assertGreaterThan(0.0, $epsilon, 'ε_null must be > 0 — noise always has non-zero CV');
        $this->assertLessThan(9.9, $epsilon, 'ε_null must be finite');
    }

    /**
     * На структурированных данных (y = f(x)) ε_null ДОЛЖЕН быть выше, чем CV
     * на НЕперемешанных данных — структура предсказуема, шум нет.
     */
    public function testCalibrateEpsilonExceedsCvOnStructuredData(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 50; $i++) {
            $x = $i * 0.1;
            $X[] = [$x];
            $y[] = 2.0 * $x;  // y = 2x — Search::find найдёт (x0×K2) точно
        }

        // CV на реальных данных (Search::find depth=1 с x×K2 находит 2x точно)
        [$found, $cvReal, $formula] = Search::find($X, $y, $this->grammar, 2);
        $this->assertTrue($found, 'Search must find exact law on structured data');
        $this->assertLessThan(0.001, $cvReal, 'CV on structured data must be ~0');

        // ε_null на перемешанных данных (закон должен быть ВЫШЕ чем на структуре)
        $epsilon = NullCalibrator::calibrate($X, $y, $this->grammar, 30);
        $this->assertGreaterThan($cvReal, $epsilon, 'ε_null on shuffled must exceed CV on structured');
        $this->assertLessThan(10.0, $epsilon, 'ε_null must be finite (not OOM/NaN)');
    }

    /**
     * 99-й перцентиль при N=10 стабилен: два вызова на одних данных
     * дают ~одинаковый результат (shuffle случаен, но экстремальный перцентиль устойчив).
     */
    public function testCalibratePercentileIsStable(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 20; $i++) {
            $X[] = [($i % 5) * 0.5];
            $y[] = sin($i * 0.3);
        }

        $eps1 = NullCalibrator::calibrate($X, $y, $this->grammar, 20);
        $eps2 = NullCalibrator::calibrate($X, $y, $this->grammar, 20);

        $this->assertGreaterThan(0.0, $eps1);
        $this->assertGreaterThan(0.0, $eps2);
        // Два вызова с N=20 должны давать близкие значения (99-й перцентиль устойчив)
        $this->assertEqualsWithDelta($eps1, $eps2, 1.0, 'ε_null must be stable across calibrations');
    }

    /**
     * Пустые данные → fallback ε = 0.15 (05.08: 0.01 делал систему слепой).
     */
    public function testCalibrateEmptyReturnsFallback(): void
    {
        $epsilon = NullCalibrator::calibrate([], [], $this->grammar);
        $this->assertEqualsWithDelta(0.15, $epsilon, 0.001, 'Empty data must return hardcoded fallback');
    }
}
