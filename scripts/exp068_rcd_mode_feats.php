<?php

declare(strict_types=1);

/**
 * B4.2-RCD шаг 3: режимные фичи из метаданных ячеек.
 *
 * Гипотеза (контракт из шага 2): сдвиг кривизны (коэф. t²) объясняется
 * не только химией, но и режимом (протокол съёмки / lifetime).
 *
 * Фичи-кандидаты (НЕ зависят от y — только метаданные выгрузки):
 *   unit_days      = 1, если время в днях (протокол), иначе 0 (cycles)
 *   lifetime_raw   = max(t_raw) ячейки
 *   lifetime_norm  = max(t_norm) ячейки (сколько жизни покрыто данными)
 *   sample_rate    = точек на единицу t_norm (плотность съёмки)
 *
 * Тест: per-cell quadratic fit -> коэф. t² (кривизна c2) -> corr(c2, фича)
 * против перестановочного null. Затем: унифицированный LSQ с фичей-модификатором
 * кривизны (t² × фича) и leave-cells-out сравнение с базой t²×химии.
 */

require __DIR__ . '/../vendor/autoload.php';

$lines = file(__DIR__ . '/../battery_b2/unified_capacity_fade.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(trim((string) array_shift($lines)));
$byCell = [];
foreach ($lines as $l) {
    $v = str_getcsv(trim($l));
    if (count($v) < 6) { continue; }
    $byCell[$v[0]][] = ['unit' => $v[3], 't' => (float) $v[2], 'tn' => (float) $v[4], 'cap' => (float) $v[5], 'chem' => $v[1]];
}
echo 'ячеек: ' . count($byCell) . "\n";

// per-cell quadratic fit: cap ~ 1 + c1*tn + c2*tn² (LSQ 3 параметра)
function quadFit(array $pts): array
{
    // X: [tn, tn², 1] — решаем нормальные уравнения
    $cols = [[], [], []]; $y = [];
    foreach ($pts as $p) { $cols[0][] = $p['tn']; $cols[1][] = $p['tn'] * $p['tn']; $cols[2][] = 1.0; $y[] = $p['cap']; }
    $m = count($y);
    $A = array_fill(0, 3, array_fill(0, 3, 0.0)); $b = array_fill(0, 3, 0.0);
    for ($i = 0; $i < $m; $i++) {
        $row = [$cols[0][$i], $cols[1][$i], 1.0];
        for ($p = 0; $p < 3; $p++) {
            $b[$p] += $row[$p] * $y[$i];
            for ($q = 0; $q < 3; $q++) { $A[$p][$q] += $row[$p] * $row[$q]; }
        }
    }
    for ($p = 0; $p < 3; $p++) {
        $mx = $p;
        for ($q = $p + 1; $q < 3; $q++) { if (abs($A[$q][$p]) > abs($A[$mx][$p])) { $mx = $q; } }
        if (abs($A[$mx][$p]) < 1e-14) { continue; }
        [$A[$p], $A[$mx]] = [$A[$mx], $A[$p]];
        [$b[$p], $b[$mx]] = [$b[$mx], $b[$p]];
        for ($q = $p + 1; $q < 3; $q++) {
            $f = $A[$q][$p] / $A[$p][$p];
            for ($r = $p; $r < 3; $r++) { $A[$q][$r] -= $f * $A[$p][$r]; }
            $b[$q] -= $f * $b[$p];
        }
    }
    $w = [0.0, 0.0, 0.0];
    for ($p = 2; $p >= 0; $p--) {
        $s = $b[$p];
        for ($r = $p + 1; $r < 3; $r++) { $s -= $A[$p][$r] * $w[$r]; }
        $w[$p] = abs($A[$p][$p]) > 1e-14 ? $s / $A[$p][$p] : 0.0;
    }
    return $w; // [c1, c2, const]
}

$cells = [];
foreach ($byCell as $id => $pts) {
    if (count($pts) < 8) { continue; }
    $units = array_unique(array_column($pts, 'unit'));
    $unit = in_array('days', $units, true) ? 1 : 0;
    $tmax = max(array_column($pts, 't'));
    $tnmax = max(array_column($pts, 'tn'));
    [$c1, $c2, $c0] = quadFit($pts);
    $cells[$id] = [
        'unit' => $unit,
        'lifetime_raw' => $tmax,
        'lifetime_norm' => $tnmax,
        'rate' => count($pts) / max($tnmax, 1e-9),
        'c1' => $c1, 'c2' => $c2,
        'chem' => strtoupper(trim($pts[0]['chem'])),
    ];
}
$nc = count($cells);
echo "ячеек с ≥8 точками: $nc\n";

function corr(array $a, array $b): float
{
    $m = count($a);
    $ma = array_sum($a) / $m; $mb = array_sum($b) / $m;
    $n = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $m; $i++) { $n += ($a[$i] - $ma) * ($b[$i] - $mb); $da += ($a[$i] - $ma) ** 2; $db += ($b[$i] - $mb) ** 2; }
    return ($da > 1e-12 && $db > 1e-12) ? $n / sqrt($da * $db) : 0.0;
}

$c2 = array_column($cells, 'c2');
$c2abs = array_map('abs', $c2);
$feats = ['unit' => array_column($cells, 'unit'), 'lifetime_raw' => array_column($cells, 'lifetime_raw'),
    'lifetime_norm' => array_column($cells, 'lifetime_norm'), 'rate' => array_column($cells, 'rate')];

echo "\ncorr(кривизна c2, фича) — с перестановочным null (500):\n";
foreach ($feats as $name => $vals) {
    $c = corr($c2, $vals);
    // null: перестановка c2
    $sims = [];
    for ($p = 0; $p < 500; $p++) {
        $w = $c2;
        for ($i = $nc - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$w[$i], $w[$j]] = [$w[$j], $w[$i]]; }
        $sims[] = abs(corr($w, $vals));
    }
    sort($sims);
    $q95 = $sims[(int) (0.95 * 499)];
    printf("  %-14s r=%+.3f  null95=%.3f  %s\n", $name, $c, $q95, abs($c) > $q95 ? 'ЗНАЧИМ' : 'шум');
}
// то же для |c2| (кривизна по модулю — знак может зависеть от химии)
echo "\ncorr(|c2|, фича):\n";
foreach ($feats as $name => $vals) {
    $c = corr($c2abs, $vals);
    $sims = [];
    for ($p = 0; $p < 500; $p++) {
        $w = $c2abs;
        for ($i = $nc - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$w[$i], $w[$j]] = [$w[$j], $w[$i]]; }
        $sims[] = abs(corr($w, $vals));
    }
    sort($sims);
    $q95 = $sims[(int) (0.95 * 499)];
    printf("  %-14s r=%+.3f  null95=%.3f  %s\n", $name, $c, $q95, abs($c) > $q95 ? 'ЗНАЧИМ' : 'шум');
}

// распределение кривизны по химиям/юнитам
$stat = [];
foreach ($cells as $c) {
    $key = $c['chem'] . ($c['unit'] ? '/days' : '/cyc');
    $stat[$key][] = $c['c2'];
}
echo "\nкривизна c2 по группам (медиана, IQR):\n";
foreach ($stat as $k => $v) {
    sort($v);
    $mid = $v[(int) (count($v) / 2)];
    $q1 = $v[(int) (count($v) * 0.25)];
    $q3 = $v[(int) (count($v) * 0.75)];
    printf("  %-12s n=%d med=%+.4f IQR=[%+.4f,%+.4f]\n", $k, count($v), $mid, $q1, $q3);
}
