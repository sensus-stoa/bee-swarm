<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * STRATEGY-TRANSFER prereg rev.3 R2/R3 + rev.4 F4: sensor buffer for the
 * operator-yield channel. Collection is append-only (temporal order);
 * stateAt(t) is computed ONLY from the prefix [0..t] - future entries
 * cannot enter by construction (mechanical leakage test enforces this).
 * UNKNOWN when an operator has fewer than n_min observations.
 * Yield is cost-normalized: (R_before - R_after) / ((R_before + eps) * cost).
 */
final class SensorState
{
    /** @var list<array{op:string, drop:float, cost:float}> */
    private array $obs = [];
    private int $nMin;

    public function __construct(int $nMin)
    {
        $this->nMin = max(1, $nMin);
    }

    /** Append one observation (call only when the action actually ran). */
    public function addObservation(string $op, float $rBefore, float $rAfter, float $cost = 1.0, int $at = null): void
    {
        $at = $at ?? count($this->obs);
        $eps = 1e-12;
        $drop = ($rBefore - $rAfter) / ((abs($rBefore) + $eps) * max($cost, $eps));
        $this->obs[$at] = ['op' => $op, 'drop' => $drop, 'cost' => $cost];
    }

    /**
     * Sensor state for operator $op using ONLY observations with index <= $t.
     *
     * @return array{status:string, Y?:float, n:int, var?:float}
     */
    public function state(string $op, int $t): array
    {
        $drops = [];
        foreach ($this->obs as $i => $o) {
            if ($i <= $t && $o['op'] === $op) {
                $drops[] = $o['drop'];
            }
        }
        $n = count($drops);
        if ($n < $this->nMin) {
            return ['status' => 'UNKNOWN', 'n' => $n];
        }
        $mean = array_sum($drops) / $n;
        $var = 0.0;
        foreach ($drops as $d) {
            $var += ($d - $mean) ** 2;
        }
        $var /= $n;

        return ['status' => 'OK', 'Y' => $mean, 'n' => $n, 'var' => $var];
    }

    /**
     * rev.5 R3 W-window: Y over the LAST $w observations of $op with index
     * <= $t (prefix-restricted, leakage-safe by the same construction).
     * n = TOTAL observation count of $op (confidence triple (Y, n, Var));
     * UNKNOWN when fewer than nMin total observations exist.
     *
     * @return array{status:string, Y?:float, n:int, var?:float}
     */
    public function stateWindow(string $op, int $t, int $w): array
    {
        $full = $this->state($op, $t);
        if ($full['status'] !== 'OK') {
            return $full;
        }
        $recent = [];
        foreach ($this->obs as $i => $o) {
            if ($i <= $t && $o['op'] === $op) {
                $recent[] = $o['drop'];
                if (count($recent) > $w) {
                    array_shift($recent);
                }
            }
        }
        $mean = array_sum($recent) / count($recent);
        $var = 0.0;
        foreach ($recent as $d) {
            $var += ($d - $mean) ** 2;
        }
        $var /= count($recent);

        return ['status' => 'OK', 'Y' => $mean, 'n' => $full['n'], 'var' => $var];
    }
}
