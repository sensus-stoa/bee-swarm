<?php
declare(strict_types=1);
// B4.2-серт v2: per-cell cert с anchor-inversion (масштаб c подгоняется на TRAIN)
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\ExpressionEvaluator;

$lines = file(__DIR__ . '/../battery_b2/unified_task_gid.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($lines);
$byCell = [];
foreach ($lines as $l) {
    $v = str_getcsv(trim($l));
    if (count($v) < 6) { continue; }
    $byCell[$v[0]][] = [(float) $v[1], 1.0 - (float) $v[5], (float) $v[2], (float) $v[3]];
}

$med = function (array $a): float {
    if (!$a) { return 0.0; }
    sort($a);
    return $a[(int) (count($a) / 2)];
};

// cert одного сплита: anchor (median y/pred) на train, ratio+MAE cv на test
$certSplit = function (array $Xtr, array $ytr, array $Xte, array $yte) use ($med): ?float {
    $pvTr = ExpressionEvaluator::evaluateFormula('(x0×x0)', $Xtr);
    $pvTe = ExpressionEvaluator::evaluateFormula('(x0×x0)', $Xte);
    if (!is_array($pvTr) || !is_array($pvTe)) { return null; }
    $ratiosTr = [];
    foreach ($ytr as $i => $yv) { if ((float) $yv != 0.0) { $ratiosTr[] = $pvTr[$i] / (float) $yv; } }
    if (!$ratiosTr) { return null; }
    $m = $med($ratiosTr);
    if ($m == 0.0 || !is_finite($m)) { return null; }
    // TEST: два канала
    $ratiosTe = [];
    $ae = 0.0;
    foreach ($yte as $i => $yv) {
        $pred = $pvTe[$i] * $m;
        if ((float) $yv != 0.0) { $ratiosTe[] = $pred / (float) $yv; }
        $ae += abs($pred - (float) $yv);
    }
    $cands = [];
    if ($ratiosTe) {
        $mean = array_sum($ratiosTe) / count($ratiosTe);
        if ($mean != 0.0) {
            $var = 0.0;
            foreach ($ratiosTe as $rv) { $var += ($rv - $mean) ** 2; }
            $cands[] = sqrt($var / count($ratiosTe)) / abs($mean);
        }
    }
    $meanAbsY = $med(array_map('abs', $yte));
    if ($meanAbsY > 0) { $cands[] = ($ae / count($yte)) / $meanAbsY; }
    return $cands ? min($cands) : null;
};

$real = []; $null = [];
foreach ($byCell as $cid => $pts) {
    if (count($pts) < 12) { continue; }
    $Xc = []; $yc = [];
    foreach ($pts as $p) { $Xc[] = [$p[0]]; $yc[] = $p[1]; }
    $cvs = [];
    for ($sp = 0; $sp < 3; $sp++) {
        [, , $Xe, $ye] = split_eval($Xc, $yc, 424000 + $sp * 104729);
        $cvs[] = $certSplit($Xc, $yc, $Xe, $ye);
    }
    $cvs = array_filter($cvs, fn ($v) => $v !== null);
    if ($cvs) { $real[] = max($cvs); } // cert-логика: все три <= порог -> берём max
    if (count($null) < 40) {
        $yp = $yc;
        mt_srand(9100 + count($null));
        for ($i = count($yp) - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$yp[$i], $yp[$j]] = [$yp[$j], $yp[$i]]; }
        $cn = [];
        for ($sp = 0; $sp < 3; $sp++) {
            [, , $Xe, $ye] = split_eval($Xc, $yp, 424000 + $sp * 104729);
            $cn[] = $certSplit($Xc, $yp, $Xe, $ye);
        }
        $cn = array_filter($cn, fn ($v) => $v !== null);
        if ($cn) { $null[] = max($cn); }
    }
}
sort($real); sort($null);
$q = function (array $a, float $p) { return $a[(int) ($p * (count($a) - 1))]; };
echo 'n_cells=' . count($real) . "\n";
echo 'REAL (anchor, max-3): 10%=' . round($q($real, 0.1), 3) . ' 25%=' . round($q($real, 0.25), 3) . ' 50%=' . round($q($real, 0.5), 3) . "\n";
echo 'NULL (anchor, max-3): 50%=' . round($q($null, 0.5), 3) . ' 95%=' . round($q($null, 0.95), 3) . "\n";
foreach ([0.10, 0.15, 0.20, 0.30, 0.50] as $t) {
    $rp = round(100 * count(array_filter($real, fn ($v) => $v <= $t)) / max(1, count($real)));
    $np = round(100 * count(array_filter($null, fn ($v) => $v <= $t)) / max(1, count($null)));
    echo "порог $t: real $rp% | null $np%\n";
}
