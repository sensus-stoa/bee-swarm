<?php
declare(strict_types=1);
require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);
$DIR = __DIR__ . '/../Benchmarks/mgrowth_smgdb11';
$REPS = ['V09', 'V10', 'V11', 'V12'];
function loadF(string $DIR, string $f): array {
    $raw = openpyxl_load($DIR . '/' . $f);
    $h = array_map(fn ($v) => trim((string) $v), $raw[0]);
    $rows = [];
    foreach (array_slice($raw, 1) as $r) { if (count($r) < 3 || trim((string) $r[0]) === '') continue; $rows[] = $r; }
    return [$h, $rows];
}
[$hM, $metabRows] = loadF($DIR, 'growth_data_per_metabolite.csv');
[$hS, $strainRows] = loadF($DIR, 'growth_data_per_strain.csv');
$mcols = array_slice($hM, 3);
$scols = array_values(array_filter(array_slice($hS, 3), fn ($c) => stripos($c, 'Unknown') === false));
$SPECS = []; foreach ($scols as $c) { $SPECS[] = substr($c, 0, strpos($c, ' rRNA') ?: strlen($c)); }
$mk = []; $sk = [];
foreach ($metabRows as $r) { $rep = trim($r[0]); $t = (float) $r[2]; foreach ($mcols as $j => $c) $mk["$rep|$t"][$c] = is_numeric($r[3 + $j]) ? (float) $r[3 + $j] : null; }
foreach ($strainRows as $r) { $rep = trim($r[0]); $t = (float) $r[2]; foreach ($scols as $j => $c) $sk["$rep|$t"][$c] = is_numeric($r[3 + $j]) ? (float) $r[3 + $j] : null; }
$sp = $SPECS[0]; $full = $scols[0];
$rows = [];
foreach ($REPS as $rep) {
    $ts = []; foreach ($mk as $k => $v) if (str_starts_with($k, "$rep|")) $ts[] = (float) substr($k, strlen($rep) + 1);
    sort($ts);
    $cnt = -1;
    for ($i = 0; $i < count($ts) - 1; $i++) {
        $t0 = $ts[$i]; $t1 = $ts[$i + 1]; $dt = $t1 - $t0; if ($dt <= 0) continue;
        if ($t0 > 21) continue;
        $m0 = $mk["$rep|$t0"] ?? null; $m1 = $mk["$rep|$t1"] ?? null; $s0 = $sk["$rep|$t0"] ?? null; $s1 = $sk["$rep|$t1"] ?? null;
        if (!$m0 || !$m1 || !$s0 || !$s1) continue;
        $n0 = $s0[$full] ?? null; $n1 = $s1[$full] ?? null; if (!$n0 || !$n1 || $n0 <= 0) continue;
        $y = log($n1 / $n0) / $dt; $feats = []; $ok = true;
        foreach ($mcols as $mc) { $v0 = $m0[$mc]; $v1 = $m1[$mc]; if ($v0 === null || $v1 === null) { $ok = false; break; } $feats[$mc] = ($v0 + $v1) / 2; }
        if (!$ok) continue;
        $rows[] = ['rep' => $rep, 't0' => $t0, 'y' => $y, 'feats' => $feats, '_idx' => ++$cnt];
    }
}
$vec = function ($spec, $rows) { $v = []; foreach ($rows as $r) $v[] = $r['feats'][$spec[1]]; return $v; };
$lodoR2 = function ($rows, $spec) use ($REPS, $vec) {
    $r2s = [];
    foreach ($REPS as $hold) {
        $train = array_values(array_filter($rows, fn ($d) => $d['rep'] !== $hold));
        $test = array_values(array_filter($rows, fn ($d) => $d['rep'] === $hold));
        if (count($train) < 6 || count($test) < 4) continue;
        $ytr = array_column($train, 'y'); $xtr = $vec($spec, $train);
        $n = count($train); $my = array_sum($ytr) / $n; $mx = array_sum($xtr) / $n;
        $sxy = 0; $sxx = 0;
        for ($i = 0; $i < $n; $i++) { $sxy += ($xtr[$i] - $mx) * ($ytr[$i] - $my); $sxx += ($xtr[$i] - $mx) ** 2; }
        if ($sxx < 1e-12) continue;
        $b = $sxy / $sxx; $a = $my - $b * $mx;
        $yte = array_column($test, 'y'); $xte = $vec($spec, $test);
        $sse = 0; $tot = 0; $myte = array_sum($yte) / count($yte);
        for ($i = 0; $i < count($test); $i++) { $sse += ($yte[$i] - ($a + $b * $xte[$i])) ** 2; $tot += ($yte[$i] - $myte) ** 2; }
        $r2s[] = $tot > 1e-12 ? 1 - $sse / $tot : NAN;
    }
    $valid = array_values(array_filter($r2s, 'is_finite'));
    if (count($valid) < 3) return NAN;
    sort($valid); return $valid[intdiv(count($valid), 2)];
};
$spec = ['lin', 'pyruvate'];
echo "реальный: ", $lodoR2($rows, $spec), "\n";
mt_srand(424242);
$repY = []; foreach ($rows as $r) $repY[$r['rep']][] = $r['y'];
for ($p = 0; $p < 12; $p++) {
    $shifts = []; foreach ($repY as $rep => $ys) $shifts[$rep] = mt_rand(1, max(1, count($ys) - 1));
    $rows2 = [];
    foreach ($rows as $r) { $r2 = $r; $ys = $repY[$r['rep']]; $nY = count($ys); $r2['y'] = $ys[($r['_idx'] + $shifts[$r['rep']]) % $nY]; $rows2[] = $r2; }
    echo "  null: ", $lodoR2($rows2, $spec), "\n";
}
// вопрос: сколько интервалов в growth-режиме у BT? 5 интервалов * 4 реплики = 20. y-ряд per rep длиной 5.
echo "n_per_rep = ", count(array_filter($rows, fn ($r) => $r['rep'] === 'V09')), "\n";
