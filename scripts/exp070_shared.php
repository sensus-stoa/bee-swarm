<?php
declare(strict_types=1);

/** Общие функции B5 (Venturelli). */

/** csv -> массив строк */
function openpyxl_load(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    while (($r = fgetcsv($fh)) !== false) { $rows[] = $r; }
    fclose($fh);
    return $rows;
}

/** LSQ с ridge (канон B4) */
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

/** RK4-интеграция gLV: dx_i/dt = x_i*(r_i + sum_j a_ij*x_j) */
function glvIntegrate(array $species, array $r, array $A, array $x0, float $tEnd, int $nOut): array
{
    $n = count($species);
    $f = function (array $x) use ($n, $r, $A, $species) {
        $dx = [];
        for ($i = 0; $i < $n; $i++) {
            $s = $r[$species[$i]];
            for ($j = 0; $j < $n; $j++) {
                $s += $A[$species[$i]][$species[$j]] * $x[$j];
            }
            $dx[] = $x[$i] * $s;
        }
        return $dx;
    };
    $dt = $tEnd / (($nOut - 1) * 24); // шаг интегратора = 0.25 час при 24 подшагах/точку? упрощённо ниже
    // проще: интегрируем с малым шагом до tEnd, записывая на равной сетке
    $nSub = 240; // подшагов на интервал
    $dt = $tEnd / (($nOut - 1) * $nSub);
    $out = [$x0];
    $xc = $x0;
    for ($k = 1; $k < $nOut; $k++) {
        for ($s = 0; $s < $nSub; $s++) {
            $k1 = $f($xc);
            $x2 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $xc, $k1);
            $k2 = $f($x2);
            $x3 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $xc, $k2);
            $k3 = $f($x3);
            $x4 = array_map(fn ($v, $d) => $v + $d * $dt, $xc, $k3);
            $k4 = $f($x4);
            $xc = array_map(fn ($v, $a1, $a2, $a3, $a4) => $v + $dt / 6 * ($a1 + 2 * $a2 + 2 * $a3 + $a4), $xc, $k1, $k2, $k3, $k4);
        }
        $out[] = $xc;
    }
    return $out;
}
