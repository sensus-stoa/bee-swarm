<?php
declare(strict_types=1);

/**
 * B5.4: Residual Observer на EV3 — где их T3 (SBML) ошибается систематически.
 *
 * Канон RCD шаг 2: остаток = y − предсказание baseline; ACF по времени,
 * CCF с кандидатами-функциями (x_i*x_j, x_i*x_k и др.), знаковая структура
 * по композициям; значимость — перестановочный null (200 пермутаций).
 * Вердикт: STRUCTURED (z>=4) → контракт на недостающий терм; FORMLESS →
 * «парных достаточно» (измерено нашим гейтом).
 *
 * Базлайн: python-версия B5.2 (RK4 dt=0.5h, r=0.851 — воспроизведено).
 * Здесь считаем предсказания той же схемой (RK4 48 шагов/день, dt=0.5)
 * для ВСЕХ 13 EV3-композиций, затем остатки по (вид, t, композиция).
 */

require __DIR__ . '/exp070_shared.php';

error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$SBML = '/tmp/ven_s006/twelveSpeciesT3.xml';

$xml = file_get_contents($SBML);
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }
preg_match_all('/<species [^>]*id="x([A-Z]{2})"/', $xml, $sm);
$species = $sm[1];
$n = count($species);
$rT = [];
$AT = [];
foreach ($species as $i) {
    $rT[$i] = $params['u' . $i];
    foreach ($species as $j) { $AT[$i][$j] = $params['a' . $i . $j]; }
}

// RK4 (позиционные индексы — канон после B5.3)
$f = function (array $x) use ($n, $species, $rT, $AT): array {
    $dx = [];
    foreach ($species as $idx => $i) {
        $s = $rT[$i];
        foreach ($species as $jdx => $j) { $s += $AT[$i][$j] * $x[$jdx]; }
        $dx[] = $x[$idx] * $s;
    }
    return $dx;
};
$integrate = function (array $x0v) use ($n, $species, $rT, $AT): array {
    $f = function (array $x) use ($n, $species, $rT, $AT): array {
        $dx = [];
        foreach ($species as $idx => $i) {
            $s = $rT[$i];
            foreach ($species as $jdx => $j) { $s += $AT[$i][$j] * $x[$jdx]; }
            $dx[] = $x[$idx] * $s;
        }
        return $dx;
    };
    $rk4 = function (array $x, float $dt) use ($f): array {
        $k1 = $f($x);
        $x2 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k1);
        $k2 = $f($x2);
        $x3 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k2);
        $k3 = $f($x3);
        $x4 = array_map(fn ($v, $d) => $v + $d * $dt, $x, $k3);
        $k4 = $f($x4);
        return array_map(fn ($v, $a1, $a2, $a3, $a4) => $v + $dt / 6 * ($a1 + 2 * $a2 + 2 * $a3 + $a4), $x, $k1, $k2, $k3, $k4);
    };
    $xc = $x0v;
    $traj = [$x0v];
    for ($day = 1; $day <= 6; $day++) {
        for ($h = 0; $h < 48; $h++) { $xc = $rk4($xc, 0.5); }
        $traj[] = $xc;
    }
    return $traj;
};

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
$posOf = array_flip($species); // code -> SBML index

// --- предсказания и остатки для всех 13 композиций ---
$records = []; // [comm, species, t, obs, pred]
foreach ($communities as $label => $mat) {
    $x0v = [];
    foreach ($species as $i) { $x0v[] = $mat[0][array_search($i, $ev3order, true)]; }
    $traj = $integrate($x0v);
    foreach ($ev3order as $c => $sp) {
        $si = $posOf[$sp];
        for ($t = 0; $t < 7; $t++) {
            $sum = array_sum($traj[$t]);
            $pred = $sum > 0 ? $traj[$t][$si] / $sum : 0.0;
            $records[] = [
                'comm' => $label, 'sp' => $sp, 't' => $t,
                'obs' => $mat[$t][$c],
                'pred' => $pred,
                'x_i' => $mat[$t][$c],
                // кандидаты CCF: концентрации на шаге t (наблюдаемые)
                'res' => $mat[$t][$c] - $pred,
            ];
        }
    }
}
$nRec = count($records);
echo "записей остатков: $nRec (13 композиций x 12 видов x 7 точек)\n";

// наблюдаемые концентрации других видов для CCF: нужны полные векторы obs
// пересоберём: для каждой записи добавим x_j (obs) для каждого другого вида j
$byComm = [];
foreach ($communities as $label => $mat) {
    foreach ($mat as $t => $row) {
        foreach ($ev3order as $c => $sp) { $byComm[$label][$t][$sp] = $row[$c]; }
    }
}
foreach ($records as $k => $rec) {
    $row = $byComm[$rec['comm']][$rec['t']];
    $records[$k]['x_sum_other'] = array_sum($row) - $row[$rec['sp']];
    arsort($row);
    unset($row[$rec['sp']]);
    $top = array_slice($row, 0, 2, true);
    $records[$k]['x_top1'] = reset($top);
    $records[$k]['x_top1sp'] = array_key_first($top);
    $records[$k]['x_top2'] = end($top);
    $records[$k]['richness'] = count(array_filter($row, fn ($v) => $v > 0.001)) + 1;
}

$y = array_column($records, 'res');
$mean = array_sum($y) / $nRec;
$var = 0.0;
foreach ($y as $v) { $var += ($v - $mean) ** 2; }
$var /= $nRec;
echo sprintf("остаток: mean=%.5f sd=%.5f (доля obs: %.1f%%)\n", $mean, sqrt($var), 100 * sqrt($var) / (array_sum(array_column($records, 'obs')) / $nRec));

// --- ACF по времени (внутри композиции, лаг 1) ---
$acf1 = [];
foreach ($communities as $label => $mat) {
    $series = [];
    foreach ($records as $rec) {
        if ($rec['comm'] === $label) { $series[$rec['sp']][$rec['t']] = $rec['res']; }
    }
    foreach ($series as $sp => $rr) {
        for ($t = 0; $t < 6; $t++) {
            if (isset($rr[$t], $rr[$t + 1])) { $acf1[] = $rr[$t] * $rr[$t + 1]; }
        }
    }
}
$acfMean = array_sum($acf1) / count($acf1);
$acfSd = sqrt(array_sum(array_map(fn ($v) => ($v - $acfMean) ** 2, $acf1)) / count($acf1));
echo sprintf("ACF(1) продукта соседних остатков: mean=%.6f sd=%.6f (положит. среднее = незатухающая автокорреляция)\n", $acfMean, $acfSd);

// --- перестановочный null: перемешиваем res внутри (comm, sp) по t ---
$nullAcf = [];
mt_srand(42000);
for ($perm = 0; $perm < 200; $perm++) {
    $shuffled = [];
    foreach ($communities as $label => $mat) {
        foreach ($ev3order as $c => $sp) {
            $pool = [];
            foreach ($records as $rec) { if ($rec['comm'] === $label && $rec['sp'] === $sp) { $pool[] = $rec['res']; } }
            shuffle($pool);
            $shuffled[$label][$sp] = $pool;
        }
    }
    $prod = [];
    foreach ($shuffled as $label => $bySp) {
        foreach ($bySp as $sp => $rr) {
            for ($t = 0; $t < 6; $t++) { $prod[] = $rr[$t] * $rr[$t + 1]; }
        }
    }
    $nullAcf[] = array_sum($prod) / count($prod);
}
sort($nullAcf);
$zIdx = 0;
foreach ($nullAcf as $i => $v) { if ($v <= $acfMean) { $zIdx = $i + 1; } }
$zAcf = ($acfMean - array_sum($nullAcf) / count($nullAcf)) / (max(1e-12, sqrt(array_sum(array_map(fn ($v) => ($v - array_sum($nullAcf) / count($nullAcf)) ** 2, $nullAcf)) / count($nullAcf))));
printf("null ACF: медиана=%.6f, 95%%=%.6f | наш=%.6f | z=%.2f, перц.=%d\n", $nullAcf[100], $nullAcf[189], $acfMean, $zAcf, $zIdx);

// --- CCF: корреляция остатка с кандидатами-фичами ---
$ccfFeatures = [
    'x_i' => array_column($records, 'x_i'),
    'x_sum_other' => array_column($records, 'x_sum_other'),
    'x_top1' => array_column($records, 'x_top1'),
    'richness' => array_column($records, 'richness'),
    't' => array_column($records, 't'),
];
// добавим x_i*x_j-термы для топ-пар? пока базовые
$ccfPearson = function (array $a, array $b): float {
    $n = count($a);
    $ma = array_sum($a) / $n; $mb = array_sum($b) / $n;
    $cov = 0.0; $va = 0.0; $vb = 0.0;
    for ($i = 0; $i < $n; $i++) { $cov += ($a[$i] - $ma) * ($b[$i] - $mb); $va += ($a[$i] - $ma) ** 2; $vb += ($b[$i] - $mb) ** 2; }
    return ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0;
};
echo "\nCCF(остаток, кандидат):\n";
foreach ($ccfFeatures as $name => $col) {
    $r = $ccfPearson($y, $col);
    // перестановочный null z
    $nulls = [];
    mt_srand(43000 + (int) abs($r * 1e6));
    for ($perm = 0; $perm < 200; $perm++) {
        $sh = $y;
        shuffle($sh);
        $nulls[] = $ccfPearson($sh, $col);
    }
    $mN = array_sum($nulls) / 200;
    $sdN = sqrt(array_sum(array_map(fn ($v) => ($v - $mN) ** 2, $nulls)) / 200);
    $z = $sdN > 1e-12 ? ($r - $mN) / $sdN : 0.0;
    printf("  %-12s r=%+.3f z=%+.1f\n", $name, $r, $z);
}

// --- знаковая структура: систематические недо/пере-предсказания по видам ---
$bySp = [];
foreach ($records as $rec) { $bySp[$rec['sp']][] = $rec['res']; }
echo "\nзнаковая структура по видам (mean res, доля недо-предсказаний):\n";
foreach ($bySp as $sp => $rr) {
    $m = array_sum($rr) / count($rr);
    $fracUnder = count(array_filter($rr, fn ($v) => $v > 0)) / count($rr);
    printf("  %s: mean=%+.4f under=%.0f%% (n=%d)\n", $sp, $m, 100 * $fracUnder, count($rr));
}

// --- знаковая структура по композициям (dropout-специфика) ---
$byComm2 = [];
foreach ($records as $rec) { $byComm2[$rec['comm']][] = $rec['res']; }
echo "\nпо композициям (mean res):\n";
foreach ($byComm2 as $comm => $rr) {
    printf("  %-5s mean=%+.4f (n=%d)\n", $comm, array_sum($rr) / count($rr), count($rr));
}
