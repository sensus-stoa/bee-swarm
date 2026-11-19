<?php

declare(strict_types=1);

/**
 * B4.2-RCD шаг 4: режим = протокол (G1..G63 из cell_id).
 *
 * Уровни честности:
 *  A. LCO-ячейки (5-fold): тест = невидимые ячейки известных протоколов.
 *  B. LPO-протоколы: тест = ЦЕЛИКОМ невидимые протоколы (настоящая
 *     переносимость закона на новый режим).
 *
 * Модели (всё в грамматике: sq + ×):
 *   chem      : t² + химии + t²×химии              (шаг 2, OOS 0.896)
 *   proto     : t² + химии + t²×(протокол-кривизна, предагрегированная
 *               на train-фолде)                     (режим через протокол)
 *   full      : chem + proto
 *
 * Утечка: per-protocol кривизна считается ТОЛЬКО на train-ячейках фолда
 * (тестовые протоколы получают глобальную медиану train).
 */

require __DIR__ . '/../vendor/autoload.php';

$lines = file(__DIR__ . '/../battery_b2/unified_capacity_fade.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($lines);
$raw = [];
foreach ($lines as $l) {
    $v = str_getcsv(trim($l));
    if (count($v) < 6) { continue; }
    $raw[$v[0]][] = ['t' => (float) $v[2], 'tn' => (float) $v[4], 'cap' => (float) $v[5], 'chem' => trim($v[1])];
}
// ячейки: протокол, химия, точки; per-cell quadratic -> c2
function quadC2(array $pts): float
{
    $c0 = []; $c1 = []; $c2 = []; $y = [];
    foreach ($pts as $p) { $c0[] = $p['tn']; $c1[] = $p['tn'] * $p['tn']; $c2[] = 1.0; $y[] = $p['cap']; }
    $cols = [$c0, $c1, $c2];
    $m = count($y);
    $A = array_fill(0, 3, [0.0, 0.0, 0.0]); $b = [0.0, 0.0, 0.0];
    for ($i = 0; $i < $m; $i++) {
        $row = [$c0[$i], $c1[$i], 1.0];
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
    return $w[1]; // c2
}

$cells = []; $rows = [];
foreach ($raw as $id => $pts) {
    if (count($pts) < 8) { continue; }
    preg_match('/^G(\d+)/', $id, $mm);
    $proto = $mm ? 'G' . $mm[1] : 'G?';
    $chem = strtoupper(trim($pts[0]['chem']));
    $c2 = quadC2($pts);
    $cells[$id] = ['proto' => $proto, 'chem' => $chem, 'c2' => $c2];
    foreach ($pts as $p) { $rows[] = ['id' => $id, 'proto' => $proto, 'chem' => $chem, 'tn' => $p['tn'], 'cap' => $p['cap']]; }
}
$n = count($rows);
$protos = array_unique(array_column($cells, 'proto'));
echo "n=$n, ячеек=" . count($cells) . ', протоколов=' . count($protos) . "\n";

// фиче-колонки (полные векторы по строкам)
$col = function (string $f) use ($rows): array {
    $v = [];
    foreach ($rows as $r) {
        $v[] = match ($f) {
            '1' => 1.0,
            't' => $r['tn'],
            't2' => $r['tn'] ** 2,
            'nmc' => (int) ($r['chem'] === 'NMC'),
            'lfp' => (int) ($r['chem'] === 'LFP'),
            't2nmc' => $r['tn'] ** 2 * (int) ($r['chem'] === 'NMC'),
            't2lfp' => $r['tn'] ** 2 * (int) ($r['chem'] === 'LFP'),
            default => 0.0,
        };
    }
    return $v;
};
// протокольная кривизна-модификатор строится по train-ячейкам
$protoFeat = function (array $trainIdx) use ($cells, $rows): array {
    // медиана c2 протокола по train-ячейкам
    $sum = []; $cnt = [];
    foreach ($trainIdx as $i) { $c = $cells[$rows[$i]['id']]; $sum[$c['proto']][] = $c['c2']; }
    $med = [];
    foreach ($sum as $p => $vals) { sort($vals); $med[$p] = $vals[(int) (count($vals) / 2)]; }
    $gmed = $med ? array_sum($med) / count($med) : 0.0;
    $v = [];
    foreach ($rows as $r) { $v[] = ($med[$r['proto']] ?? $gmed) * $r['tn'] ** 2; }
    return $v;
};

function lsqFit(array $cols, array $y): array
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
    // ridge: гасит вырожденность на почти-коллинеарных колонках
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
// компактный вывод предупреждений (per-fold диагностика шумит)
error_reporting(E_ALL & ~E_WARNING);

$evaluate = function (array $names, bool $withProto, callable $splitFn, string $tag) use ($rows, $cells, $col, $protoFeat, $y, $n, $ssTot, $my): array {
    $base = array_map(fn ($f) => $col($f), $names);
    $oosRes = 0.0;
    foreach ($splitFn() as $f => $testSet) {
        $testSet = array_merge(...array_map('array_flip', $testSet));
        $trainI = []; $testI = [];
        foreach ($rows as $i => $r) { if (isset($testSet[$r['id']])) { $testI[] = $i; } else { $trainI[] = $i; } }
        $cols = $base;
        if ($withProto) { $cols[] = $protoFeat($trainI); }
        $sub = []; $yt = [];
        foreach ($cols as $c) { $s = []; foreach ($trainI as $i) { $s[] = $c[$i]; } $sub[] = $s; }
        foreach ($trainI as $i) { $yt[] = $y[$i]; }
        $w = lsqFit($sub, $yt);
        $foldRes = 0.0; $foldTot = 0.0;
        foreach ($testI as $i) {
            $s = $w[0];
            foreach ($cols as $p => $c) { $s += $w[$p + 1] * $c[$i]; }
            $foldRes += ($y[$i] - $s) ** 2;
            $foldTot += ($y[$i] - $my) ** 2;
        }
        $oosRes += $foldRes;
        echo sprintf("    fold %s: n=%d OOS=%.3f\n", $f, count($testI), 1 - $foldRes / max($foldTot, 1e-30));
    }
    $oos = 1 - $oosRes / $ssTot;
    echo sprintf("%-28s %10.4f\n", $tag, $oos);
    return [$tag, $oos];
};

// --- уровень A: невидимые ячейки известных протоколов ---
$foldsA = [];
foreach ($cells as $id => $c) { $foldsA[crc32($id) % 5][] = [$id]; }
echo "\nУровень A (невидимые ячейки, протоколы известны):\n";
$evaluate(['1', 't', 't2', 'nmc', 'lfp', 't2nmc', 't2lfp'], false, fn () => $foldsA, 'chem (шаг 2)');
$evaluate(['1', 't', 't2', 'nmc', 'lfp', 't2nmc', 't2lfp'], true, fn () => $foldsA, 'chem + протокол-кривизна');

// --- уровень B: невидимые протоколы, стратифицировано по химиям ---
// (урок fold-2: нестратифицированное разбиение протоколов оставляет train
//  без всей химии — тест не переносимости, а артефакт разбиения)
$foldsB = [];
foreach ($protos as $p) {
    $idsP = []; $chP = '';
    foreach ($cells as $id => $c) { if ($c['proto'] === $p) { $idsP[] = $id; $chP = $c['chem']; } }
    if (!$idsP) { continue; }
    $foldsB[$chP . crc32($p) % 5][] = $idsP;
}
echo "\nУровень B (целые протоколы невидимы):\n";
$evaluate(['1', 't', 't2', 'nmc', 'lfp', 't2nmc', 't2lfp'], false, fn () => $foldsB, 'chem');
$evaluate(['1', 't', 't2', 'nmc', 'lfp', 't2nmc', 't2lfp'], true, fn () => $foldsB, 'chem + протокол-кривизна');
