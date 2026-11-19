<?php
declare(strict_types=1);

/**
 * B5.7: Дихотомия композиционного уровня — тест контракта B5.6.
 *
 * Контракт (из Residual Observer + one-step): одношаговая динамика вида i
 * в композиции требует терма УРОВНЯ КОМПОЗИЦИИ, а не парного.
 *
 * Кандидаты (все выразимы +×):
 *   M0 (baseline B5.6): y_i = r_i + d_i*x_i + avgA_i*x_others
 *   M2a: + c_i * x_i * x_top1        (модуляция доминантом)
 *   M2b: + g_i * x_i * x_sum_other   (глобальная модуляция через соседей)
 *   M2c: + h_i * x_i * richness      (переключение по богатству)
 *   M2d: M2a+M2b
 *
 * Фит: EV1+EV2 (train), test: one-step EV3 (x_i >= 0.01). EV3 вне фита.
 * Значимость: перестановочный null по приросту R2 (200 пермутаций y train
 * — контроль "терм ловит шум"), плюс разница corr.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$species = ['BH', 'BO', 'BT', 'BU', 'BV', 'CA', 'CH', 'DP', 'EL', 'ER', 'FP', 'PC'];

// --- train: EV1 + EV2 (как B5.6) ---
$rows = openpyxl_load($VEN . '/ev1_pairs.csv');
$data = [];
foreach ($rows as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t]; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi < 0.01) { continue; }
        $data[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => $s1 = substr($name, 0, 2), 'sj' => $s2 = substr($name, 2, 2), 'src' => 'EV1'];
    }
}
$rows2 = openpyxl_load($VEN . '/ev2_pairs.csv');
$phaseIdx = [];
foreach ($rows2 as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $phaseIdx[$name] = ($phaseIdx[$name] ?? -1) + 1;
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t] ?? NAN; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi < 0.01) { continue; }
        $data[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => substr($name, 0, 2), 'sj' => substr($name, 2, 2), 'src' => 'EV2'];
    }
}

// ВАЖНО: в паре x_top1 = x_j, x_sum_other = x_j, richness = 2 (или 1) —
// композиционные термы вырождены в парные. Значит коэффициенты c_i, g_i, h_i
// НЕ идентифицируемы на EV1+EV2! Единственный честный путь: фитить их на
// ЧАСТИ EV3 (holdout-композиции) и тестировать на другой части EV3.
// Дисциплина: split композиций EV3 на train/test (половина dropouts + NONE
// в train? NONE уникален — его в test!). Сплит: 6 dropouts train, 6 dropouts
// + NONE test. R2 train/test соответственно.

// --- EV3 ---
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
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];

// строки EV3: приращения всех видов
$rowsEv3 = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        $xRow = [];
        foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
        foreach ($ev3order as $c => $sp) {
            $xi = $mat[$t][$c]; $xin = $mat[$t + 1][$c] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $row = $xRow;
            arsort($row);
            unset($row[$sp]);
            $top = array_slice($row, 0, 1, true);
            $rowsEv3[] = [
                'comm' => $label, 'sp' => $sp, 't' => $t,
                'xi' => $xi, 'xRow' => $xRow,
                'y' => ($xin - $xi) / $xi,
                'x_top1' => reset($top),
                'x_sum_other' => array_sum($row),
                'richness' => count(array_filter($row, fn ($v) => $v > 0.001)) + 1,
            ];
        }
    }
}
echo 'EV3 one-step строк: ' . count($rowsEv3) . "\n";

// train/test по композициям: NONE и 3 dropouts в test (разные функциональные
// роли: BU- — муцин-деградер, FP- — гриб-партнёр отсутствует, NONE — full)
$testComms = ['NONE', 'BU-', 'FP-', 'DP-'];
$trainEv3 = array_filter($rowsEv3, fn ($r) => !in_array($r['comm'], $testComms, true));
$testEv3 = array_filter($rowsEv3, fn ($r) => in_array($r['comm'], $testComms, true));
echo 'EV3 train-строк (для композиционных термов): ' . count($trainEv3) . '; test: ' . count($testEv3) . "\n";

// --- фит модели: M0 параметры (r_i, d_i, a_ij, avgA_i) — ИЗ EV1+EV2 (B5.6),
// композиционные коэффициенты (c_i, g_i, h_i) — LSQ на EV3-train поверх
// предсказаний M0 (иерархический: сначала парные, потом residual-термы). ---

// фит M0 (копия B5.6)
$fit0 = function () use ($data, $species): array {
    $colDefs = [];
    $idx = 0;
    foreach ($species as $i) { $colDefs['r_' . $i] = $idx++; }
    foreach ($species as $i) { $colDefs['d_' . $i] = $idx++; }
    $pairIdx = [];
    foreach ($data as $row) {
        $key = $row['si'] . '>' . $row['sj'];
        if (!isset($pairIdx[$key])) { $pairIdx[$key] = 'a_' . $key; $colDefs['a_' . $key] = $idx++; }
    }
    $k = $idx;
    $cols = array_fill(0, $k, []);
    $y = [];
    foreach ($data as $row) {
        $i = $row['si']; $j = $row['sj'];
        $xi = $row['xi']; $xj = $row['xj'];
        $v = array_fill(0, $k, 0.0);
        $v[$colDefs['r_' . $i]] = 1.0;
        $v[$colDefs['d_' . $i]] = $xi;
        $v[$colDefs['a_' . $i . '>' . $j]] = $xj;
        foreach ($v as $p => $val) { $cols[$p][] = $val; }
        $y[] = $row['y'];
    }
    $w = lsqR($cols, $y);
    $w0 = $w[0]; $wc = array_slice($w, 1);
    $sumA = []; $cntA = [];
    foreach ($colDefs as $name => $p) {
        if (preg_match('/^a_([A-Z]{2})>/', $name, $mm)) {
            $sumA[$mm[1]] = ($sumA[$mm[1]] ?? 0.0) + $wc[$p];
            $cntA[$mm[1]] = ($cntA[$mm[1]] ?? 0) + 1;
        }
    }
    $avgA = [];
    foreach ($sumA as $i => $s) { $avgA[$i] = $s / $cntA[$i]; }
    return ['colDefs' => $colDefs, 'w' => $wc, 'w0' => $w0, 'avgA' => $avgA];
};
$m0 = $fit0();

$predM0 = function (string $i, array $xRow) use ($m0): float {
    $xi = $xRow[$i] ?? 0.0;
    $s = $m0['w0'] + ($m0['w'][$m0['colDefs']['r_' . $i]] ?? 0.0)
        + ($m0['w'][$m0['colDefs']['d_' . $i]] ?? 0.0) * $xi;
    $sKnown = 0.0; $sumUnknown = 0.0;
    foreach ($xRow as $j => $xj) {
        if ($j === $i || $xj <= 0) { continue; }
        if (isset($m0['colDefs']['a_' . $i . '>' . $j])) {
            $sKnown += ($m0['w'][$m0['colDefs']['a_' . $i . '>' . $j]] ?? 0.0) * $xj;
        } else {
            $sumUnknown += $xj;
        }
    }
    return $s + $sKnown + ($m0['avgA'][$i] ?? 0.0) * $sumUnknown;
};

// residual-фит композиционных термов на EV3-train (per-species, ridge)
$fitResidual = function (array $rows, array $terms) use ($m0, $predM0, $species): array {
    $colDefs = [];
    $idx = 0;
    foreach ($species as $i) {
        foreach ($terms as $t) { $colDefs[$t . '_' . $i] = $idx++; }
    }
    $k = $idx;
    $cols = array_fill(0, $k, []);
    $y = [];
    foreach ($rows as $row) {
        $i = $row['sp']; $xi = $row['xi']; $xRow = $row['xRow'];
        $base = $predM0($i, $xRow);
        $y[] = $row['y'] - $base;
        foreach ($terms as $t) {
            $val = match ($t) {
                'c' => $xi * $row['x_top1'],
                'g' => $xi * $row['x_sum_other'],
                'h' => $xi * $row['richness'],
                default => 0.0,
            };
            $cols[$colDefs[$t . '_' . $i]][] = $val;
        }
    }
    $w = lsqR($cols, $y);
    $w0 = $w[0]; $wc = array_slice($w, 1);
    return ['colDefs' => $colDefs, 'w' => $wc, 'w0' => $w0, 'terms' => $terms];
};

$predFull = function (string $i, array $xRow, array $resid) use ($m0, $predM0): float {
    $base = $predM0($i, $xRow);
    $xi = $xRow[$i] ?? 0.0;
    $row = $xRow;
    arsort($row);
    unset($row[$i]);
    $top = array_slice($row, 0, 1, true);
    $xTop1 = reset($top);
    $xSum = array_sum($row);
    $rich = count(array_filter($row, fn ($v) => $v > 0.001)) + 1;
    $s = $resid['w0'];
    foreach ($resid['terms'] as $t) {
        $val = match ($t) {
            'c' => $xi * $xTop1,
            'g' => $xi * $xSum,
            'h' => $xi * $rich,
            default => 0.0,
        };
        $s += ($resid['w'][$resid['colDefs'][$t . '_' . $i]] ?? 0.0) * $val;
    }
    return $base + $s;
};

// --- конфигурации ---
$configs = [
    'M0' => ['terms' => []],
    'M2a' => ['terms' => ['c']],
    'M2b' => ['terms' => ['g']],
    'M2c' => ['terms' => ['h']],
    'M2ab' => ['terms' => ['c', 'g']],
];

$results = [];
foreach ($configs as $name => $cfg) {
    $resid = count($cfg['terms']) ? $fitResidual($trainEv3, $cfg['terms']) : null;
    // eval на EV3-test
    $res = 0.0; $tot = 0.0; $n = 0;
    $yObsAll = []; $yPredAll = [];
    foreach ($testEv3 as $row) {
        $pred = $resid ? $predFull($row['sp'], $row['xRow'], $resid) : $predM0($row['sp'], $row['xRow']);
        $yObsAll[] = $row['y']; $yPredAll[] = $pred;
        $res += ($row['y'] - $pred) ** 2; $n++;
    }
    $m = array_sum($yObsAll) / count($yObsAll);
    foreach ($yObsAll as $v) { $tot += ($v - $m) ** 2; }
    // corr
    $mo = array_sum($yObsAll) / $n; $mp = array_sum($yPredAll) / $n;
    $cov = 0.0; $va = 0.0; $vb = 0.0;
    for ($i = 0; $i < $n; $i++) { $cov += ($yObsAll[$i] - $mo) * ($yPredAll[$i] - $mp); $va += ($yObsAll[$i] - $mo) ** 2; $vb += ($yPredAll[$i] - $mp) ** 2; }
    $corr = ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0;
    $results[$name] = ['r2' => 1 - $res / $tot, 'corr' => $corr, 'n' => $n];
    printf("%-6s: EV3-test R2=%+.3f corr=%+.3f (n=%d)\n", $name, $results[$name]['r2'], $corr, $n);
}

// перестановочный null для лучшей модели: перемешать residual-цель на train
echo "\nnull-контроль (перемешанный residual на train, 50 пермутаций):\n";
$best = 'M2ab';
$terms = $configs[$best]['terms'];
$nullR2 = [];
for ($perm = 0; $perm < 50; $perm++) {
    $rowsSh = $trainEv3;
    // перемешать y по строкам
    $ys = array_column($rowsSh, 'y');
    mt_srand(50000 + $perm);
    shuffle($ys);
    foreach ($rowsSh as $i => $r) { $rowsSh[$i]['y'] = $ys[$i]; }
    $residP = $fitResidual($rowsSh, $terms);
    $res = 0.0; $tot = 0.0;
    foreach ($testEv3 as $row) {
        $pred = $predFull($row['sp'], $row['xRow'], $residP);
        $res += ($row['y'] - $pred) ** 2;
    }
    foreach ($testEv3 as $row) { $tot += ($row['y'] - $m) ** 2; }
    $nullR2[] = 1 - $res / $tot;
}
$mN = array_sum($nullR2) / count($nullR2);
$sdN = sqrt(array_sum(array_map(fn ($v) => ($v - $mN) ** 2, $nullR2)) / count($nullR2));
printf("null M2ab: mean R2=%+.3f sd=%.3f | реальный %+.3f | z=%+.2f\n", $mN, $sdN, $results[$best]['r2'], $sdN > 1e-9 ? ($results[$best]['r2'] - $mN) / $sdN : 0.0);
