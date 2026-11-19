<?php
declare(strict_types=1);

/**
 * B6.5-E: РЕВАЛЬДАЦИЯ редокс-градиента после condition audit.
 *
 * Вопрос пользователя: «история с редокс неправильная?» — нет, но она
 * прошла тот же тест, что и формы: её надо перегнать БЕЗ ER/FP и с
 * фиксом перемешивания. Если редокс-градиент (corr(dR2, redox)=+0.46)
 * держится на acetate-свободных видах и на правильном null — он жив.
 * Если нет — был артефактом.
 *
 * Плюс N3-совместимость: redox-дескриптор не должен объясняться сам
 * через acetate-условие (ER/FP метаболиты — их redox-профиль мог
 * быть просто другим из-за среды).
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$ev3orderAll = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];
$excluded = ['ER', 'FP'];
$ev3order = array_values(array_diff($ev3orderAll, $excluded));

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

$chem = function (string $name): array {
    $n = strtolower($name);
    $redoxPatterns = ['sulfoxide', 'hydroxy', 'oxo', 'deoxy', 'cyclic', 'hydroquin', 'quinol', 'glutar', 'citramal', 'ketobuty', 'dihydroxy', 'phospho'];
    $redoxCount = 0;
    foreach ($redoxPatterns as $pat) { if (str_contains($n, $pat)) { $redoxCount++; } }
    return ['redox' => $redoxCount, 'len' => strlen($name)];
};

$buildRows = function (string $sp, array $mNames) use ($communities, $ev3orderAll, $metab, $params): array {
    $out = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            $xRow = [];
            foreach ($ev3orderAll as $c => $sp2) { $xRow[$sp2] = $mat[$t][$c]; }
            $xi = $xRow[$sp]; $xin = $mat[$t + 1][array_search($sp, $ev3orderAll, true)] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $s = $params['u' . $sp];
            foreach ($ev3orderAll as $j) { $s += $params['a' . $sp . $j] * $xRow[$j]; }
            $pools = [];
            foreach ($mNames as $mn) {
                $pool = 0.0;
                foreach ($xRow as $j => $xj) { if ($j !== $sp) { $pool += ($metab[$mn][$j] ?? 0.0) * $xj; } }
                $pools[$mn] = $pool;
            }
            $out[] = ['comm' => $label, 't' => $t, 'xi' => $xi, 'pools' => $pools, 'y' => ($xin - $xi) / $xi - $s];
        }
    }
    return $out;
};

$fitR2 = function (array $rows, array $mNames): float {
    $n = count($rows);
    $y = array_column($rows, 'y');
    $k = count($mNames) + 1;
    if ($n < $k + 3) { return NAN; }
    $X = [];
    foreach ($rows as $r) {
        $row = [1.0];
        foreach ($mNames as $mn) { $row[] = $r['pools'][$mn]; }
        $X[] = $row;
    }
    $A = array_fill(0, $k, array_fill(0, $k, 0.0));
    $b = array_fill(0, $k, 0.0);
    for ($i = 0; $i < $n; $i++) {
        for ($p = 0; $p < $k; $p++) {
            $b[$p] += $X[$i][$p] * $y[$i];
            for ($q = 0; $q < $k; $q++) { $A[$p][$q] += $X[$i][$p] * $X[$i][$q]; }
        }
    }
    for ($p = 0; $p < $k; $p++) {
        $mx = $p;
        for ($q = $p + 1; $q < $k; $q++) { if (abs($A[$q][$p]) > abs($A[$mx][$p])) { $mx = $q; } }
        [$A[$p], $A[$mx]] = [$A[$mx], $A[$p]];
        [$b[$p], $b[$mx]] = [$b[$mx], $b[$p]];
        if (abs($A[$p][$p]) < 1e-12) { return NAN; }
        for ($q = $p + 1; $q < $k; $q++) {
            $f = $A[$q][$p] / $A[$p][$p];
            for ($r2 = $p; $r2 < $k; $r2++) { $A[$q][$r2] -= $f * $A[$p][$r2]; }
            $b[$q] -= $f * $b[$p];
        }
    }
    $w = array_fill(0, $k, 0.0);
    for ($p = $k - 1; $p >= 0; $p--) {
        $s = $b[$p];
        for ($q = $p + 1; $q < $k; $q++) { $s -= $A[$p][$q] * $w[$q]; }
        $w[$p] = $s / $A[$p][$p];
    }
    $res = 0.0; $tot = 0.0;
    $my = array_sum($y) / $n;
    foreach ($y as $v) { $tot += ($v - $my) ** 2; }
    for ($i = 0; $i < $n; $i++) {
        $pred = 0.0;
        for ($p = 0; $p < $k; $p++) { $pred += $w[$p] * $X[$i][$p]; }
        $res += ($y[$i] - $pred) ** 2;
    }
    return $tot > 1e-12 ? 1 - $res / $tot : NAN;
};

// редокс-градиент на acetate-свободных видах, 2 глубины (2 и 3 фичи)
$SEL = json_decode(file_get_contents('/tmp/e080_selection.json'), true);
$allSteps = [];
foreach ($ev3order as $sp) {
    $ms = $SEL[$sp] ?? [];
    if (count($ms) < 3) { continue; }
    $rows = $buildRows($sp, $ms);
    $r2_1 = $fitR2($rows, [$ms[0]]);
    $r2_2 = $fitR2($rows, [$ms[0], $ms[1]]);
    $r2_3 = $fitR2($rows, [$ms[0], $ms[1], $ms[2]]);
    foreach ([1 => $ms[1], 2 => $ms[2]] as $d => $mn) {
        $ch = $chem($mn);
        $prev = $d === 1 ? $r2_1 : $r2_2;
        $cur = $d === 1 ? $r2_2 : $r2_3;
        if (!is_finite($prev) || !is_finite($cur)) { continue; }
        $allSteps[] = ['dR2' => $cur - $prev, 'redox' => $ch['redox'], 'len' => $ch['len'], 'sp' => $sp];
    }
}
$stat = function (string $key) use ($allSteps): float {
    $n = count($allSteps);
    $xs = array_column($allSteps, 'dR2'); $ys = array_column($allSteps, $key);
    $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($xs[$i] - $mx) * ($ys[$i] - $my); $sxx += ($xs[$i] - $mx) ** 2; $syy += ($ys[$i] - $my) ** 2; }
    return ($sxx > 1e-12 && $syy > 1e-12) ? $sxy / sqrt($sxx * $syy) : 0.0;
};
// null: перемешать соответствие dR2<->redox внутри вектора
mt_srand(424242);
$nullC = [];
$reds = array_column($allSteps, 'redox');
$drs = array_column($allSteps, 'dR2');
for ($p = 0; $p < 500; $p++) {
    $sh = $reds;
    shuffle($sh);
    $n = count($drs);
    $mx = array_sum($drs) / $n; $my = array_sum($sh) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($drs[$i] - $mx) * ($sh[$i] - $my); $sxx += ($drs[$i] - $mx) ** 2; $syy += ($sh[$i] - $my) ** 2; }
    $nullC[] = ($sxx > 1e-12 && $syy > 1e-12) ? $sxy / sqrt($sxx * $syy) : 0.0;
}
$r = $stat('redox');
sort($nullC);
$pGreater = count(array_filter($nullC, fn ($c) => $c >= $r)) / 500;
printf("Редокс-градиент на acetate-свободных видах (9 видов, n=%d шагов):\n", count($allSteps));
printf("  corr(dR2, redox) = %+.3f | null 95%%-квантиль = %+.3f | p = %.3f\n", $r, $nullC[(int) (0.95 * 499)], $pGreater);
printf("  Вердикт: %s\n", $pGreater < 0.05 ? 'ЖИВЁТ (p<0.05)' : 'не подтверждён');

// Тест 2: redox-разница ER/FP vs остальных (был ли redox-профиль acetate-условия)
$redoxIn = [];
foreach ($metab as $mn => $bySp) {
    $ch = $chem($mn);
    foreach ($bySp as $sp => $v) {
        if (!isset($redoxIn[$sp])) { $redoxIn[$sp] = []; }
        $redoxIn[$sp][] = $ch['redox'];
    }
}
echo "\nredox-средний по профилям видов:\n";
foreach ($ev3orderAll as $sp) {
    $m = array_sum($redoxIn[$sp]) / count($redoxIn[$sp]);
    printf("  %s: %.3f %s\n", $sp, $m, in_array($sp, $excluded, true) ? '<- acetate' : '');
}
