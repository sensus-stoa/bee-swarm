<?php
declare(strict_types=1);
namespace BeeSwarm\Core;

use BeeSwarm\Core\Grammar;

class Search
{
    public static function cv(array $vec, array $y): float
    {
        $n = count($vec);
        $exact = true;
        for ($i = 0; $i < $n; $i++) {
            if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
        }
        if ($exact) return 0.0;
        
        $ratio = [];
        for ($i = 0; $i < $n; $i++) $ratio[] = $vec[$i] / ($y[$i] + 1e-8);
        $mean = array_sum($ratio) / $n;
        if (abs($mean) < 1e-8) return 9.99;
        $variance = 0;
        foreach ($ratio as $r) $variance += ($r - $mean) ** 2;
        return sqrt($variance / $n) / abs($mean);
    }
    
    public static function find(array $X, array $y, Grammar $grammar, int $depth = 2): array
    {
        $n = count($y);
        if ($n === 0 || empty($X) || empty($X[0])) return [false, 9.99, 'none'];
        $nFeat = count($X[0]);
        
        // L0: Features
        $feats = [];
        for ($i = 0; $i < $nFeat; $i++) {
            $col = array_column($X, $i);
            $feats["x$i"] = $col;
            $feats["x{$i}²"] = array_map(fn($v) => $v * $v, $col);
        }
        foreach ([1.0, 2.0] as $c) $feats["K$c"] = array_fill(0, $n, $c);
        foreach ($grammar->all() as $op) {
            if (preg_match('/^K[_-]?(\d+(\.\d+)?)$/', $op, $m)) {
                $feats[$op] = array_fill(0, $n, (float)$m[1]);
            }
        }
        
        $exprs = $feats;
        $featKeys = array_keys($feats);
        $ops = $grammar->all();
        
        // L1: pairwise on features
        for ($a = 0; $a < count($featKeys); $a++) {
            for ($b = $a + 1; $b < count($featKeys); $b++) {
                $va = $feats[$featKeys[$a]]; $vb = $feats[$featKeys[$b]];
                foreach ($ops as $op) {
                    $vec = [];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply($va[$i], $vb[$i], $op);
                        $vec[] = $r ?? 0.0;
                    }
                    $exprs["({$featKeys[$a]}$op{$featKeys[$b]})"] = $vec;
                }
            }
        }
        
        // L1 unary: apply unary ops to each feature
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
        
        // Get L1 keys (everything added after features)
        $l1Keys = array_values(array_diff(array_keys($exprs), $featKeys));
        
        // L1 squared
        $l1Sq = [];
        foreach (array_slice($l1Keys, 0, 200) as $name) {
            $vec = $exprs[$name];
            $exprs["($name)²"] = array_map(fn($v) => $v * $v, $vec);
            $l1Sq[] = "($name)²";
        }
        
        // 🔥 Unary on L1 results
        $l1Unary = [];
        foreach (array_slice($l1Keys, 0, 200) as $name) {
            foreach ($unaryOps as $uname) {
                $vec = [];
                $baseVec = $exprs[$name];
                for ($i = 0; $i < $n; $i++) {
                    $r = $grammar->apply($baseVec[$i], 0.0, $uname);
                    $vec[] = $r ?? 0.0;
                }
                $exprs["({$name}{$uname})"] = $vec;
                $l1Unary[] = "({$name}{$uname})";
            }
        }
        
        // L2: combinations of (L1 + L1² + L1-unary)
        $l2Keys = [];
        if ($depth >= 2) {
            $pool = array_merge(
                array_slice($l1Keys, 0, 40),
                array_slice($l1Sq, 0, 30),
                array_slice($l1Unary, 0, 30)
            );
            for ($a = 0; $a < count($pool); $a++) {
                $va = $exprs[$pool[$a]];  // hoisted
                for ($b = $a + 1; $b < count($pool); $b++) {
                    $vb = $exprs[$pool[$b]];
                    foreach ($ops as $op) {
                        $vec = [];
                        for ($i = 0; $i < $n; $i++) {
                            $r = $grammar->apply($va[$i], $vb[$i], $op);
                            $vec[] = $r ?? 0.0;
                        }
                        $name = "({$pool[$a]}$op{$pool[$b]})";
                        $exprs[$name] = $vec;
                        $l2Keys[] = $name;
                    }
                }
            }
        }
        
        // L3: L2 / constant (для MIN = (...)/2)
        if ($depth >= 3) {
            $constKeys = array_filter($featKeys, fn($k) => str_starts_with($k, 'K'));
            foreach ($l2Keys as $l2name) {
                foreach ($constKeys as $ck) {
                    $vec = [];
                    $cvec = $feats[$ck];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply($exprs[$l2name][$i], $cvec[$i], '/');
                        $vec[] = $r ?? 0.0;
                    }
                    $exprs["($l2name/$ck)"] = $vec;
                }
            }
        }
        
        // Evaluate FEATURES first (fast path)
        foreach ($feats as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
            if ($exact) return [true, 0.0, $name];
        }
        // Evaluate all expressions
        $bestCv = 9.99; $bestName = null;
        foreach ($exprs as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
            }
            if ($exact) return [true, 0.0, $name];
            
            $std = self::stddev($vec);
            if ($std < 1e-6) continue;
            $cv = self::cv($vec, $y);
            if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; }
        }
        
        return [$bestCv < 0.15, $bestCv, $bestName ?? 'none'];
    }
    
    private static function stddev(array $v): float
    {
        $n = count($v); $mean = array_sum($v) / $n;
        $sq = 0; foreach ($v as $x) $sq += ($x - $mean) ** 2;
        return sqrt($sq / $n);
    }
}
