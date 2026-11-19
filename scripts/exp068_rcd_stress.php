<?php

declare(strict_types=1);

/**
 * B4.2-RCD шаг 5: режимные фичи = физические условия протокола (ISU-ILCC).
 *
 * На чистых NMC (одна химия) тестируем:
 *   base  : cap ~ a + b·t + c·t²            (одна кривая на все протоколы)
 *   proto : + d·t²×(chC), e·t²×(disC), f·t²×(DoD)   (физика протокола!)
 *
 * Уровни: LCO-ячейки (5-fold) и LPO-протоколы (каждый протокол по очереди
 * невидим). Условия из protocol_conditions.json (README_V2.0, Appendix A1).
 */

require __DIR__ . '/../vendor/autoload.php';

$conds = json_decode(file_get_contents(__DIR__ . '/../battery_b2/raw/protocol_conditions.json'), true);

$raw = [];
$dir = __DIR__ . '/../battery_b2/raw/cfade/capacity_fade/Release 1.0';
foreach (glob("$dir/*.csv") as $f) {
    $id = basename($f, '.csv');
    if (!preg_match('/^G(\d+)C(\d+)$/', $id, $m)) { continue; }
    $g = $m[1];
    if (!isset($conds[$g])) { continue; }
    $pts = [];
    $fh = fopen($f, 'r');
    fgetcsv($fh); // header
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 2 || $row[0] === '' ) { continue; }
        $pts[] = [(float) $row[0], (float) $row[1]];
    }
    fclose($fh);
    if (count($pts) < 8) { continue; }
    $tn0 = $pts[0][0];
    $tmax = $pts[count($pts) - 1][0];
    $cap0 = max(array_map(fn ($p) => $p[1], array_slice($pts, 0, 3)));
    if ($cap0 <= 0 || $tmax <= $tn0) { continue; }
    foreach ($pts as $p) {
        $rows[] = [
            'id' => $id, 'proto' => $g,
            'tn' => ($p[0] - $tn0) / ($tmax - $tn0),
            'cap' => $p[1] / $cap0,
            'chC' => (float) $conds[$g][0],
            'disC' => (float) $conds[$g][1],
            'dod' => (float) $conds[$g][2] / 100.0,
        ];
    }
}
$n = count($rows);
$protos = array_unique(array_column($rows, 'proto'));
$cells = array_unique(array_column($rows, 'id'));
echo "n=$n, ячеек=" . count($cells) . ', протоколов=' . count($protos) . "\n";

// LSQ с ridge
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
    for ($p = 0; $p < $k; $p++) { $A[$p][$p] += 1e-6; }
    for ($p = 0; $p < $k; $p++) {
        $mx = $p;
        for ($q = $p + 1; $q < $k; $q++) { if (abs($A[$q][$p]) > abs($A[$mx][$p])) { $mx = $q; } }
        if (abs($A[$mx][$p]) < 1e-14) { continue; }
        [$A[$p], $A[$mx]] = [$A[$mx], $A[$p]];
        [$b[$p], $b[$mx]] = [$b[$mx], $b[$p]];
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

$y = array_column($rows, 'cap');
$my = array_sum($y) / $n;
$ssTot = 0.0;
for ($i = 0; $i < $n; $i++) { $ssTot += ($y[$i] - $my) ** 2; }

$evaluate = function (array $names, callable $splitFn, string $tag) use ($rows, $y, $n, $ssTot, $my): array {
    // колонки
    $cols = [];
    foreach ($names as $f) {
        $c = [];
        foreach ($rows as $r) {
            $c[] = match ($f) {
                '1' => 1.0,
                't' => $r['tn'],
                't2' => $r['tn'] ** 2,
                't2ch' => $r['tn'] ** 2 * $r['chC'],
                't2dis' => $r['tn'] ** 2 * $r['disC'],
                't2dod' => $r['tn'] ** 2 * $r['dod'],
                default => 0.0,
            };
        }
        $cols[] = $c;
    }
    $oosRes = 0.0;
    foreach ($splitFn() as $testSet) {
        $testSet = array_flip($testSet);
        $trainI = []; $testI = [];
        foreach ($rows as $i => $r) { if (isset($testSet[$r['id']])) { $testI[] = $i; } else { $trainI[] = $i; } }
        $sub = []; $yt = [];
        foreach ($cols as $c) { $s = []; foreach ($trainI as $i) { $s[] = $c[$i]; } $sub[] = $s; }
        foreach ($trainI as $i) { $yt[] = $y[$i]; }
        $w = lsqR($sub, $yt);
        foreach ($testI as $i) {
            $s = $w[0];
            foreach ($cols as $p => $c) { $s += $w[$p + 1] * $c[$i]; }
            $oosRes += ($y[$i] - $s) ** 2;
        }
    }
    $oos = 1 - $oosRes / $ssTot;
    echo sprintf("%-24s %10.4f\n", $tag, $oos);
    return [$tag, $oos];
};

$protoSplits = function () use ($protos, $rows): array {
    $folds = [];
    foreach ($protos as $p) {
        $ids = [];
        foreach ($rows as $r) { if ($r['proto'] === $p) { $ids[] = $r['id']; } }
        if ($ids) { $folds[] = array_unique($ids); }
    }
    return $folds;
};
$cellSplits = function () use ($rows): array {
    $folds = [];
    foreach (array_unique(array_column($rows, 'id')) as $id) { $folds[crc32($id) % 5][] = $id; }
    return $folds;
};

echo "\nNMC-only (ISU-ILCC, условия протоколов):\n";
echo "Уровень A (невидимые ячейки):\n";
$evaluate(['1', 't', 't2'], $cellSplits, 'base t^2');
$evaluate(['1', 't', 't2', 't2ch', 't2dis', 't2dod'], $cellSplits, '+t2×(chC,disC,DoD)');

echo "Уровень B (невидимые протоколы):\n";
$evaluate(['1', 't', 't2'], $protoSplits, 'base t^2');
$evaluate(['1', 't', 't2', 't2ch', 't2dis', 't2dod'], $protoSplits, '+t2×(chC,disC,DoD)');
