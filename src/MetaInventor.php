<?php
declare(strict_types=1);

namespace BeeSwarm;

class MetaInventor
{
    public function invent(array $unsolved, Grammar $grammar): ?string
    {
        // Level 1: fixed strategies
        $l1 = $this->tryLevel1($unsolved, $grammar);
        if ($l1 !== null) return $l1;
        
        // Level 2: minmax
        $l2 = $this->tryMinMax($unsolved, $grammar);
        if ($l2 !== null) return $l2;
        
        // Level 3: data analysis → new operation classes
        $l3 = $this->tryLevel3($unsolved, $grammar);
        if ($l3 !== null) return $l3;
        
        return null;
    }
    
    /**
     * Level 4: Detect recurrence patterns.
     * Instead of inventing an operation, reformats the data.
     * Returns the SHIFTED data that can be solved.
     */
    public function detectRecurrence(array $unsolved, Grammar $grammar): ?array
    {
        foreach ($unsolved as $idx => [$X, $y, $name]) {
            $nFeat = count($X[0]);
            $n = count($y);
            
            // Only for single-feature sequences
            if ($nFeat !== 1) continue;
            if ($n < 4) continue;
            
            $positions = array_column($X, 0);
            
            // Check: is position a simple arithmetic sequence (1,2,3,...)?
            $isSequence = true;
            for ($i = 1; $i < $n; $i++) {
                if (abs($positions[$i] - $positions[$i-1] - 1) > 0.01) {
                    $isSequence = false; break;
                }
            }
            if (!$isSequence) continue;
            
            // Try shift-1: (prev, current)
            $shifted1 = [];
            for ($i = 1; $i < $n; $i++) {
                $shifted1[] = [$y[$i-1], $y[$i]];
            }
            if (count($shifted1) >= 3) {
                $X1 = array_map(fn($r) => [$r[0]], $shifted1);
                $y1 = array_column($shifted1, 1);
                [$ok] = Search::find($X1, $y1, $grammar);
                if ($ok) {
                    return ['shift' => 1, 'data' => $shifted1, 'name' => $name . '_shift1', 
                            'desc' => "a_n = f(a_{n-1})"];
                }
            }
            
            // Try shift-2: (prev_prev, prev, current)
            $shifted2 = [];
            for ($i = 2; $i < $n; $i++) {
                $shifted2[] = [$y[$i-2], $y[$i-1], $y[$i]];
            }
            if (count($shifted2) >= 3) {
                $X2 = array_map(fn($r) => [$r[0], $r[1]], array_values($shifted2));
                $y2 = array_column($shifted2, 2);
                [$ok, $cv, $formula] = Search::find($X2, $y2, $grammar);
                if ($ok) {
                    return ['shift' => 2, 'data' => $shifted2, 'name' => $name . '_shift2',
                            'desc' => "a_n = f(a_{n-2}, a_{n-1})", 'formula' => $formula, 'cv' => $cv];
                }
            }
            
            // Try shift-3: (prev3, prev2, prev1, current)
            $shifted3 = [];
            for ($i = 3; $i < $n; $i++) {
                $shifted3[] = [$y[$i-3], $y[$i-2], $y[$i-1], $y[$i]];
            }
            if (count($shifted3) >= 3) {
                $X3 = array_map(fn($r) => [$r[0], $r[1], $r[2]], array_values($shifted3));
                $y3 = array_column($shifted3, 3);
                [$ok, $cv, $formula] = Search::find($X3, $y3, $grammar);
                if ($ok) {
                    return ['shift' => 3, 'data' => $shifted3, 'name' => $name . '_shift3',
                            'desc' => "a_n = f(a_{n-3}, a_{n-2}, a_{n-1})", 'formula' => $formula, 'cv' => $cv];
                }
            }
        }
        return null;
    }
    
    // ── Level 1 ──
    private function tryLevel1(array $unsolved, Grammar $grammar): ?string
    {
        $strategies = [
            'unary_not' => fn($t, $g) => $this->tryUnary($t, $g, fn($x) => 1 - $x, '¬x'),
            'unary_square' => fn($t, $g) => $this->tryUnary($t, $g, fn($x) => $x * $x, 'sq_x'),
            'invert_mul' => fn($t, $g) => $this->tryInvertOp($t, $g),
        ];
        $keys = array_keys($strategies);
        shuffle($keys);
        foreach ($keys as $k) {
            $r = $strategies[$k]($unsolved, $grammar);
            if ($r !== null) return $r;
        }
        return null;
    }
    
    // ── Level 2 ──
    private function tryMinMax(array $tasks, Grammar $grammar): ?string
    {
        foreach ($tasks as [$X, $y, $name]) {
            $vecMin = array_map(fn($r) => ($r[0] + $r[1] - abs($r[0] - $r[1])) / 2, $X);
            if ($this->matches($vecMin, $y)) return 'MIN';
            $vecMax = array_map(fn($r) => ($r[0] + $r[1] + abs($r[0] - $r[1])) / 2, $X);
            if ($this->matches($vecMax, $y)) return 'MAX';
        }
        return null;
    }
    
    // ── Level 3 ──
    private function tryLevel3(array $unsolved, Grammar $grammar): ?string
    {
        foreach ($unsolved as [$X, $y, $name]) {
            // Constant detection
            $mean = array_sum($y) / count($y);
            $variance = 0.0;
            foreach ($y as $v) $variance += ($v - $mean) ** 2;
            $cv = sqrt($variance / count($y)) / (abs($mean) + 1e-8);
            
            if ($cv < 0.01) {
                $constName = 'K' . round($mean, 1);
                if (!in_array($constName, $grammar->all())) {
                    $grammar->add($constName, 'auto-constant');
                    return $constName;
                }
            }
            
            // Exponential detection
            if ($mean > 0 && count($y) >= 3) {
                $ratios = [];
                for ($i = 1; $i < count($y); $i++) {
                    if ($y[$i-1] > 0) $ratios[] = $y[$i] / $y[$i-1];
                }
                if (count($ratios) >= 2) {
                    $ratioMean = array_sum($ratios) / count($ratios);
                    $ratioVar = 0.0;
                    foreach ($ratios as $r) $ratioVar += ($r - $ratioMean) ** 2;
                    $ratioCv = sqrt($ratioVar / count($ratios)) / (abs($ratioMean) + 1e-8);
                    if ($ratioCv < 0.01 && $ratioMean > 1.0) {
                        $base = round($ratioMean);
                        $powName = "pow{$base}";
                        if (!in_array($powName, $grammar->all())) {
                            $grammar->add($powName, 'auto-exponential');
                            return $powName;
                        }
                    }
                }
            }
            
            // Alternating detection
            $unique = array_unique($y);
            if (count($unique) === 2 && count($y) >= 3) {
                $vals = array_values($unique);
                if (abs($vals[0] + $vals[1]) < 0.01) {
                    $parityName = 'parity';
                    if (!in_array($parityName, $grammar->all())) {
                        $grammar->add($parityName, 'auto-parity');
                        return $parityName;
                    }
                }
            }
        }
        return null;
    }
    
    // ── Helpers ──
    private function tryUnary(array $tasks, Grammar $grammar, callable $fn, string $prefix): ?string
    {
        foreach ($tasks as [$X, $y, $name]) {
            $nFeat = count($X[0]);
            for ($i = 0; $i < $nFeat; $i++) {
                $opName = "{$prefix}{$i}";
                if (in_array($opName, $grammar->all())) continue;
                $vec = array_map(fn($row) => $fn($row[$i]), $X);
                if ($this->matches($vec, $y)) return $opName;
            }
        }
        return null;
    }
    
    private function tryInvertOp(array $tasks, Grammar $grammar): ?string
    {
        foreach ($tasks as [$X, $y, $name]) {
            foreach ($grammar->all() as $op) {
                $invName = "¬$op";
                if (in_array($invName, $grammar->all())) continue;
                $vec = [];
                $nFeat = count($X[0]);
                foreach ($X as $row) {
                    $a = $row[0];
                    $b = $nFeat >= 2 ? $row[1] : $a;
                    $r = $grammar->apply($a, $b, $op);
                    $vec[] = 1 - ($r ?? 0);
                }
                if ($this->matches($vec, $y)) return $invName;
            }
        }
        return null;
    }
    
    private function matches(array $vec, array $y): bool
    {
        $n = count($y);
        for ($i = 0; $i < $n; $i++) {
            if (abs($vec[$i] - $y[$i]) > 0.001) return false;
        }
        return true;
    }
}
