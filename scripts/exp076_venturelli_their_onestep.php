<?php
declare(strict_types=1);

/**
 * B5.6-контрол: их T3 в НАШЕЙ мере (one-step приращений EV3).
 * SBML T3: y_i = u_i + sum_j a_ij*x_j (линейно). corr + R2 на EV3-приращениях.
 * Сравнение с нашим M0/M1 — честное разделение «форма не переносится» vs «наш фит плох».
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$SBML = '/tmp/ven_s006/twelveSpeciesT3.xml';
$xml = file_get_contents($SBML);
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

$VEN = __DIR__ . '/../Benchmarks/venturelli';
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

$predTheir = function (string $sp, array $xRow) use ($params): float {
    $s = $params['u' . $sp];
    foreach ($xRow as $j => $xj) { $s += $params['a' . $sp . $j] * $xj; }
    return $s;
};

$pairs = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        $xRow = [];
        foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
        foreach ($ev3order as $c => $sp) {
            $xi = $mat[$t][$c]; $xin = $mat[$t + 1][$c] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $pairs[] = [($xin - $xi) / $xi, $predTheir($sp, $xRow), $label, $sp];
        }
    }
}
$obs = array_column($pairs, 0);
$pred = array_column($pairs, 1);
$n = count($obs);
$mo = array_sum($obs) / $n; $mp = array_sum($pred) / $n;
$cov = 0.0; $va = 0.0; $vb = 0.0;
$res = 0.0; $tot = 0.0;
for ($i = 0; $i < $n; $i++) {
    $cov += ($obs[$i] - $mo) * ($pred[$i] - $mp);
    $va += ($obs[$i] - $mo) ** 2; $vb += ($pred[$i] - $mp) ** 2;
    $res += ($obs[$i] - $pred[$i]) ** 2; $tot += ($obs[$i] - $mo) ** 2;
}
$r = $cov / sqrt($va * $vb);
echo "ИХ T3 в one-step мере EV3 (n=$n): corr=" . round($r, 3) . ' R2=' . round(1 - $res / $tot, 3) . "\n";
echo '(наш M0: corr=0.16, R2=-12.2; публикационные 0.85 — это мера УРОВНЕЙ multi-step)' . "\n";

// распечатать масштаб предсказаний их T3 vs obs
$prSort = $pred;
sort($prSort);
echo 'их предсказания y: медиана=' . round($prSort[(int) ($n / 2)], 3) . ' 5%=' . round($prSort[(int) (0.05 * $n)], 3) . ' 95%=' . round($prSort[(int) (0.95 * $n)], 3) . "\n";
$obSort = $obs; sort($obSort);
echo 'obs y:            медиана=' . round($obSort[(int) ($n / 2)], 3) . ' 5%=' . round($obSort[(int) (0.05 * $n)], 3) . ' 95%=' . round($obSort[(int) (0.95 * $n)], 3) . "\n";

// --- сравнение масштабов приращений EV1 vs EV3 (шум округления или реальность?) ---
$yEv1 = [];
$rows = openpyxl_load($VEN . '/ev1_pairs.csv');
foreach ($rows as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t]; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi < 0.01) { continue; }
        $yEv1[] = ($xin - $xi) / $xi;
    }
}
$ab1 = array_map('abs', $yEv1);
$ab3 = array_map('abs', $obs);
$med = function (array $a): float { sort($a); return $a[(int) (count($a) / 2)]; };
$p95 = function (array $a): float { sort($a); return $a[(int) (0.95 * count($a))]; };
echo "\nмасштаб приращений: EV1 медиана|y|=" . round($med($ab1), 3) . " p95=" . round($p95($ab1), 3)
    . " | EV3 медиана|y|=" . round($med($ab3), 3) . " p95=" . round($p95($ab3), 3) . "\n";
