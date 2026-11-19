<?php
declare(strict_types=1);

/**
 * B7.2: Форменный перебор на SMGDB00000011 с честным гейтом.
 *
 * Грамматика форм (та же, что Venturelli B6): lin:MC | mul:MC1:MC2 | over:MC
 * Глубина 1-2 (n мал: 44 интервала/вид; глубже нельзя — переобучение).
 *
 * ГЕЙТ (последовательный, все три обязательны):
 *  1. LODO-реплики (4 фолда): медиана holdout R2 > 0
 *  2. Временной holdout: фит t<=93, тест t>93, R2 > 0 (и фит-R2 > 0)
 *  3. Null block-permutation: 300 перестановок ЦЕЛЫХ реплик
 *     (рвём связь реплика->y, сохраняя временную автокорреляцию)
 *     p < 0.05
 *  4. Null sign-flip: 300 флипов знака log2fold-концентраций
 *     (здесь: флип знака отклонения концентрации от среднего по репликам
 *     в t-точке — сохраняем магнетуды, ломаем направление)
 *     p < 0.05
 *
 * Множественные сравнения: 12 видов-метаболитных семей x ~24 формы =
 * ~280 тестов на вид; Benjamini-Hochberg FDR q<0.10 на семейство null-p.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$DIR = __DIR__ . '/../Benchmarks/mgrowth_smgdb11';
$REPS = ['V09', 'V10', 'V11', 'V12'];

$load = function (string $f) use ($DIR): array {
    $raw = openpyxl_load($DIR . '/' . $f);
    $header = array_map(fn ($v) => trim((string) $v), $raw[0]);
    $rows = [];
    foreach (array_slice($raw, 1) as $r) {
        if (count($r) < 3 || trim((string) $r[0]) === '') { continue; }
        $rows[] = $r;
    }
    return [$header, $rows];
};
[$hM, $metabRows] = $load('growth_data_per_metabolite.csv');
[$hS, $strainRows] = $load('growth_data_per_strain.csv');
$mcols = array_slice($hM, 3);
$scols = array_values(array_filter(array_slice($hS, 3), fn ($c) => stripos($c, 'Unknown') === false));
$SPECS = [];
foreach ($scols as $c) { $SPECS[] = substr($c, 0, strpos($c, ' rRNA') ?: strlen($c)); }

$mk = []; $sk = [];
foreach ($metabRows as $r) {
    $rep = trim($r[0]); $t = (float) $r[2];
    foreach ($mcols as $j => $c) { $mk["$rep|$t"][$c] = is_numeric($r[3 + $j]) ? (float) $r[3 + $j] : null; }
}
foreach ($strainRows as $r) {
    $rep = trim($r[0]); $t = (float) $r[2];
    foreach ($scols as $j => $c) { $sk["$rep|$t"][$c] = is_numeric($r[3 + $j]) ? (float) $r[3 + $j] : null; }
}

// интервалы per вид
$build = function (string $sp, array $reps) use ($mk, $sk, $mcols, $scols, $SPECS): array {
    $full = $scols[array_search($sp, $SPECS, true)];
    $out = [];
    foreach ($reps as $rep) {
        $ts = [];
        foreach ($mk as $k => $v) { if (str_starts_with($k, "$rep|")) { $ts[] = (float) substr($k, strlen($rep) + 1); } }
        sort($ts);
        for ($i = 0; $i < count($ts) - 1; $i++) {
            $t0 = $ts[$i]; $t1 = $ts[$i + 1]; $dt = $t1 - $t0;
            if ($dt <= 0) { continue; }
            $m0 = $mk["$rep|$t0"] ?? null; $m1 = $mk["$rep|$t1"] ?? null;
            $s0 = $sk["$rep|$t0"] ?? null; $s1 = $sk["$rep|$t1"] ?? null;
            if (!$m0 || !$m1 || !$s0 || !$s1) { continue; }
            $n0 = $s0[$full] ?? null; $n1 = $s1[$full] ?? null;
            if (!$n0 || !$n1 || $n0 <= 0) { continue; }
            $y = log($n1 / $n0) / $dt;
            $feats = []; $ok = true;
            foreach ($mcols as $mc) {
                $v0 = $m0[$mc]; $v1 = $m1[$mc];
                if ($v0 === null || $v1 === null) { $ok = false; break; }
                $feats[$mc] = ($v0 + $v1) / 2;
            }
            if (!$ok) { continue; }
            $out[] = ['rep' => $rep, 't0' => $t0, 'y' => $y, 'feats' => $feats];
        }
    }
    return $out;
};

// вектор формы
$vec = function (array $spec, array $rows): array {
    $kind = $spec[0];
    $v = [];
    if ($kind === 'lin') {
        foreach ($rows as $r) { $v[] = $r['feats'][$spec[1]]; }
    } elseif ($kind === 'over') {
        $xmax = 0;
        foreach ($rows as $r) { $xmax = max($xmax, $r['feats'][$spec[1]]); }
        foreach ($rows as $r) { $v[] = $r['feats'][$spec[1]] / max($xmax, 1e-9); }
    } elseif ($kind === 'mul') {
        $m2 = $spec[2];
        $min = INF; $max = -INF;
        foreach ($rows as $r) { $val = $r['feats'][$m2]; $min = min($min, $val); $max = max($max, $val); }
        foreach ($rows as $r) {
            $norm = (abs($max - $min) > 1e-9) ? ($r['feats'][$m2] - $min) / ($max - $min) : 0.0;
            $v[] = $r['feats'][$spec[1]] * $norm;
        }
    }
    return $v;
};

$fitR2 = function (array $rows, array $spec) use ($vec): float {
    $n = count($rows);
    if ($n < 6) { return NAN; }
    $y = array_column($rows, 'y');
    $x = $vec($spec, $rows);
    $my = array_sum($y) / $n; $mx = array_sum($x) / $n;
    $sxy = 0; $sxx = 0; $syy = 0;
    for ($i = 0; $i < $n; $i++) {
        $sxy += ($x[$i] - $mx) * ($y[$i] - $my);
        $sxx += ($x[$i] - $mx) ** 2; $syy += ($y[$i] - $my) ** 2;
    }
    if ($sxx < 1e-12 || $syy < 1e-12) { return NAN; }
    return ($sxy / sqrt($sxx * $syy)) ** 2 * (($sxy > 0) ? 1 : 1); // r2
};

// LODO: фит на 3 репликах (LSQ b) -> R2 на 4-й
$lodoR2 = function (array $rows, array $spec) use ($REPS, $vec): float {
    $r2s = [];
    foreach ($REPS as $hold) {
        $train = array_values(array_filter($rows, fn ($d) => $d['rep'] !== $hold));
        $test = array_values(array_filter($rows, fn ($d) => $d['rep'] === $hold));
        if (count($train) < 6 || count($test) < 4) { continue; }
        $ytr = array_column($train, 'y');
        $xtr = $vec($spec, $train);
        $n = count($train);
        $my = array_sum($ytr) / $n; $mx = array_sum($xtr) / $n;
        $sxy = 0; $sxx = 0;
        for ($i = 0; $i < $n; $i++) { $sxy += ($xtr[$i] - $mx) * ($ytr[$i] - $my); $sxx += ($xtr[$i] - $mx) ** 2; }
        if ($sxx < 1e-12) { continue; }
        $b = $sxy / $sxx; $a = $my - $b * $mx;
        // R2 на test
        $yte = array_column($test, 'y');
        $xte = $vec($spec, $test);
        $sse = 0; $tot = 0;
        $myte = array_sum($yte) / count($yte);
        for ($i = 0; $i < count($test); $i++) {
            $sse += ($yte[$i] - ($a + $b * $xte[$i])) ** 2;
            $tot += ($yte[$i] - $myte) ** 2;
        }
        $r2s[] = $tot > 1e-12 ? 1 - $sse / $tot : NAN;
    }
    $valid = array_values(array_filter($r2s, 'is_finite'));
    if (count($valid) < 3) { return NAN; }
    sort($valid);
    return $valid[intdiv(count($valid), 2)];
};

// временной holdout: фит t<=93 -> R2 t>93
$timeR2 = function (array $rows, array $spec) use ($vec): float {
    $tr = array_values(array_filter($rows, fn ($d) => $d['t0'] <= 93));
    $te = array_values(array_filter($rows, fn ($d) => $d['t0'] > 93));
    if (count($tr) < 8 || count($te) < 5) { return NAN; }
    $ytr = array_column($tr, 'y'); $xtr = $vec($spec, $tr);
    $n = count($tr);
    $my = array_sum($ytr) / $n; $mx = array_sum($xtr) / $n;
    $sxy = 0; $sxx = 0; $syytr = 0;
    for ($i = 0; $i < $n; $i++) {
        $sxy += ($xtr[$i] - $mx) * ($ytr[$i] - $my);
        $sxx += ($xtr[$i] - $mx) ** 2; $syytr += ($ytr[$i] - $my) ** 2;
    }
    if ($sxx < 1e-12) { return NAN; }
    $b = $sxy / $sxx; $a = $my - $b * $mx;
    $yte = array_column($te, 'y'); $xte = $vec($spec, $te);
    $sse = 0; $tot = 0;
    $myte = array_sum($yte) / count($te);
    for ($i = 0; $i < count($te); $i++) {
        $sse += ($yte[$i] - ($a + $b * $xte[$i])) ** 2;
        $tot += ($yte[$i] - $myte) ** 2;
    }
    return $tot > 1e-12 ? 1 - $sse / $tot : NAN;
};

// null 1: block-permutation — перемешиваем ЦЕЛЫЕ реплики (связь rep->y рвётся, временная структура внутри реплики сохранена)
// реализация: сборка rep->(t->y) отдельно от rep->(t->feats); перестановка назначений rep-блоков y
$nullBlock = function (array $rows, array $spec, int $seed, int $N) use ($lodoR2): array {
    mt_srand($seed);
    // rep -> список y в исходном порядке интервалов
    $repY = [];
    foreach ($rows as $r) { $repY[$r['rep']][] = $r['y']; }
    $reps = array_keys($repY);
    $count = 0;
    for ($p = 0; $p < $N; $p++) {
        // перемешать блоки y между репликами
        $shuf = $repY;
        $vals = array_values($repY);
        shuffle($vals);
        $shuf = array_combine($reps, $vals);
        $rows2 = [];
        foreach ($rows as $r) {
            $r2 = $r;
            $r2['y'] = array_shift($shuf[$r['rep']]);
            $rows2[] = $r2;
        }
        $r2 = $lodoR2($rows2, $spec);
        if (is_finite($r2)) { $count++; }
    }
    return [$count, $N];
};

// null 2: sign-flip — флип знака отклонения концентрации от среднего по репликам в t-точке
$nullSign = function (array $rows, array $spec, int $seed, int $N) use ($mk, $mcols, $vec, $lodoR2): array {
    mt_srand($seed);
    // сначала: средняя концентрация по репликам на каждый t (по t0)
    $tMean = [];
    foreach ($rows as $r) { $tMean[$r['t0']][$r['rep']] = $r['feats']; }
    $devRows = []; // отклонения от среднего (реплика-специфичные)
    foreach ($rows as $r) {
        $t = $r['t0'];
        $avg = [];
        foreach ($mcols as $mc) {
            $s = 0; $c = 0;
            foreach ($tMean[$t] as $rep => $feats) { $s += $feats[$mc]; $c++; }
            $avg[$mc] = $s / max($c, 1);
        }
        $devRows[] = $avg;
    }
    $count = 0;
    for ($p = 0; $p < $N; $p++) {
        // флип знака всех dev целиком (per-реплика)
        $flip = (mt_rand() / mt_getrandmax() < 0.5) ? -1 : 1;
        $rows2 = [];
        foreach ($rows as $i => $r) {
            $r2 = $r;
            $avg = $devRows[$i];
            $f = [];
            foreach ($mcols as $mc) { $f[$mc] = $avg[$mc] + $flip * ($r['feats'][$mc] - $avg[$mc]); }
            $r2['feats'] = $f;
            $rows2[] = $r2;
        }
        $r2 = $lodoR2($rows2, $spec);
        if (is_finite($r2)) { $count++; }
    }
    return [$count, $N];
};

// --- перебор форм ---
$forms = [];
foreach ($mcols as $mc) { $forms[] = ['lin', $mc]; }
foreach ($mcols as $m1) {
    foreach ($mcols as $m2) {
        if ($m1 !== $m2) { $forms[] = ['mul', $m1, $m2]; }
    }
}
printf("форм: %d x видов: %d\n", count($forms), count($SPECS));

$report = [];
$STATS = ['gate_r2in' => 0, 'gate_lodo' => 0, 'gate_time' => 0, 'gate_pBlock' => 0, 'gate_pSign' => 0];
foreach ($SPECS as $sp) {
    $rows = $build($sp, $REPS);
    if (count($rows) < 20) { printf("%-30.30s SKIP (n=%d)\n", $sp, count($rows)); continue; }
    $cands = [];
    foreach ($forms as $spec) {
        $r2in = $fitR2($rows, $spec);
        if (!is_finite($r2in) || $r2in < 0.15) { continue; }
        $lodo = $lodoR2($rows, $spec);
        if (!is_finite($lodo) || $lodo <= 0) { continue; }
        $STATS['gate_r2in']++; $STATS['gate_lodo']++;
        $tr2 = $timeR2($rows, $spec);
        if (!is_finite($tr2) || $tr2 <= 0) { continue; }
        $STATS['gate_time']++;
        // null-иерархия для выживших
        [$c1, ] = $nullBlock($rows, $spec, 424242, 200);
        $pBlock = $c1 / 200;
        if ($pBlock >= 0.10) { continue; }
        $STATS['gate_pBlock']++;
        [$c2, ] = $nullSign($rows, $spec, 777777, 200);
        $pSign = $c2 / 200;
        if ($pSign >= 0.10) { continue; }
        $STATS['gate_pSign']++;
        $cands[] = ['spec' => $spec, 'r2in' => $r2in, 'lodo' => $lodo, 'tr2' => $tr2,
                    'pBlock' => $pBlock, 'pSign' => $pSign];
    }
    // BH FDR внутри вида
    usort($cands, fn ($a, $b) => min($a['pBlock'], $a['pSign']) <=> min($b['pBlock'], $b['pSign']));
    $m = count($cands);
    $keep = [];
    $maxQ = 0;
    foreach ($cands as $i => $c) {
        $q = min($c['pBlock'], $c['pSign']) * $m / ($i + 1);
        $maxQ = $q;
        if ($q <= 0.10) { $keep[] = $c + ['q' => min(1.0, $q)]; }
    }
    printf("%-30.30s выживших после всех гейтов: %d (из ~%d тестов)\n", $sp, count($keep), count($forms));
    foreach (array_slice($keep, 0, 8) as $c) {
        $specStr = $c['spec'][0] === 'lin' ? "lin:{$c['spec'][1]}"
            : ($c['spec'][0] === 'over' ? "over:{$c['spec'][1]}" : "mul:{$c['spec'][1]}x{$c['spec'][2]}");
        printf("   %-28.28s R2in=%.2f LODO=%+.2f T-hold=%+.2f pBlock=%.2f pSign=%.2f q=%.2f\n",
            $specStr, $c['r2in'], $c['lodo'], $c['tr2'], $c['pBlock'], $c['pSign'], $c['q']);
    }
    if ($keep) { $report[$sp] = $keep; }
}
echo "\n=== гейты (суммарно по видам, после r2in>0.15) ===\n";
foreach ($STATS as $g => $n) { printf("  %s: %d\n", $g, $n); }
echo "\nИТОГО видов с сертифицированными формами: ", count($report), "\n";
