<?php
declare(strict_types=1);

/**
 * B5.10: ПОЛНЫЙ прогон — все 97 метаболитов EV4 на 12 видов.
 *
 * Архитектура (канон «per-object параметры — per-object фит», урок B4.2):
 *   y_i = r_i + d_i*x_i + sum_f b_if * m_f
 * где m_f = sum_j x_f(j)*x_j (EV4-пул метаболита f, взвешенный долями).
 * НЕ 97 фич в Search — per-species LSQ с отбором (null-гейт на train,
 * then OOS). Search::find подключим на ОТБОРАННЫХ фичах после этого
 * гейта (пул depth-2 квадратичен — 97 фич туда не входят).
 *
 * Схема (по целым композициям, nested):
 *   фит композиционных термов: 6 dropouts EV3
 *   test: NONE + BU- + FP- + DP- (как B5.7 — сравнение с M0/M2)
 *   baseline: M0 (перенос парных, B5.6)
 *
 * Отбор метаболитов: на train — корреляция m_f с residual M0, null
 * перестановками (200), берём |r| с z>=4, не более 10 на вид.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$species = ['BH', 'BO', 'BT', 'BU', 'BV', 'CA', 'CH', 'DP', 'EL', 'ER', 'FP', 'PC'];

// --- EV4: 97 x 12 ---
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
echo 'EV4: ' . count($metab) . " метаболитов\n";

// --- EV3: строки приращений с метаболическим контекстом (ПОЛНЫЙ: 97 пулов) ---
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

// метаболические пулы m_f для каждой (comm, t): 97-вектор
$mPool = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 7; $t++) {
        $xRow = [];
        foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
        foreach ($metab as $mname => $bySp) {
            $pool = 0.0;
            foreach ($ev3order as $sp) { $pool += ($bySp[$sp] ?? 0.0) * $xRow[$sp]; }
            $mPool[$label][$t][$mname] = $pool;
        }
    }
}

// one-step строки: (comm, sp, t): y_i, x_i, mPool
$rowsEv3 = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        foreach ($ev3order as $c => $sp) {
            $xi = $mat[$t][$c]; $xin = $mat[$t + 1][$c] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $rowsEv3[] = [
                'comm' => $label, 'sp' => $sp, 't' => $t,
                'xi' => $xi, 'y' => ($xin - $xi) / $xi,
                'm' => $mPool[$label][$t],
            ];
        }
    }
}
echo 'one-step строк: ' . count($rowsEv3) . "\n";

// --- M0 baseline (перенос парных, копия B5.6) ---
$rows1 = openpyxl_load($VEN . '/ev1_pairs.csv');
$data1 = [];
foreach ($rows1 as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t]; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi < 0.01) { continue; }
        $data1[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => substr($name, 0, 2), 'sj' => substr($name, 2, 2)];
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
        $data1[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => substr($name, 0, 2), 'sj' => substr($name, 2, 2)];
    }
}
$colDefs = [];
$idx = 0;
foreach ($species as $i) { $colDefs['r_' . $i] = $idx++; }
foreach ($species as $i) { $colDefs['d_' . $i] = $idx++; }
$pairIdx = [];
foreach ($data1 as $row) {
    $key = $row['si'] . '>' . $row['sj'];
    if (!isset($pairIdx[$key])) { $pairIdx[$key] = 'a_' . $key; $colDefs['a_' . $key] = $idx++; }
}
$k0 = $idx;
$cols0 = array_fill(0, $k0, []); $y0 = [];
foreach ($data1 as $row) {
    $i = $row['si']; $j = $row['sj'];
    $v = array_fill(0, $k0, 0.0);
    $v[$colDefs['r_' . $i]] = 1.0;
    $v[$colDefs['d_' . $i]] = $row['xi'];
    $v[$colDefs['a_' . $i . '>' . $j]] = $row['xj'];
    foreach ($v as $p => $val) { $cols0[$p][] = $val; }
    $y0[] = $row['y'];
}
$w0lsq = lsqR($cols0, $y0);
$w0c = array_slice($w0lsq, 1);
$w00 = $w0lsq[0];
$sumA = []; $cntA = [];
foreach ($colDefs as $name => $p) {
    if (preg_match('/^a_([A-Z]{2})>/', $name, $mm)) {
        $sumA[$mm[1]] = ($sumA[$mm[1]] ?? 0.0) + $w0c[$p];
        $cntA[$mm[1]] = ($cntA[$mm[1]] ?? 0) + 1;
    }
}
$avgA = [];
foreach ($sumA as $i => $s) { $avgA[$i] = $s / $cntA[$i]; }

$predM0 = function (string $i, array $xRow) use ($colDefs, $w0c, $w00, $avgA, $species): float {
    $xi = $xRow[$i] ?? 0.0;
    $s = $w00 + ($w0c[$colDefs['r_' . $i]] ?? 0.0) + ($w0c[$colDefs['d_' . $i]] ?? 0.0) * $xi;
    $sKnown = 0.0; $sumUnknown = 0.0;
    foreach ($xRow as $j => $xj) {
        if ($j === $i || $xj <= 0) { continue; }
        if (isset($colDefs['a_' . $i . '>' . $j])) {
            $sKnown += ($w0c[$colDefs['a_' . $i . '>' . $j]] ?? 0.0) * $xj;
        } else {
            $sumUnknown += $xj;
        }
    }
    return $s + $sKnown + ($avgA[$i] ?? 0.0) * $sumUnknown;
};

// --- отбор метаболитов per-species: corr(m_f, residual M0), null 200 ---
$testComms = ['NONE', 'BU-', 'FP-', 'DP-'];
$trainRows = array_values(array_filter($rowsEv3, fn ($r) => !in_array($r['comm'], $testComms, true)));
$testRows = array_values(array_filter($rowsEv3, fn ($r) => in_array($r['comm'], $testComms, true)));
echo 'train: ' . count($trainRows) . '; test: ' . count($testRows) . "\n";

// residual M0
foreach ($trainRows as $k => $r) { $trainRows[$k]['res'] = $r['y'] - $predM0($r['sp'], $mPool[$r['comm']][$r['t']] ? array_combine($ev3order, array_fill(0, 12, 0)) : []); }
// поправка: predM0 нужен xRow — пересоберём xRow в строках
foreach ($rowsEv3 as $k => $r) {
    $rowsEv3[$k]['xRow'] = [];
    foreach ($ev3order as $sp2) { $rowsEv3[$k]['xRow'][$sp2] = $mPool[$r['comm']][$r['t']][$sp2] ?? 0; }
}
// mPool хранит метаболиты, не доли! xRow собираем из mat заново:
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        foreach ($rowsEv3 as $k => $r) {
            if ($r['comm'] === $label && $r['t'] === $t) {
                $xRow = [];
                foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
                $rowsEv3[$k]['xRow'] = $xRow;
            }
        }
    }
}
foreach ($rowsEv3 as $k => $r) {
    $rowsEv3[$k]['res'] = $r['y'] - $predM0($r['sp'], $r['xRow']);
}
$trainRows = array_values(array_filter($rowsEv3, fn ($r) => !in_array($r['comm'], $testComms, true)));
$testRows = array_values(array_filter($rowsEv3, fn ($r) => in_array($r['comm'], $testComms, true)));
echo 'residual готов: train=' . count($trainRows) . ' test=' . count($testRows) . "\n";

// per-species отбор
$mtNames = array_keys($metab);
$selected = []; // sp => [mnames]
mt_srand(777);
foreach ($species as $sp) {
    $rows = array_filter($trainRows, fn ($r) => $r['sp'] === $sp);
    $nR = count($rows);
    if ($nR < 15) { echo "$sp: мало строк ($nR) — пропуск\n"; continue; }
    $resArr = array_column($rows, 'res');
    $mMean = array_sum($resArr) / $nR;
    $sdRes = sqrt(array_sum(array_map(fn ($v) => ($v - $mMean) ** 2, $resArr)) / $nR);
    $cands = [];
    if ($sdRes < 1e-9) { echo "$sp: residual вырожден — пропуск\n"; continue; }
    foreach ($mtNames as $mn) {
        $mArr = array_map(fn ($r) => $r['m'][$mn], $rows);
        $mm = array_sum($mArr) / $nR;
        $sdM = sqrt(array_sum(array_map(fn ($v) => ($v - $mm) ** 2, $mArr)) / $nR);
        if ($sdM < 1e-9) { continue; }
        $cov = 0.0;
        $i = 0;
        foreach ($rows as $r) { $cov += ($r['res'] - $mMean) * ($mArr[$i] - $mm); $i++; }
        $rC = $cov / ($sdRes * $sdM * $nR);
        if (!is_finite($rC) || abs($rC) > 0.95) { continue; } // вырожденность/накладка
        // null: перемешать residual
        $nulls = [];
        $sh = $resArr;
        for ($p = 0; $p < 200; $p++) {
            shuffle($sh);
            $cov2 = 0.0; $i2 = 0;
            foreach ($rows as $r) { $cov2 += ($sh[$i2] - $mMean) * ($mArr[$i2] - $mm); $i2++; }
            $nulls[] = $cov2 / ($sdRes * $sdM * $nR);
        }
        sort($nulls);
        $z = 0;
        foreach ($nulls as $nv) { if (abs($nv) <= abs($rC)) { $z++; } }
        if ($z >= 195) { $cands[$mn] = $rC; } // z>=4
    }
    arsort($cands);
    $selected[$sp] = array_slice($cands, 0, 10, true);
    echo $sp . ': отобрано ' . count($selected[$sp]) . ' | ';
    $top = array_slice($cands, 0, 3, true);
    foreach ($top as $mn => $rc) { echo substr($mn, 0, 16) . '=' . round($rc, 2) . ' '; }
    echo "\n";
}

// --- fit композиционной регрессии per-species на train, eval на test ---
$evalModel = function (bool $useMetab) use ($trainRows, $testRows, $selected, $predM0, $species): array {
    // фит: y = predM0 + sum_f b_if*m_f (per-species b)
    $fitRows = $trainRows; $testR = $testRows;
    // per-species LSQ
    $bCoef = [];
    foreach ($species as $sp) {
        $rows = array_values(array_filter($fitRows, fn ($r) => $r['sp'] === $sp));
        if (!$rows) { continue; }
        $ms = $selected[$sp] ?? [];
        if (!$ms) { continue; }
        $mnames = array_keys($ms);
        $cols = array_fill(0, count($mnames), []);
        $y = [];
        foreach ($rows as $r) {
            foreach ($mnames as $p => $mn) { $cols[$p][] = $r['m'][$mn]; }
            $y[] = $r['res'];
        }
        $w = lsqR($cols, $y);
        $bCoef[$sp] = ['mnames' => $mnames, 'w' => array_slice($w, 1), 'w0' => $w[0]];
    }
    // eval
    $res = 0.0; $tot = 0.0; $n = 0;
    $obsAll = []; $predAll = [];
    foreach ($testR as $r) {
        $sp = $r['sp'];
        $pred = $predM0($sp, $r['xRow']);
        if (isset($bCoef[$sp])) {
            $bc = $bCoef[$sp];
            foreach ($bc['mnames'] as $p => $mn) { $pred += $bc['w'][$p] * $r['m'][$mn]; }
        }
        $obsAll[] = $r['y']; $predAll[] = $pred;
        $res += ($r['y'] - $pred) ** 2; $n++;
    }
    $m = array_sum($obsAll) / $n;
    foreach ($obsAll as $v) { $tot += ($v - $m) ** 2; }
    return ['r2' => 1 - $res / $tot, 'n' => $n, 'bCoef' => $bCoef];
};

$m0Eval = function () use ($testRows, $predM0): float {
    $res = 0.0; $tot = 0.0;
    $obs = []; $pred = [];
    foreach ($testRows as $r) {
        $p = $predM0($r['sp'], $r['xRow']);
        $obs[] = $r['y']; $pred[] = $p;
        $res += ($r['y'] - $p) ** 2;
    }
    $m = array_sum($obs) / count($obs);
    foreach ($obs as $v) { $tot += ($v - $m) ** 2; }
    return 1 - $res / $tot;
};
$r2M0 = $m0Eval();
$r2M = $evalModel(true);
printf("\n=== EV3-test (NONE+BU-+FP-+DP-, n=%d) ===\n", $r2M['n']);
printf("M0 (перенос парных):        R2=%+.3f\n", $r2M0);
printf("M-metab (пер-вид. термы):   R2=%+.3f\n", $r2M['r2']);
echo 'delta: ' . round($r2M['r2'] - $r2M0, 3) . "\n";

// corr
$obs = []; $pred = [];
foreach ($testRows as $r) {
    $sp = $r['sp'];
    $p = $predM0($sp, $r['xRow']);
    if (isset($r2M['bCoef'][$sp])) {
        $bc = $r2M['bCoef'][$sp];
        foreach ($bc['mnames'] as $p2 => $mn) { $p += $bc['w'][$p2] * $r['m'][$mn]; }
    }
    $obs[] = $r['y']; $pred[] = $p;
}
$n = count($obs);
$mo = array_sum($obs) / $n; $mp = array_sum($pred) / $n;
$cov = 0.0; $va = 0.0; $vb = 0.0;
for ($i = 0; $i < $n; $i++) { $cov += ($obs[$i] - $mo) * ($pred[$i] - $mp); $va += ($obs[$i] - $mo) ** 2; $vb += ($pred[$i] - $mp) ** 2; }
printf("corr: %+.3f\n", ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0);
