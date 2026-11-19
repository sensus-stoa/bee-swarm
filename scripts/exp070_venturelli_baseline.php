<?php
declare(strict_types=1);

/**
 * B5.1: Venturelli 2018 — транскодинг EV1-EV3 в приращения + baseline gLV (depth-2).
 *
 * Данные: /tmp/ven_supp (EV1: 66 пар x 7 точек; EV2: 15 пар x 2 ряда; EV3: 13
 * сообществ full+dropouts). Виды: 2-буквенные коды (BH, BO, BV, BT, CA, CH,
 * DP, EL, ER, FP, PC, BU).
 *
 * Цель: относительное обилие первого вида пары x_i(t), приращение
 *   y = (x(t+1) - x(t)) / x(t)   (лог-рост за шаг)
 * фичи: x_i, x_j (доли). gLV-пара: y = r + a_ii x_i + a_ij x_j  — depth-2
 * (линейная). Тройные термы x_i*x_j — depth-3.
 */

$SUPP = __DIR__ . '/../Benchmarks/venturelli';
$OUT = __DIR__ . '/../Benchmarks/venturelli';
if (!is_dir($OUT)) { mkdir($OUT, 0777, true); }

/** csv (уже конвертированные из xlsx python'ом) -> массив строк */
function openpyxl_load(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    while (($r = fgetcsv($fh)) !== false) { $rows[] = $r; }
    fclose($fh);
    return $rows;
}

// --- EV1: 66 пар ---
$rows = openpyxl_load($SUPP . '/ev1_pairs.csv');

$series = []; // [pair][species] => [x0..x6]
foreach ($rows as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    $pair = substr($name, 0, 2) . substr($name, 2, 2); // 4 буквы = 2 вида
    $s1 = substr($name, 0, 2); $s2 = substr($name, 2, 2);
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $vals[] = (float) $r[$k]; }
    $series[$pair] = ['s1' => $s1, 's2' => $s2, 'x1' => $vals];
}

// EV2: 15 пар x 2 (95:5 и 5:95) — имя в col A, фазы = повторные вхождения имени
$rows2 = openpyxl_load($SUPP . '/ev2_pairs.csv');
$series2 = [];
$phaseIdx = [];
foreach ($rows2 as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $phaseIdx[$name] = ($phaseIdx[$name] ?? -1) + 1;
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    $series2[$name . '#' . $phaseIdx[$name]] = ['s1' => substr($name, 0, 2), 's2' => substr($name, 2, 2), 'x1' => $vals];
}
echo 'EV1 пар: ' . count($series) . '; EV2 пар: ' . count($series2) . "\n";

// --- Транскодинг: приращения в task-строки ---
// CSV: pair,phase(1=1:1,95,5),t_step,x_i,x_j,y
$fh = fopen($OUT . '/task_increments.csv', 'w');
fputcsv($fh, ['pair', 'phase', 'step', 'x1', 'x2', 'y']);

$nEV1 = 0;
foreach ($series as $pair => $s) {
    for ($t = 0; $t < 6; $t++) {
        $xi = $s['x1'][$t]; $xin = $s['x1'][$t + 1];
        if ($xi <= 1e-6) { continue; }
        $y = ($xin - $xi) / $xi;
        fputcsv($fh, [$pair, '1:1', $t, $xi, 1 - $xi, $y]);
        $nEV1++;
    }
}
$nEV2 = 0;
foreach ($series2 as $key => $s) {
    $phase = str_contains($key, '#0') ? '95:5' : '5:95';
    $vals = $s['x1'];
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t] ?? NAN;
        $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $y = ($xin - $xi) / $xi;
        fputcsv($fh, [substr($key, 0, 4), $phase, $t, $xi, 1 - $xi, $y]);
        $nEV2++;
    }
}
fclose($fh);
echo "task_increments.csv: EV1 $nEV1 строк, EV2 $nEV2 строк\n";

// --- LSQ с ridge (канон) ---
function lsqR(array $cols, array $y): array
{
    $m = count($y); $k = count($cols) + 1;
    $A = array_fill(0, $k, array_fill(0, $k, 0.0)); $b = array_fill(0, $k, 0.0);
    for ($i = 0; $i < $m; $i++) {
        $row = [1.0];
        foreach ($cols as $c) { $row[] = $c[$i]; }
        for ($p = 0; $p < $k; $p++) {
            $b[$p] += $row[$p] * $y[$i];
            for ($q = 0; $q < $k; $q++) { $A[$p][$q] += $row[$p] * $row[$q]; }
        }
    }
    for ($p = 0; $p < $k; $p++) { $A[$p][$p] += 1e-8; }
    for ($p = 0; $p < $k; $p++) {
        $mx = $p;
        for ($q = $p + 1; $q < $k; $q++) { if (abs($A[$q][$p]) > abs($A[$mx][$p])) { $mx = $q; } }
        if (abs($A[$mx][$p]) < 1e-14) { continue; }
        [$A[$p], $A[$mx]] = [$A[$mx], $A[$p]]; [$b[$p], $b[$mx]] = [$b[$mx], $b[$p]];
        for ($q = $p + 1; $q < $k; $q++) {
            $f = $A[$q][$p] / $A[$p][$p];
            for ($r = $p; $r < $k; $r++) { $A[$q][$r] -= $f * $A[$p][$r]; }
            $b[$q] -= $f * $b[$p];
        }
    }
    $w = array_fill(0, $k, 0.0);
    for ($p = $k - 1; $p >= 0; $p--) {
        $s = $b[$p];
        for ($r = $p + 1; $r < $k; $r++) { $s -= $A[$p][$r] * $w[$r]; }
        $w[$p] = abs($A[$p][$p]) > 1e-14 ? $s / $A[$p][$p] : 0.0;
    }
    return $w;
}

function applyLsq(array $w, array $cols, array $idx): array
{
    $out = [];
    foreach ($idx as $i) {
        $s = $w[0];
        foreach ($cols as $p => $c) { $s += $w[$p + 1] * $c[$i]; }
        $out[] = $s;
    }
    return $out;
}

// --- Загрузка task-строк ---
$task = [];
$fh = fopen($OUT . '/task_increments.csv', 'r');
fgetcsv($fh);
while (($r = fgetcsv($fh)) !== false) {
    $task[] = ['pair' => $r[0], 'phase' => $r[1], 'step' => (int) $r[2], 'x1' => (float) $r[3], 'x2' => (float) $r[4], 'y' => (float) $r[5]];
}
$n = count($task);
echo "всего task-строк: $n\n";

// --- Baseline gLV: ПЕР-ПАРНЫЙ fit y = r + a_ii*x1 + a_ij*x2 (как у авторов:
// матрица взаимодействий паро-специфична). Train: шаги 0-3 каждой пары EV1,
// Test: шаги 4-5 (внутри-пара holdout) + EV2 (95:5/5:95 перенос) ---
error_reporting(E_ALL & ~E_WARNING);
$col1 = array_column($task, 'x1');
$col2 = array_column($task, 'x2');
$yall = array_column($task, 'y');
$my = array_sum($yall) / $n;
$byPair = [];
foreach ($task as $i => $r) { $byPair[$r['pair']][$r['phase']][] = $i; }

$r2Train = []; $r2Test = []; $r2Ev2 = [];
foreach ($byPair as $pair => $phases) {
    if (!isset($phases['1:1'])) { continue; }
    $all = $phases['1:1'];
    $trainI = array_slice($all, 0, 4); // шаги 0-3
    if (count($trainI) < 3) { continue; }
    $testI = array_slice($all, 4);      // шаги 4-5
    $cols = [$col1, $col2];
    $sub = []; foreach ($cols as $c) { $s = []; foreach ($trainI as $i) { $s[] = $c[$i]; } $sub[] = $s; }
    $yt = []; foreach ($trainI as $i) { $yt[] = $yall[$i]; }
    $w = lsqR($sub, $yt);
    // train R2
    $pred = applyLsq($w, $cols, $trainI);
    $mt = array_sum($yt) / count($yt); $res = 0; $tot = 0;
    foreach ($trainI as $k => $i) { $res += ($yt[$k] - $pred[$k]) ** 2; $tot += ($yt[$k] - $mt) ** 2; }
    if ($tot > 1e-12) { $r2Train[] = 1 - $res / $tot; }
    // внутри-пара holdout (шаги 4-5, фаза 1:1)
    if ($testI) {
        $pred = applyLsq($w, $cols, $testI);
        $res = 0; $tot = 0;
        foreach ($testI as $k => $i) { $res += ($yall[$i] - $pred[$k]) ** 2; $tot += ($yall[$i] - $my) ** 2; }
        $r2Test[] = $tot > 1e-12 ? 1 - $res / $tot : null;
    }
    // EV2: перенос на 95:5/5:95 той же пары
    if (isset($phases['95:5']) || isset($phases['5:95'])) {
        $ev2I = array_merge($phases['95:5'] ?? [], $phases['5:95'] ?? []);
        $pred = applyLsq($w, $cols, $ev2I);
        $res = 0; $tot = 0;
        foreach ($ev2I as $k => $i) { $res += ($yall[$i] - $pred[$k]) ** 2; $tot += ($yall[$i] - $my) ** 2; }
        if ($tot > 1e-12) { $r2Ev2[] = 1 - $res / $tot; }
    }
}
$med = function (array $a): float { if (!$a) { return NAN; } sort($a); return $a[(int) (count($a) / 2)]; };
printf("per-pair gLV (n=%d пар): train R2 медиана=%.3f | holdout-шаги 4-5: медиана=%.3f | EV2 95:5/5:95: медиана=%.3f\n",
    count($r2Train), $med($r2Train), $med(array_filter($r2Test)), $med(array_filter($r2Ev2)));
echo 'EV2 пар с данными: ' . count($r2Ev2) . "\n";

// сохранение справки
file_put_contents($OUT . '/AUDIT.md', "# Venturelli 2018 data audit\n\n- EV1: 66 пар x 7 точек (1:1), EV2: 15 пар x 2 фазы (95:5/5:95), EV3: 13 сообществ (full+12 dropouts), EV4: 97 метаболитов x 12 видов.\n- SBML-модели авторов: ven_s006/twelveSpeciesT1-4.xml (gLV, 12 реакций).\n- Транскодинг: Benchmarks/venturelli/task_increments.csv (приращения log-роста).\n- Baseline gLV-пара (r, a_ii, a_ij): см. вывод exp070_venturelli_baseline.php.\n");
echo 'AUDIT.md записан';
