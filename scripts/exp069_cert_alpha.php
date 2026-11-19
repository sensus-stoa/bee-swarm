<?php
declare(strict_types=1);

/**
 * B4.2-серт v3: alpha-нормализованный cert.
 *
 * Идея: fade = c·t^alpha, alpha ячейки известен (log-log slope, замкнутая
 * форма). Нормализуем цель: y' = fade / t^alpha — если форма верна, y' ≈ c
 * (константа ячейки) с шумом. Серт не формы t², а КОНСТАНТНОСТИ нормализованной
 * цели: candidate-форма = K1 (константа) относительно y'.
 *
 * Механически: cert_x('(K1×K1)', ...) даст константу 1 — не то. Правильно:
 * проверяем cv формы-константы на y' через ratio+MAE с anchor: pred=const=m̂,
 * m̂ = median(y') на TRAIN, cv на TEST = std/mean(y') — это просто CV
 * константы. Серт = 3/3 сплита cv(const) <= порог.
 *
 * Дополнительно (честность): alpha оценивается на TRAIN-половине каждого
 * сплита (без утечки: slope считается на train-точках), y' на test строится
 * с train-alpha.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';

$lines = file(__DIR__ . '/../battery_b2/unified_task_gid.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($lines);
$byCell = [];
foreach ($lines as $l) {
    $v = str_getcsv(trim($l));
    if (count($v) < 6) { continue; }
    $byCell[$v[0]][] = [(float) $v[1], 1.0 - (float) $v[5], (float) $v[2], (float) $v[3]];
}

/** alpha по train-точкам: OLS log(fade) ~ log(t), t>t0, fade>fade_min */
function alphaTrain(array $tr): ?float
{
    $S = [];
    foreach ($tr as $p) {
        if ($p[0] > 0.3 && $p[1] > 1e-5) { $S[] = [log($p[0]), log($p[1])]; }
    }
    $m = count($S);
    if ($m < 4) { return null; }
    $mx = array_sum(array_column($S, 0)) / $m;
    $my = array_sum(array_column($S, 1)) / $m;
    $n = 0.0; $d = 0.0;
    foreach ($S as $s) { $n += ($s[0] - $mx) * ($s[1] - $my); $d += ($s[0] - $mx) ** 2; }
    return $d > 1e-12 ? $n / $d : null;
}

/** cv константы-предиктора на test: min(ratio-cv, MAE/mean|y|) — как cert_x */
function constCv(array $yte): ?float
{
    $m = array_sum($yte) / max(1, count($yte));
    if ($m == 0.0) { return null; }
    $cands = [];
    $var = 0.0;
    foreach ($yte as $yv) { $var += ($yv - $m) ** 2; }
    $cands[] = sqrt($var / count($yte)) / abs($m);
    $ae = 0.0; $ma = 0.0;
    foreach ($yte as $yv) { $ae += abs($yv - $m); $ma += abs($yv); }
    if ($ma > 0) { $cands[] = ($ae / count($yte)) / ($ma / count($yte)); }
    return min($cands);
}

$real = []; $null = [];
$alphasReal = [];
foreach ($byCell as $cid => $pts) {
    if (count($pts) < 12) { continue; }
    $chem = $pts[0][2] > 0.5 ? 'NMC' : ($pts[0][3] > 0.5 ? 'LFP' : 'LCO');
    $Xc = []; $yc = [];
    foreach ($pts as $p) { $Xc[] = [$p[0]]; $yc[] = $p[1]; }
    $cvs = []; $alphaUsed = null;
    $ok = true;
    for ($sp = 0; $sp < 3; $sp++) {
        [$Xtr, $ytr, $Xte, $yte] = split_eval($Xc, $yc, 424000 + $sp * 104729);
        $pairs = [];
        foreach ($Xtr as $i => $x) { $pairs[] = [$x[0], $ytr[$i]]; }
        $al = alphaTrain($pairs);
        if ($al === null) { $ok = false; break; }
        $alphaUsed = $al;
        // y' = fade / t^alpha на train: m̂ = median
        $yp = [];
        foreach ($Xtr as $i => $x) { $t = $x[0]; $yp[] = $t > 1e-9 ? $ytr[$i] / pow($t, $al) : null; }
        $yp = array_values(array_filter($yp, fn ($v) => $v !== null && is_finite($v)));
        if (!$yp) { $ok = false; break; }
        sort($yp);
        $m = $yp[(int) (count($yp) / 2)];
        if ($m == 0.0) { $ok = false; break; }
        // test: y'_te = fade/t^alpha, cv константы m
        $ypt = [];
        foreach ($Xte as $i => $x) { $t = $x[0]; if ($t > 1e-9) { $ypt[] = $yte[$i] / pow($t, $al); } }
        $cv = constCv($ypt);
        if ($cv === null) { $ok = false; break; }
        $cvs[] = $cv;
    }
    if (!$ok || count($cvs) < 3) { continue; }
    $real[$chem][] = max($cvs);
    $alphasReal[$chem][] = $alphaUsed;
    if (count($null) < 40) {
        $yp2 = $yc;
        mt_srand(9100 + count($null));
        for ($i = count($yp2) - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$yp2[$i], $yp2[$j]] = [$yp2[$j], $yp2[$i]]; }
        $cn = []; $ok2 = true;
        for ($sp = 0; $sp < 3; $sp++) {
            [$Xtr, $ytr, $Xte, $yte] = split_eval($Xc, $yp2, 424000 + $sp * 104729);
            $pairs = [];
            foreach ($Xtr as $i => $x) { $pairs[] = [$x[0], $ytr[$i]]; }
            $al = alphaTrain($pairs);
            if ($al === null) { $ok2 = false; break; }
            $yy = [];
            foreach ($Xtr as $i => $x) { $t = $x[0]; if ($t > 1e-9) { $yy[] = $ytr[$i] / pow($t, $al); } }
            if (!$yy) { $ok2 = false; break; }
            sort($yy);
            $m = $yy[(int) (count($yy) / 2)];
            if ($m == 0.0) { $ok2 = false; break; }
            $ypt = [];
            foreach ($Xte as $i => $x) { $t = $x[0]; if ($t > 1e-9) { $ypt[] = $yte[$i] / pow($t, $al); } }
            $cv = constCv($ypt);
            if ($cv === null) { $ok2 = false; break; }
            $cn[] = $cv;
        }
        if ($ok2 && count($cn) === 3) { $null[] = max($cn); }
    }
}

function q(array $a, float $p): float
{
    if (!$a) { return NAN; }
    sort($a);
    return $a[(int) ($p * (count($a) - 1))];
}

echo "alpha-нормализованный cert (alpha с TRAIN, const-cv на TEST, порог 0.10):\n";
$totP = 0; $totN = 0;
foreach (['NMC', 'LFP', 'LCO'] as $chem) {
    if (!isset($real[$chem])) { continue; }
    $a = $real[$chem];
    $pass = count(array_filter($a, fn ($v) => $v <= 0.10));
    $totP += $pass; $totN += count($a);
    $am = $alphasReal[$chem];
    sort($am);
    printf("  %-4s: %d/%d (%.0f%%) | медиана cv=%.3f 10%%=%.3f | alpha медиана=%.2f IQR=[%.2f,%.2f]\n",
        $chem, $pass, count($a), 100 * $pass / count($a), q($a, 0.5), q($a, 0.1),
        q($am, 0.5), q($am, 0.25), q($am, 0.75));
}
echo "итого: $totP/$totN ячеек сертифицировано\n";
if ($null) {
    $np = count(array_filter($null, fn ($v) => $v <= 0.10));
    echo 'null: ' . $np . '/' . count($null) . " (медиана " . round(q($null, 0.5), 3) . ", 95% " . round(q($null, 0.95), 3) . ")\n";
}
