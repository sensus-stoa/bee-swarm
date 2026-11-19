<?php
declare(strict_types=1);

/**
 * B7-External уровень 2b: permutation null для прироста r(M1) над r(M0)
 * и для прироста после пуриновой поправки (если бы была).
 * Также: разложение Δ (наблюдаемое - M0-предсказание) — есть ли систематика
 * по богатости и по доминирующему виду.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);
if (!function_exists('median')) {
    function median(array $a): float { $a = array_values($a); sort($a); $n = count($a);
        return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }
}

$rows = []; $h = null;
foreach (openpyxl_load(__DIR__ . '/../Benchmarks/clark25/MasterDF_WellNumbers.csv') as $i => $r) {
    if ($i === 0) { $h = array_map(fn ($v) => trim((string) $v), $r); continue; }
    if (count($r) < 30 || trim((string) $r[1]) === '') { continue; }
    $rows[] = $r;
}
$idx = fn (string $n) => array_search($n, $h, true);
$iBut = $idx('Butyrate');
$SPECIES = ['ER','FP','AC','HB','CC','RI','DP','BH','CA','PC','DL','CG','BF','EL','CH','BO','BT','BU','BV','BC','BY','PJ','DF','BL','BP','BA'];
$agg = [];
foreach ($rows as $r) {
    $t = trim((string) $r[1]);
    $fr = [];
    foreach ($SPECIES as $s) { $v = $r[$idx($s . ' Fraction')] ?? 0; $fr[$s] = is_numeric($v) ? (float) $v : 0; }
    $fsum = array_sum($fr);
    if ($fsum <= 0.5) { continue; }
    $agg[$t][] = ['but' => is_numeric($r[$iBut]) ? (float) $r[$iBut] : null, 'frac' => $fr];
}
$mean = [];
foreach ($agg as $t => $rs) {
    $bs = array_filter(array_column($rs, 'but'));
    if (!$bs) { continue; }
    $fr = [];
    foreach ($SPECIES as $s) {
        $vals = array_column($rs, 'frac');
        $fr[$s] = array_sum(array_column($vals, $s)) / count($rs);
    }
    $mean[$t] = ['But' => array_sum($bs) / count($bs), 'frac' => $fr,
                 'members' => explode('-', $t), 'rich' => count(explode('-', $t))];
}
$beta = [];
foreach ($mean as $t => $m) { if ($m['rich'] === 1) { $beta[$m['members'][0]] = $m['But']; } }
$inter = [];
foreach ($mean as $t => $m) {
    if ($m['rich'] !== 2) { continue; }
    [$a, $b] = $m['members'];
    if (!isset($beta[$a], $beta[$b])) { continue; }
    $fs = $m['frac'][$a] + $m['frac'][$b];
    $inter["{$a}|{$b}"] = ['val' => $m['But'] - $beta[$a] - $beta[$b],
                           'fprod' => $fs > 0 ? ($m['frac'][$a] / $fs) * ($m['frac'][$b] / $fs) : 0.25];
}

$test = array_filter($mean, fn ($m) => $m['rich'] >= 3);
$data = [];
foreach ($test as $t => $m) {
    $fsum = array_sum(array_map(fn ($s) => $m['frac'][$s], $m['members']));
    $fn = [];
    foreach ($m['members'] as $s) { $fn[$s] = $m['frac'][$s] / max($fsum, 1e-9); }
    $pred0 = 0.0;
    foreach ($m['members'] as $s) { $pred0 += $fn[$s] * ($beta[$s] ?? 0.0); }
    $it = 0.0; $nk = 0; $np = 0;
    for ($i = 0; $i < count($m['members']); $i++) {
        for ($j = $i + 1; $j < count($m['members']); $j++) {
            $a = $m['members'][$i]; $b = $m['members'][$j];
            $np++;
            $key = "{$a}|{$b}";
            if (isset($inter[$key])) {
                $it += $inter[$key]['val'] * (($fn[$a] * $fn[$b]) / max($inter[$key]['fprod'], 0.01));
                $nk++;
            }
        }
    }
    $data[] = ['t' => $t, 'y' => $m['But'], 'p0' => $pred0, 'p1' => $pred0 + $it,
               'rich' => $m['rich'], 'cover' => $nk / $np];
}
$pearson = function (array $x, array $y): float {
    $n = count($x);
    $mx = array_sum($x) / $n; $my = array_sum($y) / $n;
    $sxy = 0; $sxx = 0; $syy = 0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($x[$i]-$mx)*($y[$i]-$my); $sxx += ($x[$i]-$mx)**2; $syy += ($y[$i]-$my)**2; }
    return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : NAN;
};
$y = array_column($data, 'y');
$p0 = array_column($data, 'p0');
$p1 = array_column($data, 'p1');
$r0 = $pearson($y, $p0);
$r1 = $pearson($y, $p1);
echo sprintf("реально: r(M0)=%.4f  r(M1)=%.4f  прирост=%.4f\n", $r0, $r1, $r1 - $r0);

// permutation: перемешиваем M1-поправки между сообществами (тот же набор поправок, случайная привязка)
mt_srand(2024);
$diffs = array_map(fn ($d) => $d['p1'] - $d['p0'], $data);
$count = 0; $N = 2000;
$vals = array_values($diffs);
foreach ($data as $i => $d) { $data[$i]['diff'] = $diffs[$i]; }
for ($p = 0; $p < $N; $p++) {
    $sh = $vals; shuffle($sh);
    $p1s = [];
    foreach ($data as $i => $d) { $p1s[] = $d['p0'] + $sh[$i]; }
    $r = $pearson($y, $p1s);
    if ($r >= $r1) { $count++; }
}
echo "permutation (прирост r(M1)): p = ", $count / $N, "\n";

// разложение Δ по richness: где парный член помогает/вредит
echo "\n=== Δ=y-p0 (остаток после M0): систематика по richness ===\n";
$byRich = [];
foreach ($data as $d) { $byRich[$d['rich']][] = $d; }
foreach ($byRich as $rich => $ds) {
    $delta = array_map(fn ($d) => $d['y'] - $d['p0'], $ds);
    $impr = array_map(fn ($d) => (abs($d['y'] - $d['p1']) < abs($d['y'] - $d['p0'])) ? 1 : 0, $ds);
    printf("  rich=%2d n=%3d  медиана Δ=%+.1f  M1 улучшает в %.0f%% случаев\n", $rich, count($ds),
        median($delta), 100 * array_sum($impr) / count($ds));
}
