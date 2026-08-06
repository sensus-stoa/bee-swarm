<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

class Search
{
    /** PARSIMONY: штраф за символ формулы (C) калибруемый, из gplearn */
    private const PARSIMONY_LAMBDA = 5e-5;

    public static function cv(array $vec, array $y, float $shift = 0.0): float
    {
        $n = count($vec);
        $exact = true;
        for ($i = 0; $i < $n; $i++) {
            // NaN/INF — артефакт, не закон (бейзлайн 05.08: R× переполнение)
            if (! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001) {
                $exact = false;
                break;
            }
        }
        if ($exact) {
            return 0.0;
        }

        // AFFINE-LAWS (ЭКСП-012): при знакопеременной цели y (переход через 0)
        // ratio = pred/y не определён (деление на 0, знакопеременный CV→∞).
        // Сдвиг: ratio = (pred−shift)/(y−shift), shift = min(y)−1 → знаменатель > 0.
        $ratio = [];
        for ($i = 0; $i < $n; $i++) {
            $ratio[] = ($vec[$i] - $shift) / ($y[$i] - $shift + 1e-8);
        }
        $mean = array_sum($ratio) / $n;
        if (abs($mean) < 1e-8) {
            return 9.99;
        }
        $variance = 0;
        foreach ($ratio as $r) {
            $variance += ($r - $mean) ** 2;
        }
        return sqrt($variance / $n) / abs($mean);
    }

    public static function find(array $X, array $y, Grammar $grammar, int $depth = 2, ?array $colLabels = null, float $testRatio = 0.0, float $cvTrainMax = 0.15): array
    {
        $n = count($y);
        if ($n === 0 || empty($X) || empty($X[0])) {
            return [false, 9.99, 'none', 9.99, 'NONE'];
        }
        $nFeat = count($X[0]);

        // Feature naming: colLabels[0]='price' → feature key 'price' instead of 'x0'
        $featName = fn (int $i): string => $colLabels[$i] ?? "x{$i}";

        // L0: Features
        $feats = [];
        for ($i = 0; $i < $nFeat; $i++) {
            $col = array_column($X, $i);
            $fname = $featName($i);
            $feats[$fname] = $col;
            $feats["{$fname}²"] = array_map(fn ($v) => $v * $v, $col);
        }
        foreach ([1.0, 2.0] as $c) {
            $feats["K{$c}"] = array_fill(0, $n, $c);
        }
        foreach ($grammar->all() as $op) {
            if (preg_match('/^K[_-]?(\d+(\.\d+)?)$/', $op, $m)) {
                $feats[$op] = array_fill(0, $n, (float) $m[1]);
            }
        }

        // GlobalReduce: arity bridge (float[]→float) via Grammar::reduce
        // S1.9 Phase 2 — reduce on top-level feature columns
        // Constants (R+x0, R×x0, Rmaxx0, Rminx0) enter L1/L2 pool
        // Pointwise expressions enter expression pool directly
        $reduceAssoc = ['+', '×', 'max', 'min'];
        // Collect raw feature names (first $nFeat entries, before squared versions)
        $rawFeatNames = [];
        for ($i = 0; $i < $nFeat; $i++) {
            $rawFeatNames[$featName($i)] = true;
        }
        $rawFeatKeys = array_keys($feats);
        foreach ($rawFeatKeys as $fname) {
            if (!isset($rawFeatNames[$fname])) continue; // only raw features
            $col = $feats[$fname];
            // Skip non-numeric columns (text data, labels, etc.)
            $allNumeric = true;
            foreach ($col as $v) {
                if (!is_float($v) && !is_int($v)) { $allNumeric = false; break; }
            }
            if (!$allNumeric) continue;
            foreach ($reduceAssoc as $rop) {
                $reduced = $grammar->reduce($rop, $col);
                if ($reduced === null || abs($reduced) < 1e-10) continue;

                // Reduce constant: enters L1/L2 pool
                $cname = "R{$rop}{$fname}";
                $feats[$cname] = array_fill(0, $n, $reduced);

                // Pointwise: (xj / reduce(op, xj)) — normalization
                $pnameDiv = "({$fname}/R{$rop}{$fname})";
                $vecDiv = [];
                for ($i = 0; $i < $n; $i++) {
                    $r = $grammar->apply((float)$col[$i], $reduced, '/');
                    $vecDiv[] = $r ?? 0.0;
                }
                $feats[$pnameDiv] = $vecDiv;

                // Pointwise: (xj - reduce(op, xj)) — centering
                // Only for min/max (subtracting sum/product is meaningless)
                if ($rop === 'min' || $rop === 'max') {
                    $pnameSub = "({$fname}-R{$rop}{$fname})";
                    $vecSub = [];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply((float)$col[$i], $reduced, '−');
                        $vecSub[] = $r ?? 0.0;
                    }
                    $feats[$pnameSub] = $vecSub;
                }
            }

            // Range constant: (Rmaxxj - Rminxj) — useful for min-max normalization
            $rmax = $grammar->reduce('max', $col);
            $rmin = $grammar->reduce('min', $col);
            if ($rmax !== null && $rmin !== null && abs($rmax - $rmin) > 1e-10) {
                $range = $rmax - $rmin;
                $feats["Rrange{$fname}"] = array_fill(0, $n, $range);

                // Pointwise min-max norm: (xj - Rminxj) / range
                $pnameNorm = "(Rnorm{$fname})";
                $vecNorm = [];
                for ($i = 0; $i < $n; $i++) {
                    $num = $grammar->apply((float)$col[$i], $rmin, '−') ?? 0.0;
                    $den = $range;
                    $vecNorm[] = $den != 0 ? ($num / $den) : 0.0;
                }
                $feats[$pnameNorm] = $vecNorm;
            }
        }

        $exprs = $feats;
        $featKeys = array_keys($feats);
        $ops = $grammar->all();

        // L1: pairwise on features
        for ($a = 0; $a < count($featKeys); $a++) {
            for ($b = $a + 1; $b < count($featKeys); $b++) {
                $va = $feats[$featKeys[$a]];
                $vb = $feats[$featKeys[$b]];
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
            $exprs["({$name})²"] = array_map(fn ($v) => $v * $v, $vec);
            $l1Sq[] = "({$name})²";
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
            $constKeys = array_filter($featKeys, fn ($k) => str_starts_with($k, 'K'));
            foreach ($l2Keys as $l2name) {
                foreach ($constKeys as $ck) {
                    $vec = [];
                    $cvec = $feats[$ck];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply($exprs[$l2name][$i], $cvec[$i], '/');
                        $vec[] = $r ?? 0.0;
                    }
                    $exprs["({$l2name}/{$ck})"] = $vec;
                }
            }
        }

        // Evaluate FEATURES first (fast path)
        foreach ($feats as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                // NaN/INF не могут быть законом (артефакт переполнения R×)
                if (! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001) {
                    $exact = false;
                    break;
                }
            }
            if ($exact) {
                return [true, 0.0, $name, 0.0, 'EMPIRICAL'];//exact
            }
        }
        // AFFINE-LAWS (ЭКСП-012): сдвиг для знакопеременных целей
        $minY = min($y);
        $maxY = max($y);
        $affineShift = ($minY < 0 && $maxY > 0) ? $minY - 1.0 : 0.0;

        // Evaluate all expressions — top-K кандидатов (SEARCH-TOP-K, ЭКСП-009):
        // на шуме лучший по train часто R-подгонка (CV_test=9.99), а закон
        // (2-я кандидатка, CV_test=0.004) терялся. Храним K лучших по train,
        // выбор — по held-out.
        // SEARCH-TOP-K (ЭКСП-009 + CONCERNS deleg_1ebc06b4): на шуме train-CV
        // не отличает закон от R-подгонок (все ~0.03-0.05), held-out отличает
        // (закон 0.004, подгонки 9.99). top-K по train теряет закон (2x вне
        // top-30). Решение: ВСЕ правдоподобные (CV_train < cvTrainMax),
        // testCv каждого (дёшево), лучший по тесту = закон.
        $plausible = [];
        foreach ($exprs as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                // NaN/INF не могут быть законом (артефакт переполнения R×)
                if (! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001) {
                    $exact = false;
                    break;
                }
            }
            if ($exact) {
                return [true, 0.0, $name, 0.0, 'EMPIRICAL'];//exact
            }

            $std = self::stddev($vec);
            if ($std < 1e-6) {
                continue;
            }
            $cv = self::cv($vec, $y, $affineShift);
            if ($cv < $cvTrainMax) {
                $plausible[] = ['cv' => $cv, 'name' => $name];
            }
        }

        usort($plausible, fn (array $a, array $b): int => $a['cv'] <=> $b['cv']);

        // PARSIMONY: при testRatio=0 (без теста) выбираем короткую среди
        // равных по cv — score = cv + λ·len. При testRatio>0 — в test-блоке.
        if ($testRatio <= 0.0 && ! empty($plausible)) {
            $lambda = self::PARSIMONY_LAMBDA;
            $bestScore = 9.99;
            foreach ($plausible as $cand) {
                $score = $cand['cv'] + $lambda * strlen($cand['name']);
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestCv = $cand['cv'];
                    $bestName = $cand['name'];
                }
            }
        } else {
            $bestCv = $plausible[0]['cv'] ?? 9.99;
            $bestName = $plausible[0]['name'] ?? null;
        }
        $cv_train = $bestCv < $cvTrainMax ? $bestCv : 9.99;
        $cv_test = $cv_train;

        // V0.8.5 + SEARCH-TOP-K: out-of-sample — лучший по ТЕСТУ среди ВСЕХ
        // правдоподобных (R-подгонки с test=9.99 отсеиваются, закон проходит)
        if ($testRatio > 0.0 && $n > 10 && ! empty($plausible)) {
            $splitIdx = (int) ($n * (1.0 - $testRatio));
            if ($splitIdx > 5 && $splitIdx < $n - 1) {
                $X_test = array_slice($X, $splitIdx);
                $y_test = array_slice($y, $splitIdx);
                // PARSIMONY-SELECTION (P0): score = testCv + λ·len —
                // сложные R-тени (len~60) штрафуются при равном testCv
                $lambda = self::PARSIMONY_LAMBDA; // (C) калибруемый: 0.00005/символ
                $bestTestScore = 9.99;
                $bestTestCv = 9.99;
                $bestTestName = null;
                $bestTestTrainCv = 9.99;
                $X_train_cv = array_slice($X, 0, $splitIdx);
                foreach ($plausible as $cand) {
                    $t = self::testCv($cand['name'], $X_test, $y_test, $bestStd ?? 1.0, $n, $colLabels, $X_train_cv);
                    $score = $t + $lambda * strlen($cand['name']);
                    if ($score < $bestTestScore) {
                        $bestTestScore = $score;
                        $bestTestCv = $t;
                        $bestTestName = $cand['name'];
                        $bestTestTrainCv = $cand['cv'];
                    }
                }
                if ($bestTestName !== null) {
                    $bestName = $bestTestName;
                    $cv_test = $bestTestCv;
                    // CONCERNS (deleg_68f5709e + deleg_6ee92a50): cv_train
                    // ПОБЕДИТЕЛЯ — обновлять И $bestCv, И $cv_train
                    $bestCv = $bestTestTrainCv;
                    $cv_train = $bestTestTrainCv;
                } else {
                    // CONCERNS (deleg_1ebc06b4): ни один правдоподобный не
                    // прошёл held-out (все R-подгонки/шум) — честный отказ
                    $bestName = null;
                    $cv_test = 9.99;
                }
            }
        }

        $found = $bestCv < $cvTrainMax && isset($bestName) && $cv_test < $cvTrainMax;
        if (! $found) {
            $cv_train = 9.99;
            $cv_test = 9.99;
        }
        
        $class = $found ? self::classify($cv_train, $cv_test) : 'NONE';
        return [$found, $cv_train, $bestName ?? 'none', $cv_test, $class];

    }


    private static function classify(float $cv_train, float $cv_test): string
    {
        // IDENTITY: perfect on train, fails on test (R-tautology)
        if ($cv_train < 0.02 && $cv_test > 0.5) {
            return 'IDENTITY';
        }
        return 'EMPIRICAL';
    }

    private static function testCv(string $name, array $X_test, array $y_test, float $trainStd, int $n, ?array $colLabels = null, ?array $X_train = null): float
    {
        $nTest = count($y_test);
        if ($nTest < 2) {
            return 9.99;
        }

        // SEARCH-TOP-K (05.08): evaluateHeldout резал переданный тест ещё раз
        // (4 → 3+1) и exact-проверял 1 точку — зашумлённые данные всегда 9.99.
        // CV формулы напрямую по ВСЕМ тестовым точкам через ExpressionEvaluator.
        // Метки колонок (colLabels: 'feature') → xN: evaluator понимает x0..x3.
        if (! empty($colLabels)) {
            $map = [];
            foreach ($colLabels as $i => $label) {
                $map[(string) $label] = "x{$i}";
            }
            uksort($map, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $name = str_replace(array_keys($map), array_values($map), $name);
        }
        // CONCERNS (deleg_6ee92a50): R-статистики фиксируются по TRAIN
        // (константы модели), иначе R-подгонка пересчитывает их на тесте и
        // адаптируется к шуму теста (0.007956 vs 0.008010 — «побеждает»).
        $stats = $X_train !== null
            ? \BeeSwarm\Core\ExpressionEvaluator::collectStats($name, $X_train)
            : [];
        $vec = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($name, $X_test, $stats);
        if ($vec === null || count($vec) !== $nTest) {
            return 9.99;
        }
        $std = self::stddev($vec);
        if ($std < 1e-6) {
            return 9.99;
        }

        // AFFINE-LAWS (CONCERNS deleg_9cf56711): тот же shift, что в train —
        // иначе знакопеременная цель в held-out даёт ложный отказ (found=false
        // при testRatio≥0.3, когда ноль попадает в тест)
        $minY = min($y_test);
        $maxY = max($y_test);
        $affineShift = ($minY < 0 && $maxY > 0) ? $minY - 1.0 : 0.0;

        return self::cv($vec, $y_test, $affineShift);
    }

    private static function stddev(array $v): float
    {
        $n = count($v);
        $mean = array_sum($v) / $n;
        $sq = 0;
        foreach ($v as $x) {
            $sq += ($x - $mean) ** 2;
        }
        return sqrt($sq / $n);
    }
}
