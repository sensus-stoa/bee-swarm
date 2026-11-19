<?php

declare(strict_types=1);

/**
 * B4.2-RCD шаг 2: дихотомия терма времени по контракту (t^alpha) —
 * с ГРУППОВЫМИ сплитами (leave-cells-out, урок Audit #5 Laser Welding:
 * групповая структура + рядовые сплиты = leakage, RF R2 0.85 -> −12.3).
 *
 * Кандидаты: cap ~ a + b·t^alpha + chemistry-сдвиги, alpha ∈ {0.5, 1, 1.5, 2}.
 * t^0.5 НЕ в грамматике (sqrt отсутствует); t^1, t^2 — есть.
 *
 * Меры: R² (полный сэмпл) + leave-cells-out (K-fold по ячейкам, K=5,
 * детерминированное разбиение по crc32(cell_id)).
 *
 * Вердикт: если alpha=0.5 даёт существенно лучший OOS R2 — грамматике
 * нужен sqrt (расширение с контрактом); если t^1/t^2 сопоставимы —
 * DEPTH/форма, грамматика не трогается.
 *
 * Запуск: php scripts/exp068_rcd_dichotomy.php
 */

require __DIR__ . '/../vendor/autoload.php';

// ---------- данные: unified_task_gid (с cell_id) ----------
$lines = file(__DIR__ . '/../battery_b2/unified_task_gid.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(trim((string) array_shift($lines)));
$cid = []; $X = []; $y = [];
foreach ($lines as $l) {
    $v = str_getcsv(trim($l));
    if (count($v) < 6) { continue; }
    $cid[] = $v[0];
    $X[] = [(float) $v[1], (float) $v[2], (float) $v[3], (float) $v[4]];
    $y[] = (float) $v[5];
}
$n = count($y);
$labels = ['t_norm', 'is_nmc', 'is_lfp', 'is_lco'];
echo "n=$n, ячеек=" . count(array_unique($cid)) . "\n";

// базис: t^alpha (4 варианта) + химии (4) + перехват
$alphas = [1.0, 1.5, 2.0, 2.5, 3.0];
$bases = [];
foreach ($alphas as $ai => $al) {
    $col = [];
    foreach ($X as $r) { $col[] = pow($r[0], $al); }
    $bases["t^$al"] = $col;
}
// режимные взаимодействия: t^2 × химия (в грамматике: ×)
foreach ([1 => 'is_nmc', 2 => 'is_lfp', 3 => 'is_lco'] as $j => $lab) {
    $bases["t2x$lab"] = [];
    foreach ($X as $r) { $bases["t2x$lab"][] = $r[0] * $r[0] * $r[$j]; }
    $bases[$lab] = array_column($X, $j);
}

// joint LSQ (с перехватом): y ~ Xb * w
function lsq(array $cols, array $y): array
{
    $m = count($y);
    $k = count($cols) + 1;
    // нормальные уравнения (k<=8 — можно без регуляризации)
    $A = array_fill(0, $k, array_fill(0, $k, 0.0));
    $b = array_fill(0, $k, 0.0);
    for ($i = 0; $i < $m; $i++) {
        $row = [1.0];
        foreach ($cols as $c) { $row[] = $c[$i]; }
        for ($p = 0; $p < $k; $p++) {
            $b[$p] += $row[$p] * $y[$i];
            for ($q = 0; $q < $k; $q++) { $A[$p][$q] += $row[$p] * $row[$q]; }
        }
    }
    // Гаусс с выбором главного
    for ($p = 0; $p < $k; $p++) {
        $mx = $p;
        for ($q = $p + 1; $q < $k; $q++) { if (abs($A[$q][$p]) > abs($A[$mx][$p])) { $mx = $q; } }
        if (abs($A[$mx][$p]) < 1e-12) { continue; }
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
        $w[$p] = abs($A[$p][$p]) > 1e-12 ? $s / $A[$p][$p] : 0.0;
    }
    return $w;
}

function predict(array $w, array $cols, int $i): float
{
    $s = $w[0];
    foreach ($cols as $p => $c) { $s += $w[$p + 1] * $c[$i]; }
    return $s;
}

// leave-cells-out folds (детерминированно по crc32 cell_id)
$folds = [];
foreach (array_unique($cid) as $c) {
    $folds[crc32($c) % 5][] = $c;
}

// прогон: каждая alpha с базовым химий-набором + t2x-взаимодействия
echo sprintf("\n%-22s %10s %12s\n", 'модель', 'R2 (in)', 'OOS R2 (LCO)');
$results = [];
$chemCols = [$bases['is_nmc'], $bases['is_lfp'], $bases['is_lco']];
$interCols = [$bases['t2xis_nmc'], $bases['t2xis_lfp'], $bases['t2xis_lco']];
foreach ($alphas as $al) {
    foreach (['base' => $chemCols, '+t2x' => array_merge($chemCols, $interCols)] as $tag => $extra) {
        $cols = array_merge([$bases["t^$al"]], $extra);
        $w = lsq($cols, $y);
        $pred = [];
        for ($i = 0; $i < $n; $i++) { $pred[] = predict($w, $cols, $i); }
        $my = array_sum($y) / $n;
        $ssRes = 0.0; $ssTot = 0.0;
        for ($i = 0; $i < $n; $i++) { $ssRes += ($y[$i] - $pred[$i]) ** 2; $ssTot += ($y[$i] - $my) ** 2; }
        $r2 = 1 - $ssRes / $ssTot;
        // leave-cells-out: 5 фолдов, тест = целые ячейки
        $ssResO = 0.0;
        foreach (range(0, 4) as $f) {
            $testCells = array_flip($folds[$f]);
            $trainI = []; $testI = [];
            for ($i = 0; $i < $n; $i++) {
                if (isset($testCells[$cid[$i]])) { $testI[] = $i; } else { $trainI[] = $i; }
            }
            // ПЛОТНЫЕ подвекторы
            $colsT = [];
            foreach ($cols as $c) { $sub = []; foreach ($trainI as $i) { $sub[] = $c[$i]; } $colsT[] = $sub; }
            $yT = []; foreach ($trainI as $i) { $yT[] = $y[$i]; }
            $wF = lsq($colsT, $yT);
            foreach ($testI as $i) { $ssResO += ($y[$i] - predict($wF, $cols, $i)) ** 2; }
        }
        $oos = 1 - $ssResO / $ssTot;
        $key = "t^$al $tag";
        $results[$key] = ['r2' => $r2, 'oos' => $oos];
        printf("%-22s %10.4f %12.4f\n", $key, $r2, $oos);
    }
}

// сравнение: alpha=0.5 мерялся ранее (OOS 0.678) — sqrt НЕ нужен
$oos05 = 0.678; $oos10 = $results['t^1 base']['oos']; $oos20 = $results['t^2 base']['oos'];
$oosBest = -9.99; $bestKey = '?';
foreach ($results as $k2 => $v2) { if ($v2['oos'] > $oosBest) { $oosBest = $v2['oos']; $bestKey = $k2; } }
echo "\nЛучший: $bestKey (OOS " . round($oosBest, 4) . ")\n";
if ($oos05 - max($oos10, $oos20) > 0.02) {
    echo "ВЕРДИКТ: alpha=0.5 существенно лучше OOS (Δ=" . round($oos05 - max($oos10, $oos20), 3)
        . ") -> грамматике НУЖЕН sqrt (диффузионный терм). Расширение с контрактом оправдано.\n";
} elseif (max($oos10, $oos20) - $oos05 > 0.02) {
    echo "ВЕРДИКТ: t^1/t^2 достаточно (sqrt хуже OOS 0.678 против " . round(max($oos10, $oos20), 3)
        . ") -> грамматику НЕ трогать.\n";
    if ($oosBest - max($oos10, $oos20) > 0.03) {
        echo "НО: взаимодействия t²×химия дают +" . round($oosBest - max($oos10, $oos20), 3)
            . " OOS -> режимный сдвиг кривизны реален и выразим в грамматике (×).\n";
    } else {
        echo "Остаток ~" . round(1 - $oosBest, 3) . " дисперсии — режимные факторы (C-rate/T), отсутствующие в выгрузке: ДАННЫЕ-дефицит.\n";
    }
} else {
    echo "ВЕРДИКТ: класс кривизны не различим — нужно больше фич.\n";
}
