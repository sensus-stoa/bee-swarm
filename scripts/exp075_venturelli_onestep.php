<?php
declare(strict_types=1);

/**
 * B5.6: Дихотомия one-step на EV3 (блатIND-проверка формы приращений).
 *
 * Train: EV1+EV2 (приращения вида i при соседе j). Test: EV3 — приращения
 * КАЖДОГО из 12 видов по времени (наблюдаемые x!). Модель:
 *   y_i = r_i + d_i*x_i + sum_j a_ij*x_j  (a_ij известно только для
 * пар, где i — измеренный вид в EV1; остальные соседи — avgA_i).
 * M0: без x_i^2; M1: + b_i*x_i^2.
 *
 * Это чистая проверка ФОРМЫ (не интегратора): наблюдаемые x -> предсказание
 * приращения. EV3 не участвует в фите.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';

// --- train данные: EV1 + EV2 ---
$rows = openpyxl_load($VEN . '/ev1_pairs.csv');
$data = [];
foreach ($rows as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $s1 = substr($name, 0, 2); $s2 = substr($name, 2, 2);
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t]; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $data[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => $s1, 'sj' => $s2, 'src' => 'EV1'];
    }
}
$rows2 = openpyxl_load($VEN . '/ev2_pairs.csv');
$phaseIdx = [];
foreach ($rows2 as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $phaseIdx[$name] = ($phaseIdx[$name] ?? -1) + 1;
    $s1 = substr($name, 0, 2); $s2 = substr($name, 2, 2);
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t] ?? NAN; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $data[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => $s1, 'sj' => $s2, 'src' => 'EV2'];
    }
}

// --- дизайн: r_i, d_i, a_ij (паро-спец, только измеренные ориентации), опц. sq_i ---
$species = ['BH', 'BO', 'BT', 'BU', 'BV', 'CA', 'CH', 'DP', 'EL', 'ER', 'FP', 'PC'];

$fit = function (bool $withSq) use ($data, $species): array {
    $colDefs = [];
    $idx = 0;
    foreach ($species as $i) { $colDefs['r_' . $i] = $idx++; }
    foreach ($species as $i) { $colDefs['d_' . $i] = $idx++; }
    if ($withSq) { foreach ($species as $i) { $colDefs['sq_' . $i] = $idx++; } }
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
        if ($withSq) { $v[$colDefs['sq_' . $i]] = $xi * $xi; }
        $v[$colDefs['a_' . $i . '>' . $j]] = $xj;
        foreach ($v as $p => $val) { $cols[$p][] = $val; }
        $y[] = $row['y'];
    }
    $w = lsqR($cols, $y);
    $w0 = $w[0]; $wc = array_slice($w, 1);
    // train R2
    $res = 0.0; $m = array_sum($y) / count($y); $tot = 0.0;
    $nR = count($y);
    for ($i = 0; $i < $nR; $i++) {
        $s = $w0;
        foreach ($cols as $p => $col) { $s += $wc[$p] * $col[$i]; }
        $res += ($y[$i] - $s) ** 2; $tot += ($y[$i] - $m) ** 2;
    }
    // средний a_ij по виду i (для неиспытанных соседей)
    $sumA = []; $cntA = [];
    foreach ($colDefs as $name => $p) {
        if (preg_match('/^a_([A-Z]{2})>/', $name, $mm)) {
            $sumA[$mm[1]] = ($sumA[$mm[1]] ?? 0.0) + $wc[$p];
            $cntA[$mm[1]] = ($cntA[$mm[1]] ?? 0) + 1;
        }
    }
    $avgA = [];
    foreach ($sumA as $i => $s) { $avgA[$i] = $s / $cntA[$i]; }
    return ['colDefs' => $colDefs, 'w' => $wc, 'w0' => $w0, 'trainR2' => 1 - $res / $tot, 'avgA' => $avgA];
};

$m0 = $fit(false);
$m1 = $fit(true);
echo 'train: M0 R2=' . round($m0['trainR2'], 3) . ' | M1 R2=' . round($m1['trainR2'], 3) . ' (delta +' . round($m1['trainR2'] - $m0['trainR2'], 3) . ")\n";

// --- predict функция: для вида i при наблюдаемом векторе долей ---
$predY = function (string $i, array $xRow, array $m, bool $withSq): float {
    $xi = $xRow[$i] ?? 0.0;
    $s = $m['w0'] + ($m['w'][$m['colDefs']['r_' . $i]] ?? 0.0)
        + ($m['w'][$m['colDefs']['d_' . $i]] ?? 0.0) * $xi;
    if ($withSq) { $s += ($m['w'][$m['colDefs']['sq_' . $i]] ?? 0.0) * $xi * $xi; }
    // соседи: известные a_ij + avgA для остальных
    $sKnown = 0.0; $sumKnownX = 0.0; $sumUnknownX = 0.0;
    foreach ($xRow as $j => $xj) {
        if ($j === $i || $xj <= 0) { continue; }
        if (isset($m['colDefs']['a_' . $i . '>' . $j])) {
            $sKnown += ($m['w'][$m['colDefs']['a_' . $i . '>' . $j]] ?? 0.0) * $xj;
            $sumKnownX += $xj;
        } else {
            $sumUnknownX += $xj;
        }
    }
    $s += $sKnown + ($m['avgA'][$i] ?? 0.0) * $sumUnknownX;
    return $s;
};

// --- EV3: приращения всех 12 видов (наблюдаемые x) ---
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

$eval = function (array $m, bool $withSq, callable $predY) use ($communities, $ev3order): array {
    $res = 0.0; $tot = 0.0; $nRows = 0;
    $perSp = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            // наблюдаемый вектор долей на шаге t
            $xRow = [];
            foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
            foreach ($ev3order as $c => $sp) {
                $xi = $mat[$t][$c]; $xin = $mat[$t + 1][$c] ?? null;
                if ($xin === null || $xi < 0.01) { continue; }
                $yObs = ($xin - $xi) / $xi;
                $res += ($yObs - $predY($sp, $xRow, $m, $withSq)) ** 2;
                $perSp[$sp][] = [$yObs, $predY($sp, $xRow, $m, $withSq)];
                $nRows++;
            }
        }
    }
    // R2 общий: mean по всем yObs
    $mY = 0.0;
    // пересчёт mean: нужна сумма yObs — соберём
    return ['res' => $res, 'nRows' => $nRows, 'perSp' => $perSp];
};

// mean yObs по EV3
$yObsAll = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        foreach ($ev3order as $c => $sp) {
            $xi = $mat[$t][$c]; $xin = $mat[$t + 1][$c] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $yObsAll[] = ($xin - $xi) / $xi;
        }
    }
}
$mY = array_sum($yObsAll) / count($yObsAll);
$totY = 0.0;
foreach ($yObsAll as $v) { $totY += ($v - $mY) ** 2; }

echo "\n=== one-step blind на EV3 (наблюдаемые x, приращения) ===\n";
$r0 = $eval($m0, false, $predY);
$r1 = $eval($m1, true, $predY);
printf("M0: R2=%+.3f (n=%d)\n", 1 - $r0['res'] / $totY, $r0['nRows']);
printf("M1: R2=%+.3f (n=%d)\n", 1 - $r1['res'] / $totY, $r1['nRows']);
// корреляция
$pear = function (array $pairs): float {
    $obs = array_column($pairs, 0); $pred = array_column($pairs, 1);
    $n = count($obs); $mo = array_sum($obs) / $n; $mp = array_sum($pred) / $n;
    $cov = 0.0; $va = 0.0; $vb = 0.0;
    for ($i = 0; $i < $n; $i++) { $cov += ($obs[$i] - $mo) * ($pred[$i] - $mp); $va += ($obs[$i] - $mo) ** 2; $vb += ($pred[$i] - $mp) ** 2; }
    return ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0;
};
// собрать пары для корреляции
$pairs0 = []; $pairs1 = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        $xRow = [];
        foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
        foreach ($ev3order as $c => $sp) {
            $xi = $mat[$t][$c]; $xin = $mat[$t + 1][$c] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $yObs = ($xin - $xi) / $xi;
            $pairs0[] = [$yObs, $predY($sp, $xRow, $m0, false)];
            $pairs1[] = [$yObs, $predY($sp, $xRow, $m1, true)];
        }
    }
}
echo 'корреляция M0: ' . round($pear($pairs0), 3) . ' | M1: ' . round($pear($pairs1), 3) . "\n";

// per-species R2
echo "\nper-species R2 (M0 | M1):\n";
foreach ($ev3order as $sp) {
    $o0 = $r0['perSp'][$sp] ?? [];
    $o1 = $r1['perSp'][$sp] ?? [];
    if (!$o0) { continue; }
    $mm = 0.0;
    foreach ($o0 as $p) { $mm += $p[0]; }
    $mm /= count($o0);
    $tS = 0.0; $rS0 = 0.0; $rS1 = 0.0;
    foreach ($o0 as $k2 => $p) { $tS += ($p[0] - $mm) ** 2; $rS0 += ($p[0] - $p[1]) ** 2; }
    foreach ($o1 as $p) { $rS1 += ($p[0] - $p[1]) ** 2; }
    printf("  %s: M0=%+.3f M1=%+.3f\n", $sp, $tS > 1e-12 ? 1 - $rS0 / $tS : NAN, $tS > 1e-12 ? 1 - $rS1 / $tS : NAN);
}
