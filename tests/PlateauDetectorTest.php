<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\PlateauDetector;

/**
 * Story 02: Plateau Honesty (HONEST_CRITERIA §1.5)
 *
 * PlateauDetector — счётчик тиков без открытий.
 * 50 тиков без открытий → PLATEAU (sleep 10s, compose off).
 * Новое открытие → выход из PLATEAU, сброс счётчика.
 *
 * @group disabled
 */
class PlateauDetectorTest extends TestCase
{
    private const THRESHOLD = 50;
    private const BASE_SLEEP_US = 200_000;
    private const PLATEAU_SLEEP_US = 10_000_000;

    /** Ниже порога — не плато, обычный sleep */
    public function test_not_plateau_below_threshold(): void
    {
        $d = new PlateauDetector(self::THRESHOLD);

        for ($i = 0; $i < 49; $i++) {
            $d->tick(false);
        }

        $this->assertFalse($d->isPlateau(), '49 ticks: not plateau');
        $this->assertSame(self::BASE_SLEEP_US, $d->getSleepUs());
        $this->assertSame(49, $d->getConsecutiveNoDiscovery());
    }

    /** Ровно на пороге — ещё не плато (50-й тик без открытия) */
    public function test_at_threshold_not_plateau(): void
    {
        $d = new PlateauDetector(self::THRESHOLD);

        for ($i = 0; $i < 50; $i++) {
            $d->tick(false);
        }

        $this->assertFalse($d->isPlateau(), '50th tick: still not plateau (> not >=)');
        $this->assertSame(self::BASE_SLEEP_US, $d->getSleepUs());
    }

    /** Выше порога — плато, длинный sleep */
    public function test_plateau_after_threshold(): void
    {
        $d = new PlateauDetector(self::THRESHOLD);

        for ($i = 0; $i < 51; $i++) {
            $d->tick(false);
        }

        $this->assertTrue($d->isPlateau(), '51 ticks: plateau');
        $this->assertSame(self::PLATEAU_SLEEP_US, $d->getSleepUs());
    }

    /** Открытие сбрасывает счётчик и выходит из плато */
    public function test_discovery_resets_counter_and_exits_plateau(): void
    {
        $d = new PlateauDetector(self::THRESHOLD);

        // Загоняем в плато
        for ($i = 0; $i < 60; $i++) {
            $d->tick(false);
        }
        $this->assertTrue($d->isPlateau());

        // Открытие!
        $d->tick(true);

        $this->assertFalse($d->isPlateau(), 'Discovery exits plateau');
        $this->assertSame(0, $d->getConsecutiveNoDiscovery());
        $this->assertSame(self::BASE_SLEEP_US, $d->getSleepUs());
    }

    /** Несколько открытий подряд — счётчик на 0 */
    public function test_multiple_discoveries_keep_counter_zero(): void
    {
        $d = new PlateauDetector(self::THRESHOLD);

        $d->tick(true);
        $d->tick(true);
        $d->tick(false);
        $d->tick(true);

        $this->assertSame(1, $d->getConsecutiveNoDiscovery());
    }

    /** PLATEAU_ONLY при 51-м тике — ровно один раз */
    public function test_plateau_entered_event_fires_once(): void
    {
        $d = new PlateauDetector(self::THRESHOLD);

        for ($i = 0; $i < 51; $i++) {
            $d->tick(false);
        }
        $this->assertTrue($d->justEnteredPlateau(), '51st tick: just entered');

        $d->tick(false);
        $this->assertFalse($d->justEnteredPlateau(), '52nd tick: already in plateau');
    }
}
