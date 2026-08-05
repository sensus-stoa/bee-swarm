<?php

declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * PlateauDetector — счётчик тиков без открытий.
 *
 * HONEST_CRITERIA §1.5: когда consecutive_no_discovery >= P, система
 * входит в PLATEAU: sleep увеличивается, compose отключается.
 * Открытие или новые данные — выход из PLATEAU.
 *
 * P = 50 ticks (E), T_plateau = 10s sleep.
 */
class PlateauDetector
{
    private int $threshold;

    private int $consecutiveNoDiscovery = 0;

    private bool $justEntered = false;

    private bool $wasPlateau = false;

    private const BASE_SLEEP_US = 200_000;

    private const PLATEAU_SLEEP_US = 10_000_000;

    private int $plateauSleepUs;

    public function __construct(int $threshold = 50, ?int $plateauSleepUs = null)
    {
        $this->threshold = $threshold;
        $this->plateauSleepUs = $plateauSleepUs ?? self::PLATEAU_SLEEP_US;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    /**
     * Один тик демона. $foundDiscovery = true если было открытие.
     */
    public function tick(bool $foundDiscovery): void
    {
        $wasPlateau = $this->isPlateau();

        if ($foundDiscovery) {
            $this->consecutiveNoDiscovery = 0;
            $this->justEntered = false;
        } else {
            $this->consecutiveNoDiscovery++;
        }

        // justEntered: ровно на переходе через порог
        $this->justEntered = ! $wasPlateau && $this->isPlateau();
        if ($this->isPlateau()) {
            $this->wasPlateau = true;
        } elseif ($this->consecutiveNoDiscovery > 1) {
            // CONCERNS 05.08: wasPlateau липкий — сброс, когда ушли из плато
            $this->wasPlateau = false;
        }
    }

    public function isPlateau(): bool
    {
        return $this->consecutiveNoDiscovery >= $this->threshold;
    }

    public function getSleepUs(): int
    {
        return $this->isPlateau() ? $this->plateauSleepUs : self::BASE_SLEEP_US;
    }

    public function getConsecutiveNoDiscovery(): int
    {
        return $this->consecutiveNoDiscovery;
    }

    /**
     * true только на первом тике после входа в плато (для однократного лога)
     */
    public function justEnteredPlateau(): bool
    {
        return $this->justEntered;
    }

    /**
     * D-ACT (аудит 05.08): true на тике, когда плато завершилось открытием
     * (было плато, foundDiscovery=true, consecutive сброшен).
     */
    public function justExitedPlateau(): bool
    {
        if (! $this->wasPlateau || $this->isPlateau()) {
            return false;
        }
        // CONCERNS (deleg_294903fa): edge-trigger — сброс ПРИ РЕГИСТРАЦИИ,
        // иначе в discovery-rich фазе (consecutive<=1) событие фолдится
        // каждый тик (8 событий на один выход). Однократное срабатывание.
        $exited = $this->consecutiveNoDiscovery <= 1;
        $this->wasPlateau = false;

        return $exited;
    }

    /**
     * Compose должен работать только НЕ на плато
     */
    public function shouldRunCompose(): bool
    {
        return ! $this->isPlateau();
    }

    /**
     * Внешнее событие (forager, новые данные) — выход из плато
     */
    public function wakeup(): void
    {
        $this->consecutiveNoDiscovery = 0;
        $this->justEntered = false;
    }
}
