<?php
declare(strict_types=1);

/**
 * B7.1: SMGDB00000011 — per-вид задача на концентрациях (не log2fold).
 *
 * Постановка (аналог T3 Venturelli, но по-честному временной):
 *   y_i(t_k) = dln(N_i)/dt на интервале [t_k, t_k+1]  (absolute Cells/mL)
 *   фичи = средние концентрации метаболитов на том же интервале
 *   (12 метаболитов, mM) + pH интервала.
 *
 * Данные: 4 био-реплики x 13 t-точек, 6 видов + Unknown (исключаем).
 * Единица независимости — био-реплика (LODO по V09-V12).
 * Временной holdout: t<=93 (train) -> t>93 (test).
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$DIR = __DIR__ . '/../Benchmarks/mgrowth_smgdb11';

// --- загрузка ---
$load = function (string $f) use ($DIR): array {
    $rows = [];
    foreach (openpyxl_load($DIR . '/' . $f) as $i => $r) {
        if ($i === 0) { continue; }
        if (count($r) < 3 || trim((string) $r[0]) === '') { continue; }
        $rows[] = $r;
    }
    return $rows;
};

$metabRows = $load('growth_data_per_metabolite.csv');
$strainRows = $load('growth_data_per_strain.csv');
$phRows = $load('community_ph.csv');

$headerM = null;
foreach (openpyxl_load($DIR . '/growth_data_per_metabolite.csv') as $i => $r) {
    if ($i === 0) { $headerM = array_map(fn ($v) => trim((string) $v), $r); break; }
}
$headerS = null;
foreach (openpyxl_load($DIR . '/growth_data_per_strain.csv') as $i => $r) {
    if ($i === 0) { $headerS = array_map(fn ($v) => trim((string) $v), $r); break; }
}
$mcols = array_slice($headerM, 3);
$scols = array_slice($headerS, 3);
// исключаем Unknown
$scols = array_values(array_filter($scols, fn ($c) => stripos($c, 'Unknown') === false));

echo "видов: ", count($scols), " | метаболитов: ", count($mcols), "\n";
foreach ($scols as $c) { echo "  ", substr($c, 0, 50), "\n"; }

// индексация: metab[(rep, t)] = [м => значение]
$mk = [];
foreach ($metabRows as $r) {
    $rep = trim($r[0]); $t = (float) $r[2];
    $vals = [];
    foreach ($mcols as $j => $c) { $vals[$c] = is_numeric($r[3 + $j]) ? (float) $r[3 + $j] : null; }
    $mk["$rep|$t"] = $vals;
}
$sk = [];
foreach ($strainRows as $r) {
    $rep = trim($r[0]); $t = (float) $r[2];
    $vals = [];
    foreach ($scols as $j => $c) { $vals[$c] = is_numeric($r[3 + $j]) ? (float) $r[3 + $j] : null; }
    $sk["$rep|$t"] = $vals;
}
$pk = [];
foreach ($phRows as $r) {
    $rep = trim($r[0]); $t = (float) $r[2];
    $pk["$rep|$t"] = is_numeric($r[3]) ? (float) $r[3] : null;
}

// --- сборка интервалов: [t_k, t_k+1], dt в часах ---
$build = function (array $reps) use ($mk, $sk, $pk, $mcols, $scols): array {
    $out = [];
    foreach ($reps as $rep) {
        $ts = [];
        foreach ($mk as $k => $v) { if (str_starts_with($k, "$rep|")) { $ts[] = (float) substr($k, strlen($rep) + 1); } }
        sort($ts);
        for ($i = 0; $i < count($ts) - 1; $i++) {
            $t0 = $ts[$i]; $t1 = $ts[$i + 1];
            $dt = $t1 - $t0;
            if ($dt <= 0) { continue; }
            $m0 = $mk["$rep|$t0"] ?? null; $m1 = $mk["$rep|$t1"] ?? null;
            $s0 = $sk["$rep|$t0"] ?? null; $s1 = $sk["$rep|$t1"] ?? null;
            if (!$m0 || !$m1 || !$s0 || !$s1) { continue; }
            foreach ($scols as $sc) {
                $n0 = $s0[$sc]; $n1 = $s1[$sc];
                if (!$n0 || !$n1 || $n0 <= 0) { continue; }
                $y = log($n1 / $n0) / $dt; // per-hour specific growth
                // фичи: средняя концентрация на интервале
                $feats = [];
                $ok = true;
                foreach ($mcols as $mc) {
                    $v0 = $m0[$mc]; $v1 = $m1[$mc];
                    if ($v0 === null || $v1 === null) { $ok = false; break; }
                    $feats[$mc] = ($v0 + $v1) / 2;
                }
                if (!$ok) { continue; }
                // pH интервала (если есть t0)
                $ph = $pk["$rep|$t0"] ?? null;
                $out[] = ['rep' => $rep, 't0' => $t0, 't1' => $t1, 'species' => $sc,
                          'y' => $y, 'feats' => $feats, 'ph' => $ph];
            }
        }
    }
    return $out;
};

$ALL = ['V09', 'V10', 'V11', 'V12'];
$data = $build($ALL);
printf("интервалов: %d (%d реплик x 12 t-интервалов x 6 видов)\n", count($data), count($ALL));

// описательная статистика y по видам
$bySpecies = [];
foreach ($data as $d) { $bySpecies[$d['species']][] = $d['y']; }
echo "\n=== y (per-hour dln N) по видам: mean [min..max] ===\n";
foreach ($bySpecies as $sp => $ys) {
    $n = count($ys);
    printf("  %-42.42s n=%2d  mean=%+.3f  [%+.3f..%+.3f]\n", $sp, $n,
        array_sum($ys) / $n, min($ys), max($ys));
}

// корреляции фич с y по каждому виду (без поправок, только картина)
echo "\n=== corr(метаболит, y) по видам (|r|>0.5 помечены) ===\n";
$mcolsList = array_keys($data[0]['feats']);
foreach ($scols as $sp) {
    $rowsSp = array_values(array_filter($data, fn ($d) => $d['species'] === $sp));
    $n = count($rowsSp);
    if ($n < 8) { continue; }
    $y = array_column($rowsSp, 'y');
    $top = [];
    foreach ($mcolsList as $mc) {
        $x = array_column($rowsSp, 'feats');
        $xs = array_column($rowsSp, 'feats');
        $xv = []; foreach ($rowsSp as $r) { $xv[] = $r['feats'][$mc]; }
        $my = array_sum($y) / $n; $mx = array_sum($xv) / $n;
        $sxy = 0; $sxx = 0; $syy = 0;
        for ($i = 0; $i < $n; $i++) {
            $sxy += ($xv[$i] - $mx) * ($y[$i] - $my);
            $sxx += ($xv[$i] - $mx) ** 2; $syy += ($y[$i] - $my) ** 2;
        }
        if ($sxx > 1e-12 && $syy > 1e-12) {
            $r = $sxy / sqrt($sxx * $syy);
            if (abs($r) > 0.5) { $top[] = sprintf('%s: %+.2f', $mc, $r); }
        }
    }
    if ($top) { printf("  %-42.42s  %s\n", $sp, implode('  ', $top)); }
}

// LODO по репликам для одномерной формы: y ~ acetate (SCFA-ось) и ноукруг
// Просто демонстрация механики: LSQ train 3 реплики -> R2 на 4-й
$lsqFit = function (array $rows, string $feat): array {
    $n = count($rows);
    $y = array_column($rows, 'y');
    $x = []; foreach ($rows as $r) { $x[] = $r['feats'][$feat]; }
    $my = array_sum($y) / $n; $mx = array_sum($x) / $n;
    $sxy = 0; $sxx = 0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($x[$i] - $mx) * ($y[$i] - $my); $sxx += ($x[$i] - $mx) ** 2; }
    if ($sxx < 1e-12) { return ['a' => $my, 'b' => 0.0]; }
    return ['a' => $my - ($sxy / $sxx) * $mx, 'b' => $sxy / $sxx, 'mx' => $mx];
};
echo "\n=== LODO по репликам: форма y ~ acetate (демо механики) ===\n";
foreach ($scols as $sp) {
    $r2s = [];
    foreach ($ALL as $hold) {
        $train = array_values(array_filter($data, fn ($d) => $d['species'] === $sp && $d['rep'] !== $hold));
        $test = array_values(array_filter($data, fn ($d) => $d['species'] === $sp && $d['rep'] === $hold));
        if (count($train) < 5 || count($test) < 3) { continue; }
        $fit = $lsqFit($train, 'acetate');
        $sse = 0; $tot = 0;
        $myTest = array_sum(array_column($test, 'y')) / count($test);
        foreach ($test as $r) {
            $pred = $fit['a'] + $fit['b'] * ($r['feats']['acetate']);
            $sse += ($r['y'] - $pred) ** 2; $tot += ($r['y'] - $myTest) ** 2;
        }
        $r2s[] = $tot > 1e-12 ? 1 - $sse / $tot : NAN;
    }
    $valid = array_filter($r2s, 'is_finite');
    if ($valid) {
        printf("  %-42.42s holdout R2 по 4 фолдам: %s медиана %+.3f\n", $sp,
            implode(' ', array_map(fn ($v) => is_finite($v) ? sprintf('%+.2f', $v) : 'n/a', $r2s)),
            $valid ? (function () use ($valid) { $v = array_values($valid); sort($v); return $v[intdiv(count($v), 2)]; })() : NAN);
    }
}
