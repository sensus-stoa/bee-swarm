<?php
declare(strict_types=1);

namespace BeeSwarm;

class Search
{
    public static function cv(array $vec, array $y): float
    {
        $n = count($vec);
        // Exact match?
        $exact = true;
        for ($i = 0; $i < $n; $i++) {
            if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
        }
        if ($exact) return 0.0;
        
        $sum = 0.0; $sumSq = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $ratio = $vec[$i] / ($y[$i] + 1e-8);
            $sum += $ratio;
            $sumSq += $ratio * $ratio;
        }
        $mean = $sum / $n;
        $variance = ($sumSq / $n) - ($mean * $mean);
        if ($variance < 0) $variance = 0;
        $std = sqrt($variance);
        return ($std / (abs($mean) + 1e-8));
    }
    
    public static function find(array $X, array $y, Grammar $grammar, int $depth = 2): array
    {
        $n = count($y);
        $nFeat = count($X[0]);
        
        // Build features
        $feats = [];
        for ($i = 0; $i < $nFeat; $i++) {
            $col = array_column($X, $i);
            $feats["x$i"] = $col;
            $feats["x{$i}²"] = array_map(fn($v) => $v * $v, $col);
        }
        foreach ([1.0, 2.0] as $c) {
            $feats["K$c"] = array_fill(0, $n, $c);
        }
        // Add dynamically invented constants (K_7, K_3.5, etc.)
        foreach ($grammar->all() as $op) {
            if (preg_match('/^K[_-]?(\d+(\.\d+)?)$/', $op, $m)) {
                $feats[$op] = array_fill(0, $n, (float)$m[1]);
            }
        }
        
        $featKeys = array_keys($feats);
        $exprs = $feats;
        
        // UNARY operations: apply to each feature individually
        $unaryOps = $grammar->getUnaryOps();
        foreach ($featKeys as $fname) {
            foreach ($unaryOps as $uname) {
                $vec = [];
                for ($i = 0; $i < $n; $i++) {
                    $r = $grammar->apply($feats[$fname][$i], 0.0, $uname);
                    $vec[] = $r ?? 0.0;
                }
                $exprs["({$fname}{$uname})"] = $vec;
            }
        }
        
        // L1: pairwise
        $ops = $grammar->all();
        for ($a = 0; $a < count($featKeys); $a++) {
            for ($b = $a + 1; $b < count($featKeys); $b++) {
                $na = $featKeys[$a]; $nb = $featKeys[$b];
                $va = $feats[$na]; $vb = $feats[$nb];
                foreach ($ops as $op) {
                    $vec = [];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply($va[$i], $vb[$i], $op);
                        $vec[] = $r ?? 0.0;
                    }
                    $exprs["($na$op$nb)"] = $vec;
                }
            }
        }
        
        // L1 self-products
        $l1Keys = array_diff(array_keys($exprs), $featKeys);
        $l1Sq = [];
        foreach (array_slice($l1Keys, 0, 50) as $name) {
            $vec = $exprs[$name];
            $exprs["($name)²"] = array_map(fn($v) => $v * $v, $vec);
            $l1Sq[] = "($name)²";
        }
        
        // L2: pairs including squared
        if ($depth >= 2) {
            $pool = array_merge(array_slice($l1Keys, 0, 30), $l1Sq);
            for ($a = 0; $a < count($pool); $a++) {
                for ($b = $a + 1; $b < count($pool); $b++) {
                    $va = $exprs[$pool[$a]]; $vb = $exprs[$pool[$b]];
                    foreach ($ops as $op) {
                        $vec = [];
                        for ($i = 0; $i < $n; $i++) {
                            $r = $grammar->apply($va[$i], $vb[$i], $op);
                            $vec[] = $r ?? 0.0;
                        }
                        $exprs["({$pool[$a]}$op{$pool[$b]})"] = $vec;
                    }
                }
            }
        }
        
        $bestCv = 9.99;
        $bestName = null;
        foreach ($exprs as $name => $vec) {
            // Exact match FIRST — before filtering by stddev
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
            }
            if ($exact) { return [true, 0.0, $name]; }
            
            $std = self::stddev($vec);
            if ($std < 1e-6) continue;
            $cv = self::cv($vec, $y);
            if ($cv < $bestCv) {
                $bestCv = $cv;
                $bestName = $name;
            }
        }
        
        $found = $bestCv < 0.01;
        return [$found, $bestCv, $bestName];
    }
    
    private static function stddev(array $v): float
    {
        $n = count($v);
        $mean = array_sum($v) / $n;
        $sumSq = 0.0;
        foreach ($v as $x) $sumSq += ($x - $mean) ** 2;
        return sqrt($sumSq / $n);
    }
}
