<?php
declare(strict_types=1);

/**
 * B6.2b: Углублённый null-тест BO-никотината — ответ на вопрос
 * «какая доля null, не фикция ли?».
 *
 * z=143/200 (p=0.285 по cv) — ниже порога значимости. Но cv — грубая
 * мера (1-R2 на 2 параметрах). Проверяем ТРИ меры на null:
 *   1. |corr(f, residual)| — сила связи
 *   2. cv формы (уже было)
 *   3. MDL-выигрыш (биты)
 * и строим точные p-значения + визуализацию квантилей.
 *
 * Плюс:null с СОХРАНЕНИЕМ СТРУКТУРЫ — перемешиваем не весь профиль
 * вида j, а только роли (какой метаболит каким видом производится),
 * чтобы дестабилизировать биологию, но сохранить масштабы пулов.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];
$SP = 'BO';
$M1 = 'nicotinate_negative_7p52';

$xml = file_get_contents('/tmp/ven_s006/twelveSpeciesT3.xml');
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

$rows = openpyxl_load($VEN . '/ev4_metabolites.csv');
$header = array_map(fn ($v) => trim(str_replace(["'", "\r"], '', $v)), $rows[0]);
$speciesEV4 = array_slice($header, 1);
$metab = [];
foreach (array_slice($rows, 1) as $r) {
    $name = trim(str_replace(["'", "\r"], '', $r[0]));
    if ($name === '') { continue; }
    foreach ($speciesEV4 as $c => $sp) {
        $v = trim((string) ($r[$c + 1] ?? ''));
        if (is_numeric($v)) { $metab[$name][$sp] = (float) $v; }
    }
}

$raw = file($VEN . '/ev3_communities.csv');
$communities = [];
$cur = null;
foreach ($raw as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (preg_match('/^([A-Z]{2}-|NONE)/', $line)) { $cur = rtrim($line); $communities[$cur] = []; continue; }
    if ($cur !== null && preg_match('/^[\d\s.eE+-]+$/', $line)) {
        $communities[$cur][] = array_map('floatval', preg_split('/\s+/', trim($line)));
    }
}

$buildRows = function (?array $M = null) use ($communities, $ev3order, $metab, $params, $SP, $M1): array {
    $MM = $M ?? $metab;
    $out = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            $xRow = [];
            foreach ($ev3order as $c => $sp2) { $xRow[$sp2] = $mat[$t][$c]; }
            $xi = $xRow[$SP]; $xin = $mat[$t + 1][array_search($SP, $ev3order, true)] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $s = $params['u' . $SP];
            foreach ($ev3order as $j) { $s += $params['a' . $SP . $j] * $xRow[$j]; }
            $pool = 0.0;
            foreach ($xRow as $j => $xj) { if ($j !== $SP) { $pool += ($MM[$M1][$j] ?? 0.0) * $xj; } }
            $out[] = ['comm' => $label, 't' => $t, 'xi' => $xi, 'pool' => $pool, 'y' => ($xin - $xi) / $xi - $s];
        }
    }
    return $out;
};

$metrics = function (array $rows): array {
    $n = count($rows);
    $f = []; $y = [];
    $xmax = 0.0;
    foreach ($rows as $r) { $xmax = max($xmax, $r['xi']); }
    foreach ($rows as $r) { $f[] = $r['pool'] / ($r['xi'] / max($xmax, 1e-9)); $y[] = $r['y']; }
    $mf = array_sum($f) / $n; $my = array_sum($y) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($f[$i] - $mf) * ($y[$i] - $my); $sxx += ($f[$i] - $mf) ** 2; $syy += ($y[$i] - $my) ** 2; }
    if ($sxx < 1e-12 || $syy < 1e-12) { return ['corr' => 0.0, 'cv' => 9.99, 'mdl' => -INF]; }
    $corr = $sxy / sqrt($sxx * $syy);
    $b = $sxy / $sxx; $a = $my - $b * $mf;
    $res = 0.0;
    for ($i = 0; $i < $n; $i++) { $res += ($y[$i] - ($a + $b * $f[$i])) ** 2; }
    $cv = $res / $syy;
    $sd = sqrt($res / $n);
    $mdl = -$n * log(2 * M_PI * M_E * $sd ** 2, 2) / 2;
    return ['corr' => abs($corr), 'cv' => $cv, 'mdl' => $mdl];
};

$real = $metrics($buildRows());
printf("РЕАЛЬНЫЙ BO-никотинат: |corr|=%.3f cv=%.3f mdl=%.1f bits\n", $real['corr'], $real['cv'], $real['mdl']);

// null-1: перемешивание профилей видов (как B6.2)
mt_srand(777);
$n1 = ['corr' => [], 'cv' => [], 'mdl' => []];
for ($p = 0; $p < 500; $p++) {
    $sh = [];
    foreach ($metab as $mn => $bySp) {
        $vals = array_values($bySp);
        shuffle($vals);
        $sh[$mn] = array_combine($ev3order, $vals);
    }
    $m = $metrics($buildRows($sh));
    $n1['corr'][] = $m['corr']; $n1['cv'][] = $m['cv']; $n1['mdl'][] = $m['mdl'];
}

// null-2: роли с сохранением масштабов — переставляем метаболит с
// сохранением распределения пулов: переставляем ВЕКТОР пула по строкам
mt_srand(555);
$n2 = ['corr' => [], 'cv' => [], 'mdl' => []];
$rowsReal = $buildRows();
for ($p = 0; $p < 500; $p++) {
    $rowsSh = $rowsReal;
    $pools = array_column($rowsReal, 'pool');
    shuffle($pools);
    foreach ($rowsSh as $i => &$r) { $r['pool'] = $pools[$i]; }
    unset($r);
    $m = $metrics($rowsSh);
    $n2['corr'][] = $m['corr']; $n2['cv'][] = $m['cv']; $n2['mdl'][] = $m['mdl'];
}

$q = function (array $a, float $q): float { sort($a); return $a[max(0, (int) floor($q * (count($a) - 1)))]; };
$pct = function (array $null, float $real, bool $lowerBetter): float {
    $c = 0;
    foreach ($null as $v) { if ($lowerBetter ? $v <= $real : $v >= $real) { $c++; } }
    return $c / count($null);
};

echo "\n=== null-1: перемешанные EV4-профили (500) ===\n";
printf("corr: реальный %.3f | null q05=%.3f q50=%.3f q95=%.3f | p(лучше null)=%.3f\n",
    $real['corr'], $q($n1['corr'], 0.05), $q($n1['corr'], 0.5), $q($n1['corr'], 0.95), 1 - $pct($n1['corr'], $real['corr'], false));
printf("cv:   реальный %.3f | null q05=%.3f q50=%.3f q95=%.3f | p(лучше null)=%.3f\n",
    $real['cv'], $q($n1['cv'], 0.05), $q($n1['cv'], 0.5), $q($n1['cv'], 0.95), $pct($n1['cv'], $real['cv'], true));
printf("mdl:  реальный %.1f | null q05=%.1f q50=%.1f q95=%.1f | p(лучше null)=%.3f\n",
    $real['mdl'], $q($n1['mdl'], 0.05), $q($n1['mdl'], 0.5), $q($n1['mdl'], 0.95), 1 - $pct($n1['mdl'], $real['mdl'], false));

echo "\n=== null-2: перестановка пула по строкам (структура сохранена, 500) ===\n";
printf("corr: p(лучше null)=%.3f | cv: p(лучше)=%.3f | mdl: p(лучше)=%.3f\n",
    1 - $pct($n2['corr'], $real['corr'], false),
    $pct($n2['cv'], $real['cv'], true),
    1 - $pct($n2['mdl'], $real['mdl'], false));

// комбинированная оценка: трёхмерный null (все три метрики одновременно лучше)
$simul = 0;
for ($i = 0; $i < 500; $i++) {
    if ($n1['corr'][$i] >= $real['corr'] && $n1['cv'][$i] <= $real['cv'] && $n1['mdl'][$i] >= $real['mdl']) { $simul++; }
}
printf("\nОдновременный null (все 3 метрики лучше реальных): %d/500 = p=%.3f\n", $simul, $simul / 500);
