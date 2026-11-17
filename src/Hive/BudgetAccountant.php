<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * STRATEGY-TRANSFER prereg rev.5 (owner edit 1): budget accounting for the
 * strategy campaign. A charges evolution and execution separately; C (the
 * always-on control) receives exactly A's actual execution figure; A's
 * evolution spend returns to C as unspent and never enters the A-vs-C
 * comparison (comparison is execution-budget-matched).
 */
final class BudgetAccountant
{
    private float $totalWall;
    private int $totalActions;
    private float $wallSlack;
    private float $evolutionWall = 0.0;
    private int $evolutionActions = 0;
    private float $executionWall = 0.0;
    private int $executionActions = 0;

    public function __construct(float $totalWall, int $totalActions, float $wallSlack = 0.0)
    {
        $this->totalWall = $totalWall;
        $this->totalActions = $totalActions;
        // flight-granularity overshoot tolerance in SECONDS (a flight runs to
        // completion; worst-case overshoot = one flight = max share)
        $this->wallSlack = max(0.0, $wallSlack);
    }

    public function chargeEvolution(float $wall, int $actions): void
    {
        $this->evolutionWall += $wall;
        $this->evolutionActions += $actions;
        $this->assertWithinTotal();
    }

    public function chargeExecution(float $wall, int $actions): void
    {
        $this->executionWall += $wall;
        $this->executionActions += $actions;
        $this->assertWithinTotal();
    }

    public function executionActualWall(): float
    {
        return $this->executionWall;
    }

    public function executionActualActions(): int
    {
        return $this->executionActions;
    }

    public function evolutionWall(): float
    {
        return $this->evolutionWall;
    }

    public function unspentWall(): float
    {
        return $this->totalWall - $this->evolutionWall - $this->executionWall;
    }

    public function totalWall(): float
    {
        return $this->totalWall;
    }

    public function totalActions(): int
    {
        return $this->totalActions;
    }

    /** C control: total = A's actual execution figure; evolution spend excluded. */
    public static function forC(BudgetAccountant $a): self
    {
        return new self($a->executionActualWall(), $a->executionActualActions());
    }

    private function assertWithinTotal(): void
    {
        if ($this->evolutionWall + $this->executionWall > $this->totalWall + $this->wallSlack + 1e-9) {
            throw new \RuntimeException('budget exceeded: wall ' . ($this->evolutionWall + $this->executionWall) . ' > ' . $this->totalWall . ' (slack ' . $this->wallSlack . ')');
        }
        if ($this->evolutionActions + $this->executionActions > $this->totalActions) {
            throw new \RuntimeException('budget exceeded: actions');
        }
    }
}
