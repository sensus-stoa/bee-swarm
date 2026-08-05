<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\PlateauDetector;

/**
 * Story 02: Plateau Honesty (HONEST_CRITERIA §1.5)
 *
 * PlateauDetector — счётчик тиков без открытий.
 * T тиков без открытий → PLATEAU (sleep 10s, compose off).
 * Новое открытие → выход из PLATEAU, сброс счётчика.
 */
class PlateauDetectorTest extends TestCase
{
    private const T = 50;  // threshold

    private const BASE_SLEEP_US = 200_000;

    private const PLATEAU_SLEEP_US = 10_000_000;

    private function tickTimes(PlateauDetector $d, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $d->tick(false);
        }
    }

    private function enterDeepPlateau(PlateauDetector $d): void
    {
        $this->tickTimes($d, self::T + 10);
    }

    /**
     * Ниже порога — не плато, обычный sleep
     */
    public function testNotPlateauBelowThreshold(): void
    {
        $d = new PlateauDetector(self::T);
        $this->tickTimes($d, self::T - 1);

        $this->assertFalse($d->isPlateau(), 'T-1 ticks: not plateau');
        $this->assertSame(self::BASE_SLEEP_US, $d->getSleepUs());
        $this->assertSame(self::T - 1, $d->getConsecutiveNoDiscovery());
    }

    /**
     * Ровно на пороге — ещё не плато
     */
    public function testAtThresholdNotPlateau(): void
    {
        $d = new PlateauDetector(self::T);
        $this->tickTimes($d, self::T - 1);

        $this->assertFalse($d->isPlateau(), 'T-1 ticks: still not plateau');
        $this->assertSame(self::BASE_SLEEP_US, $d->getSleepUs());
    }

    /**
     * На пороге — плато (>= threshold)
     */
    public function testPlateauAtThreshold(): void
    {
        $d = new PlateauDetector(self::T);
        $this->tickTimes($d, self::T);

        $this->assertTrue($d->isPlateau(), 'T ticks: plateau entered');
    }

    /**
     * Выше порога — плато, длинный sleep
     */
    public function testPlateauAfterThreshold(): void
    {
        $d = new PlateauDetector(self::T);
        $this->tickTimes($d, self::T + 1);

        $this->assertTrue($d->isPlateau(), 'T+1 ticks: plateau');
        $this->assertSame(self::PLATEAU_SLEEP_US, $d->getSleepUs());
    }

    /**
     * Открытие сбрасывает счётчик и выходит из плато
     */
    public function testDiscoveryResetsCounterAndExitsPlateau(): void
    {
        $d = new PlateauDetector(self::T);
        $this->enterDeepPlateau($d);
        $this->assertTrue($d->isPlateau());

        $d->tick(true);

        $this->assertFalse($d->isPlateau(), 'Discovery exits plateau');
        $this->assertSame(0, $d->getConsecutiveNoDiscovery());
        $this->assertSame(self::BASE_SLEEP_US, $d->getSleepUs());
    }

    /**
     * Несколько открытий подряд — счётчик на 0, потом растёт
     */
    public function testMultipleDiscoveriesKeepCounterZero(): void
    {
        $d = new PlateauDetector(self::T);

        $d->tick(true);
        $this->assertSame(0, $d->getConsecutiveNoDiscovery());

        $d->tick(true);
        $this->assertSame(0, $d->getConsecutiveNoDiscovery());

        $d->tick(false);
        $this->assertSame(1, $d->getConsecutiveNoDiscovery());

        $d->tick(false);
        $this->assertSame(2, $d->getConsecutiveNoDiscovery());
    }

    /**
     * justEnteredPlateau — только на первом тике после входа
     */
    public function testPlateauEnteredEventFiresOnce(): void
    {
        $d = new PlateauDetector(self::T);
        $this->tickTimes($d, self::T);

        $this->assertTrue($d->justEnteredPlateau(), 'T tick: just entered');

        $d->tick(false);
        $this->assertFalse($d->justEnteredPlateau(), 'T+2 tick: already in plateau');
    }

    /**
     * Compose работает только НЕ на плато
     */
    public function testShouldRunComposeWhenNotPlateau(): void
    {
        $d = new PlateauDetector(self::T);
        $this->assertTrue($d->shouldRunCompose(), 'Below threshold: compose enabled');
    }

    /**
     * Compose отключается на плато
     */
    public function testShouldNotRunComposeOnPlateau(): void
    {
        $d = new PlateauDetector(self::T);
        $this->tickTimes($d, self::T + 1);

        $this->assertFalse($d->shouldRunCompose(), 'On plateau: compose disabled');
    }

    /**
     * Compose снова включается после выхода из плато
     */
    public function testComposeReenablesAfterPlateauExit(): void
    {
        $d = new PlateauDetector(self::T);
        $this->enterDeepPlateau($d);
        $this->assertFalse($d->shouldRunCompose());

        $d->tick(true);
        $this->assertTrue($d->shouldRunCompose(), 'After discovery: compose re-enabled');
    }

    /**
     * wakeup() — внешнее событие (forager) выводит из плато
     */
    public function testWakeupExitsPlateau(): void
    {
        $d = new PlateauDetector(self::T);
        $this->enterDeepPlateau($d);
        $this->assertTrue($d->isPlateau());

        $d->wakeup(); // forager принёс новые задачи

        $this->assertFalse($d->isPlateau(), 'Wakeup exits plateau');
        $this->assertSame(0, $d->getConsecutiveNoDiscovery());
        $this->assertSame(self::BASE_SLEEP_US, $d->getSleepUs());
        $this->assertTrue($d->shouldRunCompose(), 'Compose re-enabled after wakeup');
    }

    public function testJustExitedPlateauFiresOnce(): void
    {
        // CONCERNS (deleg_294903fa): edge-trigger — в discovery-rich фазе
        // (consecutive<=1) событие выхода должно сработать РОВНО ОДИН раз,
        // а не каждый тик (было: 8 событий на один выход, липкий wasPlateau).
        $d = new PlateauDetector(3, 0);
        // 3 тика без открытий → плато
        $d->tick(false); $d->tick(false); $d->tick(false);
        $this->assertTrue($d->isPlateau());
        // Выход: открытие + discovery-rich фаза (открытия каждый тик)
        $d->tick(true);   // consecutive -> 0
        $this->assertTrue($d->justExitedPlateau(), 'exit must fire once');
        $this->assertFalse($d->justExitedPlateau(), 'second call must NOT fire (edge-trigger)');
        // Discovery-rich фаза: открытия каждый тик — событий быть не должно
        for ($i = 0; $i < 5; $i++) {
            $d->tick(true);
            $this->assertFalse($d->justExitedPlateau(), "no fire on discovery-rich tick {$i}");
        }
    }
}
