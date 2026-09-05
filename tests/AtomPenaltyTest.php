<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\AtomPenalty;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 (story DISSIPATION-LOOP): atom-penalty (§2.5.6).
 *
 * Атом в >= 3 фальсифицированных законах → penalty weight (мягкий: вес, не
 * удаление). Реабилитация: вход в живой закон декрементирует штраф.
 * Контракт: штраф мягкий, реабилитация обязательна (анти-осцилляция).
 */
final class AtomPenaltyTest extends TestCase
{
    private AtomPenalty $penalty;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->penalty = new AtomPenalty(falsifyThreshold: 3);
    }

    protected function tearDown(): void
    {
        Database::setPath(':memory:');
        Database::reset();
    }

    /** До порога фальсификаций — без штрафа (множитель 1). */
    public function testBelowThresholdNoPenalty(): void
    {
        $this->penalty->falsify('sqrt');
        $this->penalty->falsify('sqrt');
        self::assertSame(2, $this->penalty->penaltyCount('sqrt'));
        self::assertFalse($this->penalty->isPenalized('sqrt'));
        self::assertEqualsWithDelta(1.0, $this->penalty->weightMultiplier('sqrt'), 0.0001);
    }

    /** С порога — множитель падает (мягкое затухание). */
    public function testAtThresholdPenalized(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->penalty->falsify('sqrt');
        }
        self::assertTrue($this->penalty->isPenalized('sqrt'));
        // Ф5b: isPenalized и multiplier согласованы — порог уже даёт первый шаг затухания
        self::assertEqualsWithDelta(0.5, $this->penalty->weightMultiplier('sqrt'), 0.0001);
    }

    /** Graduated: чем больше фальсификаций, тем ниже множитель. */
    public function testGraduatedDecay(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->penalty->falsify('sqrt');
        }
        // count=5, threshold=3: множитель = 1/(1 + 5-3+1) = 1/4 (порог = первый шаг)
        self::assertEqualsWithDelta(0.25, $this->penalty->weightMultiplier('sqrt'), 0.0001);
        // при пороге (count=3): 1/(1+1) = 0.5 — isPenalized и multiplier согласованы
        $p3 = new AtomPenalty(falsifyThreshold: 3);
        for ($i = 0; $i < 3; $i++) { $p3->falsify('min'); }
        self::assertEqualsWithDelta(0.5, $p3->weightMultiplier('min'), 0.0001);
    }

    /** Реабилитация: успех декрементирует штраф, не ниже нуля. */
    public function testRehabilitationDecrements(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->penalty->falsify('sqrt');
        }
        $this->penalty->rehabilitate('sqrt');
        self::assertSame(3, $this->penalty->penaltyCount('sqrt'));

        // до нуля и ниже — не падает
        for ($i = 0; $i < 10; $i++) {
            $this->penalty->rehabilitate('sqrt');
        }
        self::assertSame(0, $this->penalty->penaltyCount('sqrt'));
        self::assertEqualsWithDelta(1.0, $this->penalty->weightMultiplier('sqrt'), 0.0001);
    }

    /** Cap на penalty_count (анти-бесконечный штраф). */
    public function testPenaltyCapped(): void
    {
        $penalty = new AtomPenalty(falsifyThreshold: 3, maxPenalty: 50);
        for ($i = 0; $i < 200; $i++) {
            $penalty->falsify('sqrt');
        }
        self::assertSame(50, $penalty->penaltyCount('sqrt'));
    }

    /** Реабилитация несуществующего атома не создаёт отрицательных значений. */
    public function testRehabilitateUnknownAtom(): void
    {
        $this->penalty->rehabilitate('unknown_op');
        self::assertSame(0, $this->penalty->penaltyCount('unknown_op'));
    }
}
