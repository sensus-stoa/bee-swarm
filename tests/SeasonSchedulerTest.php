<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\SeasonScheduler;

/**
 * EXP-039: SeasonScheduler — фазовые пропорции, ролл операций, Governor.
 *
 * Пропорции — из PREREG §2 (источник истины). Тесты ДО кода (канон TDD).
 * Покрытие:
 *  - таблица пропорций 8 фаз + D-агрегация 4 кварталов
 *  - ролл: доли ≈ частоты на большом N; детерминизм по seed
 *  - phase(): floor(tick/2500) mod 8, D: floor(tick/5000) mod 4
 *  - Governor: multi-attempt top-up, сходимость к бюджету ±1%
 *  - pop-ledger: injections = ΔΣE + dissipation (правило сохранения)
 */
final class SeasonSchedulerTest extends TestCase
{
    public function testPhaseBoundsAndCount(): void
    {
        $s = new SeasonScheduler('B', 12345);
        $this->assertSame(0, $s->phase(0));
        $this->assertSame(0, $s->phase(2499));
        $this->assertSame(1, $s->phase(2500));
        $this->assertSame(7, $s->phase(19999));
        // второй год: фазы повторяются (mod 8)
        $this->assertSame(0, $s->phase(20000));
        $this->assertSame(3, $s->phase(20000 + 3 * 2500));
    }

    public function testPhaseDQuarterGranularity(): void
    {
        $s = new SeasonScheduler('D', 12345);
        $this->assertSame(0, $s->phase(0));
        $this->assertSame(0, $s->phase(4999));
        $this->assertSame(1, $s->phase(5000));
        $this->assertSame(3, $s->phase(19500));
        $this->assertSame(0, $s->phase(20000)); // mod 4
    }

    public function testPhaseAUniform(): void
    {
        $s = new SeasonScheduler('A', 12345);
        // uniform — фаз нет, ролл всегда explore
        $this->assertSame('explore', $s->roll(0));
        $this->assertSame('explore', $s->roll(7777));
        $this->assertSame('explore', $s->roll(39999));
    }

    public function testProportionsMatchPrereg(): void
    {
        $p = SeasonScheduler::PHASES;
        $this->assertCount(8, $p);
        // PREREG §2: значения из prereg, не из хендоффа
        $this->assertSame(['explore' => 0.2, 'dream' => 0.4, 'verify' => 0.2, 'rest' => 0.2], $p[0]);
        $this->assertSame(['explore' => 0.6, 'dream' => 0.1, 'verify' => 0.2, 'rest' => 0.1], $p[1]);
        $this->assertSame(['explore' => 0.4, 'dream' => 0.0, 'verify' => 0.4, 'rest' => 0.2], $p[2]);
        $this->assertSame(['explore' => 0.3, 'dream' => 0.0, 'verify' => 0.5, 'rest' => 0.2], $p[3]);
        $this->assertSame(['explore' => 0.1, 'dream' => 0.0, 'verify' => 0.7, 'rest' => 0.2], $p[4]);
        // фазы 5/6: autophagy-доля исполняется как rest (аддендум A5)
        $this->assertSame(['explore' => 0.1, 'dream' => 0.0, 'verify' => 0.5, 'rest' => 0.4], $p[5]);
        $this->assertSame(['explore' => 0.1, 'dream' => 0.5, 'verify' => 0.0, 'rest' => 0.4], $p[6]);
        // фаза 7: settlement выпадает → равномерный микс (аддендум A5)
        $this->assertSame(['explore' => 0.25, 'dream' => 0.25, 'verify' => 0.25, 'rest' => 0.25], $p[7]);
        // суммы долей = 1 для каждой фазы
        foreach ($p as $i => $ph) {
            $this->assertEqualsWithDelta(1.0, array_sum($ph), 1e-9, "phase {$i}");
        }
    }

    public function testProportionsDQuarterAggregation(): void
    {
        $p = SeasonScheduler::PHASES_D;
        $this->assertCount(4, $p);
        // D = среднее пар схлопнутых фаз (prereg §3b): Sow=(1+2)/2, Grow=(3+4)/2,
        // Harvest=(5+6)/2, Settle=(7+0)/2 — усреднение тех же 8 фаз.
        foreach ($p as $i => $ph) {
            $this->assertEqualsWithDelta(1.0, array_sum($ph), 1e-9, "D phase {$i}");
        }
        // проверка агрегации: explore кварталов
        $this->assertEqualsWithDelta(0.5, $p[0]['explore'], 1e-9); // (0.6+0.4)/2
        $this->assertEqualsWithDelta(0.2, $p[1]['explore'], 1e-9); // (0.3+0.1)/2
        $this->assertEqualsWithDelta(0.1, $p[2]['explore'], 1e-9); // (0.1+0.1)/2
        $this->assertEqualsWithDelta(0.225, $p[3]['explore'], 1e-9); // (0.25+0.2)/2
    }

    public function testRollFrequenciesMatchProportions(): void
    {
        // Ролл детерминирован на (seed, tick) — частоты меряем по всем тикам
        // фазы (2500 шт), не повторными вызовами одного тика.
        $s = new SeasonScheduler('B', 777);
        $phase = 1;
        $start = $phase * SeasonScheduler::TICKS_PER_PHASE;
        $counts = ['explore' => 0, 'dream' => 0, 'verify' => 0, 'rest' => 0];
        for ($t = $start; $t < $start + SeasonScheduler::TICKS_PER_PHASE; $t++) {
            $counts[$s->roll($t)]++;
        }
        foreach (SeasonScheduler::PHASES[$phase] as $op => $share) {
            $this->assertEqualsWithDelta(
                $share,
                $counts[$op] / SeasonScheduler::TICKS_PER_PHASE,
                0.02,
                "phase{$phase} {$op}"
            );
        }
    }

    public function testRollDeterministicPerTickSeed(): void
    {
        $a = new SeasonScheduler('B', 777);
        $b = new SeasonScheduler('B', 777);
        $c = new SeasonScheduler('B', 778);
        $seqA = [];
        $seqB = [];
        $seqC = [];
        for ($t = 0; $t < 500; $t++) {
            $seqA[] = $a->roll($t);
            $seqB[] = $b->roll($t);
            $seqC[] = $c->roll($t);
        }
        $this->assertSame($seqA, $seqB, 'same seed → same roll sequence');
        $this->assertNotSame($seqA, $seqC, 'different seed → different rolls');
    }

    public function testGovernorTopUpConvergesToBudget(): void
    {
        // Governor с banking: дефицит низко-explore фаз отыгрывается в
        // высоко-explore фазах (фазы 1-2 дают запас мощности).
        $budget = 1000.0;
        $cost = 0.1;
        $T = 40000;
        $s = new SeasonScheduler('B', 777, $budget, $cost);
        $maxAttempts = 0;
        for ($t = 1; $t <= $T; $t++) {
            $op = $s->roll($t);
            $attempts = $op === 'explore' ? $s->attemptsFor($t, $T) : 0;
            $maxAttempts = max($maxAttempts, $attempts);
            $s->noteTick($t, $T, $attempts * $cost);
        }
        $this->assertGreaterThan(1, $maxAttempts, 'top-up должен срабатывать');
        $this->assertEqualsWithDelta($budget, $s->spent(), $budget * 0.01, 'сходимость ±1%');
    }

    public function testGovernorNeverOverspendsBudget(): void
    {
        $budget = 500.0;
        $cost = 0.1;
        $s = new SeasonScheduler('D', 42, $budget, $cost);
        $T = 20000;
        for ($t = 1; $t <= $T; $t++) {
            $op = $s->roll($t);
            $attempts = $op === 'explore' ? $s->attemptsFor($t, $T) : 0;
            $s->noteTick($t, $T, $attempts * $cost);
            $this->assertLessThanOrEqual($budget + 1e-9, $s->spent(), 'overspend запрещён');
        }
        // бюджет исчерпан → новых explore-попыток нет
        $this->assertSame(0, $s->attemptsFor($T, $T));
    }

    public function testLedgerInjectionIdentity(): void
    {
        // правило сохранения: injections = ΔΣE_pop + dissipation
        $s = new SeasonScheduler('B', 777);
        $s->ledger(0, 30.0, 3, 0.0, 0.0);
        // +5.0 ΣE без диссипации = инъекция 5.0
        $s->ledger(50, 35.0, 3, 0.0, 0.0);
        // −2.0 ΣE при диссипации 1.5 → инъекция −0.5 (смерть унесла 2.0... нет:
        // ΔΣE = −2.0, диссипация +1.5 → инъекция = −2.0+1.5 = −0.5)
        $s->ledger(100, 33.0, 2, 1.5, 0.0);
        $rows = $s->ledgerRows();
        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(0.0, $rows[0]['injections'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $rows[1]['injections'], 1e-9);
        $this->assertEqualsWithDelta(-0.5, $rows[2]['injections'], 1e-9);
    }
}
