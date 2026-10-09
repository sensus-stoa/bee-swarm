<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * EXP-039: SeasonScheduler — фазовые пропорции энергии, ролл операций,
 * бюджетный Governor (multi-attempt top-up + banking), популяционный леджер.
 *
 * Чистый класс: НЕ зависит от Hive/DB, НЕ трогает глобальный RNG
 * (mt_srand улья не нарушается — собственный PRNG на (seed, tick)).
 *
 * Источники: PREREG.md §2 (пропорции — источник истины), §3b (D),
 * PREREG_ADDENDUM_BUDGET.md (A5 — семантика нереализуемых долей,
 * A3 — Governor, A8 — изоляция).
 */
final class SeasonScheduler
{
    public const TICKS_PER_PHASE = 2500;
    public const PHASES_PER_YEAR = 8;
    public const MAX_ATTEMPTS = 4;

    /**
     * PREREG §2, 8 фаз (Imbolc..Yule). Фазы 5/6: autophagy-доля 0.3
     * исполняется как rest (аддендум A5: autophagy голод-триггерный,
     * правка Bee = второй фактор). Фаза 7: settlement выпадает
     * (prereg §4) → равномерный микс.
     */
    public const PHASES = [
        ['explore' => 0.2, 'dream' => 0.4, 'verify' => 0.2, 'rest' => 0.2],
        ['explore' => 0.6, 'dream' => 0.1, 'verify' => 0.2, 'rest' => 0.1],
        ['explore' => 0.4, 'dream' => 0.0, 'verify' => 0.4, 'rest' => 0.2],
        ['explore' => 0.3, 'dream' => 0.0, 'verify' => 0.5, 'rest' => 0.2],
        ['explore' => 0.1, 'dream' => 0.0, 'verify' => 0.7, 'rest' => 0.2],
        ['explore' => 0.1, 'dream' => 0.0, 'verify' => 0.5, 'rest' => 0.4],
        ['explore' => 0.1, 'dream' => 0.5, 'verify' => 0.0, 'rest' => 0.4],
        ['explore' => 0.25, 'dream' => 0.25, 'verify' => 0.25, 'rest' => 0.25],
    ];

    /**
     * PREREG §3b: D = 4 квартала × 5000 тиков, агрегированные пропорции
     * (среднее пар): Sow=(1+2)/2, Grow=(3+4)/2, Harvest=(5+6)/2,
     * Settle=(7+0)/2. Значения захардкожены, сверены с PHASES тестом.
     */
    public const PHASES_D = [
        ['explore' => 0.5, 'dream' => 0.05, 'verify' => 0.3, 'rest' => 0.15],
        ['explore' => 0.2, 'dream' => 0.0, 'verify' => 0.6, 'rest' => 0.2],
        ['explore' => 0.1, 'dream' => 0.25, 'verify' => 0.25, 'rest' => 0.4],
        ['explore' => 0.225, 'dream' => 0.325, 'verify' => 0.225, 'rest' => 0.225],
    ];

    /** @var array<int, array<string, float>> активная таблица пропорций */
    private array $phases;

    private float $spent = 0.0;
    private float $deficit = 0.0;
    private bool $exhausted = false;

    /** @var list<array<string, float|int>> */
    private array $ledgerRows = [];
    private ?float $prevSumEnergy = null;

    /**
     * @param string $mode   A|B|C|D (C = shuffled PHASES по permSeed)
     * @param int    $seed   seed роллов (C: и seed перестановки)
     * @param float  $budget бюджет Governor (0 = off; A всегда off)
     * @param float  $cost   цена одной explore-попытки (searchCost)
     */
    public function __construct(
        private readonly string $mode,
        private readonly int $seed,
        private readonly float $budget = 0.0,
        private readonly float $cost = 0.1,
    ) {
        $this->phases = match ($mode) {
            'A' => [],
            'D' => self::PHASES_D,
            'C' => $this->shuffledPhases(),
            default => self::PHASES,
        };
    }

    /**
     * Фаза тика: floor(tick / TICKS_PER_PHASE) mod N (D: период 5000).
     * A — uniform (фаз нет, возвращаем 0).
     */
    public function phase(int $tick): int
    {
        if ($this->mode === 'A') {
            return 0;
        }
        if ($this->mode === 'D') {
            return intdiv($tick, self::TICKS_PER_PHASE * 2) % 4;
        }
        $n = count($this->phases);

        return intdiv($tick, self::TICKS_PER_PHASE) % max(1, $n);
    }

    /**
     * Ролл операции тика по фазовым долям. Детерминирован (seed, tick):
     * порядок вызовов не влияет на результат.
     */
    public function roll(int $tick): string
    {
        if ($this->mode === 'A') {
            return 'explore';
        }
        $prop = $this->phases[$this->phase($tick)];
        $r = $this->prand($tick);
        $acc = 0.0;
        foreach (['explore', 'dream', 'verify', 'rest'] as $op) {
            $acc += $prop[$op];
            if ($r < $acc) {
                return $op;
            }
        }

        return 'rest'; // страховка от float-дрейфа суммы
    }

    /**
     * Целевой расход бюджета к тику tick (pro-rata линейный).
     * tick=0 → 0, tick=T → budget.
     */
    public function proRata(int $tick, int $totalTicks): float
    {
        if ($totalTicks <= 0) {
            return 0.0;
        }

        return $this->budget * min(1.0, max(0.0, $tick / $totalTicks));
    }

    /**
     * Governor (аддендум A3) с banking: target тика = pro-rata + накопленный
     * дефицит предыдущих тиков. Возвращает число explore-попыток тика
     * (0 если ролл не explore или бюджет исчерпан; 1..MAX_ATTEMPTS иначе).
     * mode A или budget=0 → всегда 1 (ролл A всегда explore).
     *
     * @param int $totalTicks длительность сеанса (для pro-rata)
     */
    public function attemptsFor(int $tick, int $totalTicks): int
    {
        // mode A: uniform, все тики explore по 1 попытке (гейта нет).
        if ($this->mode === 'A') {
            return 1;
        }
        // Фазовые режимы: ролл решает explore(1+) или другая операция(0).
        // budget=0 (smoke/A-эталон) → Governor-добор выключен, но РОЛЛ ЖИВЁТ
        // (иначе фазовая механика не исполняется вовсе — поймано smoke 09.10:
        // budget=0 делал все тики explore, B не отличался от A).
        if ($this->roll($tick) !== 'explore' || $this->exhausted) {
            return 0;
        }
        if ($this->budget <= 0.0) {
            return 1;
        }
        $target = $this->proRata($tick, $totalTicks) + $this->deficit;
        if ($this->spent >= $target) {
            return 1; // минимум одна попытка: ролл выпал, Governor не режет
        }
        $extra = (int) floor(($target - $this->spent) / $this->cost);
        $affordable = (int) floor(max(0.0, $this->budget - $this->spent) / $this->cost);

        return (int) min(self::MAX_ATTEMPTS, 1 + max(0, $extra), $affordable);
    }

    /**
     * Учёт фактического расхода тика (вызывается драйвером ПОСЛЕ попыток).
     * Фиксирует overspend (непревышение бюджета), обновляет banking-дефицит.
     */
    public function noteTick(int $tick, int $totalTicks, float $actualCost): void
    {
        if ($this->budget <= 0.0 || $this->mode === 'A') {
            return;
        }
        $this->spent = min($this->budget, $this->spent + $actualCost);
        if ($this->spent >= $this->budget - 1e-9) {
            $this->exhausted = true;
        }
        $target = $this->proRata($tick, $totalTicks);
        // дефицит = недобор против pro-rata этого тика, копится, прощается
        // при опережении (не ниже 0 — кредит не выдаём)
        $this->deficit = max(0.0, $target - $this->spent);
    }

    public function spent(): float
    {
        return $this->spent;
    }

    /**
     * Pop-ledger (аддендум A3): строка телеметрии с инъекциями по правилу
     * сохранения энергии: injections = ΔΣE_pop + dissipation.
     */
    public function ledger(int $tick, float $sumEnergy, int $alive, float $metab, float $search): void
    {
        $dissipation = $metab + $search;
        $deltaE = $this->prevSumEnergy === null ? 0.0 : ($sumEnergy - $this->prevSumEnergy);
        $injections = $this->prevSumEnergy === null ? 0.0 : ($deltaE + $dissipation);
        $this->prevSumEnergy = $sumEnergy;
        $this->ledgerRows[] = [
            'tick' => $tick,
            'phase' => $this->phase($tick),
            'sum_energy' => round($sumEnergy, 6),
            'alive' => $alive,
            'metab' => round($metab, 6),
            'search' => round($search, 6),
            'injections' => round($injections, 6),
        ];
    }

    /** @return list<array<string, float|int>> */
    public function ledgerRows(): array
    {
        return $this->ledgerRows;
    }

    /** @return list<array<string, float>> пропорции (для драйвера/анализатора) */
    public function activePhases(): array
    {
        return $this->phases;
    }

    /**
     * C-режим: перестановка PHASES по детерминированному PRNG
     * (prereg §6: seed на перестановку фиксирован).
     *
     * @return list<array<string, float>>
     */
    private function shuffledPhases(): array
    {
        $phases = self::PHASES;
        $n = count($phases);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = (int) ($this->prand(1_000_000 + $i) * ($i + 1));
            if ($j > $i) {
                $j = $i;
            }
            [$phases[$i], $phases[$j]] = [$phases[$j], $phases[$i]];
        }

        return $phases;
    }

    /**
     * Собственный 32-битный PRNG, безопасный в PHP int64
     * (все умножения < 2^53 либо маскируются ДО умножения;
     * pure mulberry32 с 32×32→64 не помещается в signed int64).
     * Посев (seed, tick) — изоляция от глобального mt_rand улья.
     */
    private function prand(int $tick): float
    {
        $z = ($this->seed & 0xFFFFFFFF) ^ ((($tick & 0xFFFF) * 0x9E37) & 0xFFFFFFFF);
        $z = ($z + 0x6D2B) & 0xFFFF;
        $z = ((($z ^ ($z >> 7)) * ($z | 0x41)) & 0xFFFF);
        $z = ($z ^ (($z + ((($z ^ ($z >> 5)) * ($z | 0x1F)) & 0xFFFF)) & 0xFFFF)) & 0xFFFF;

        return $z / 65536.0;
    }
}
