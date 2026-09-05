<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * Atom penalty (протокол §2.5.6, диссипативный контур, Phase 5).
 *
 * Атом, входящий в >= falsifyThreshold фальсифицированных (OBSOLETE/LOSS)
 * законов, получает penalty weight: вес в weightedPick делится на
 * (1 + penalty_count). Мягкий штраф — вес, не удаление атома.
 *
 * Реабилитация (анти-осцилляция): атом, вошедший в подтверждённый
 * (non-obsolete) закон, декрементирует penalty_count. Не уходит ниже нуля.
 */
final class AtomPenalty
{
    public function __construct(
        private readonly int $falsifyThreshold = 3,
        private readonly int $maxPenalty = 50,
    ) {
    }

    /** Атом вошёл в фальсифицированный (LOSS/OBSOLETE) закон. */
    public function falsify(string $atom): void
    {
        $db = Database::get();
        // SQLite MIN() с параметром не работает (питфолл) — хардкод через maxPenalty
        // интерполяцией в int (значение инъектируется конструктором, не пользовательский ввод)
        $cap = (int) $this->maxPenalty;
        $db->prepare(
            "INSERT INTO atom_penalties (atom, penalty_count) VALUES (?, 1)
             ON CONFLICT(atom) DO UPDATE SET penalty_count = MIN(penalty_count + 1, {$cap})"
        )->execute([$atom]);
    }

    /** Атом вошёл в живой (подтверждённый) закон — реабилитация. */
    public function rehabilitate(string $atom): void
    {
        $db = Database::get();
        $db->prepare(
            'INSERT INTO atom_penalties (atom, penalty_count) VALUES (?, 0)
             ON CONFLICT(atom) DO UPDATE SET
               penalty_count = MAX(penalty_count - 1, 0)'
        )->execute([$atom]);
    }

    /** Заслужил ли атом штраф (порог фальсификаций). */
    public function isPenalized(string $atom): bool
    {
        return $this->penaltyCount($atom) >= $this->falsifyThreshold;
    }

    public function penaltyCount(string $atom): int
    {
        $stmt = Database::get()->prepare(
            'SELECT penalty_count FROM atom_penalties WHERE atom = ?'
        );
        $stmt->execute([$atom]);
        $v = $stmt->fetchColumn();

        return $v === false ? 0 : (int) $v;
    }

    /**
     * Штрафной множитель веса атома для weightedPick.
     * До порога — 1 (без штрафа); после — 1/(1+count): мягкое затухание.
     */
    public function weightMultiplier(string $atom): float
    {
        $count = $this->penaltyCount($atom);
        if ($count < $this->falsifyThreshold) {
            return 1.0;
        }

        return 1.0 / (1.0 + ($count - $this->falsifyThreshold));
    }
}
