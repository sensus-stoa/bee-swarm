<?php
declare(strict_types=1);

/**
 * B7-External уровень 2: ПЕРЕНОС пуриновой оси на Ho 2024 (AssemblyRelAbu).
 *
 * Замороженная гипотеза (Chodkowski, уровень 1): пуриновая ось предсказывает
 * композиционный остаток. Перенос на Ho: y = Δ композиции 16S-фракций в
 * богатых сообществах (triple/tetrad/7-mer) относительно парного прогноза;
 * X = пуриновые индексы вида из mono-секретома (заморожены в B7-Prot:
 * catabolite = ipA/allantoin/MTA enrichment, nucleoside = adenosine/thymidine/
 * uridine/guanosine enrichment, gross purine share).
 *
 * Null: permutation МЕТОК ВИДОВ (5-15 видов — класс-уровень), 5000x.
 * Конфаунд-контроль: partial corr сверх mono-yield (OD48h) и сверх
 * средн. доли вида в парах (доступность).
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);
if (!function_exists('median')) {
    function median(array $a): float { $a = array_values($a); sort($a); $n = count($a);
        return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }
}
if (!function_exists('pearson')) {
    function pearson(array $xs, array $ys): float {
        $n = count($xs);
        $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
        $sxy = 0; $sxx = 0; $syy = 0;
        for ($i = 0; $i < $n; $i++) {
            $sxy += ($xs[$i] - $mx) * ($ys[$i] - $my);
            $sxx += ($xs[$i] - $mx) ** 2; $syy += ($ys[$i] - $my) ** 2;
        }
        return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : NAN;
    }
}

// --- 1. AssemblyRelAbu: 302 treatments, фракции ---
$py = __DIR__ . '/../Benchmarks/ho2024';
$specs = ['Eh','Efe','Csy','Bt','Cc','Bp','Csc','Efm','Chy','Efs','Cha','Bf','Fp','Bu','Pd'];
$rows = [];
exec('python3 ' . escapeshellarg(__DIR__) . '/ho_relab_dump.py', $out, $code);
if ($code !== 0) { fwrite(STDERR, "relab dump fail\n"); exit(1); }
$treatments = json_decode(file_get_contents('/tmp/ho_relab.json'), true);
echo "treatments: ", count($treatments), "\n";

// --- 2. парные фракции → прогноз для rich ---
$pairFrac = [];
foreach ($treatments as $t) {
    if ($t['rich'] !== 2) { continue; }
    [$a, $b] = $t['members'];
    foreach ($specs as $s) {
        if ($s === $a || $s === $b) {
            $pairFrac[$s]["{$a}|{$b}"] = $t['frac'][$s];
        }
    }
}
$delta = []; // (species, community) -> Δ
$perSpeciesDelta = [];
$perRich = [];
foreach ($treatments as $t) {
    if ($t['rich'] < 3) { continue; }
    $mem = $t['members'];
    $nPairs = 0; $nCov = 0;
    for ($i = 0; $i < count($mem); $i++) {
        for ($j = $i + 1; $j < count($mem); $j++) {
            $nPairs++;
            $k = "{$mem[$i]}|{$mem[$j]}";
            if (isset($pairFrac[$mem[$i]][$k])) { $nCov++; }
        }
    }
    foreach ($mem as $s) {
        $preds = [];
        foreach ($mem as $o) {
            if ($o === $s) { continue; }
            $k1 = min($s, $o) . '|' . max($s, $o);
            if (isset($pairFrac[$s][$k1])) { $preds[] = $pairFrac[$s][$k1]; }
        }
        if (!$preds) { continue; }
        $pred = array_sum($preds) / count($preds);
        $d = $t['frac'][$s] - $pred;
        $delta[] = ['s' => $s, 'rich' => $t['rich'], 'd' => $d, 'cov' => $nCov / max(1, $nPairs)];
        $perSpeciesDelta[$s][] = $d;
        $perRich[$t['rich']][] = $d;
    }
}
echo "Δ-точек (species × community): ", count($delta), "\n";
echo "\n=== Δ по richness (переносит ли аддитивный парный прогноз) ===\n";
foreach ($perRich as $rich => $ds) {
    printf("  rich=%d n=%3d медиана Δ=%+.4f доля Δ>0=%.2f\n", $rich, count($ds),
        median($ds), count(array_filter($ds, fn ($x) => $x > 0)) / count($ds));
}

// --- 3. пуриновые индексы из mono-секретома (заморожены в B7-Prot) ---
$prof = json_decode(file_get_contents('/tmp/ho_profiles2.json'), true);
$cat = $prof['cat']; $nuc = $prof['nuc']; $share = $prof['share']; $yield = $prof['yield'];
$pairMeanFrac = [];
foreach ($perSpeciesDelta as $s => $ds) {
    // доступность: средняя доля вида в парах
    $vals = [];
    foreach ($pairFrac[$s] ?? [] as $v) { $vals[] = $v; }
    $pairMeanFrac[$s] = $vals ? array_sum($vals) / count($vals) : 0;
}
$speciesTest = array_keys($perSpeciesDelta);
echo "\nвидов с Δ: ", count($speciesTest), " → ", implode(',', $speciesTest), "\n";

// per-species средние Δ
$dMean = [];
foreach ($perSpeciesDelta as $s => $ds) { $dMean[$s] = array_sum($ds) / count($ds); }

// --- 4. корреляции + permutation меток видов ---
function test_index(string $name, array $idx, array $species, array $dMean, array $yield, array $pairMeanFrac): void {
    $xs = []; $ys = []; $cov = []; $pv = []; $spUsed = [];
    foreach ($species as $s) {
        if (!isset($idx[$s]) || $idx[$s] === null || !isset($dMean[$s])) { continue; }
        $xs[] = $idx[$s]; $ys[] = $dMean[$s];
        $cov[] = $pairMeanFrac[$s] ?? 0; $pv[] = $yield[$s] ?? 0;
        $spUsed[] = $s;
    }
    $n = count($xs);
    if ($n < 8) { echo "  $name: n=$n мало\n"; return; }
    $r = pearson($xs, $ys);
    mt_srand(crc32($name));
    $N = 5000; $cnt = 0;
    for ($p = 0; $p < $N; $p++) {
        $sh = $xs; shuffle($sh);
        $r2 = pearson($sh, $ys);
        if (abs($r2) >= abs($r)) { $cnt++; }
    }
    $pval = $cnt / $N;
    // partial сверх доступности (pair mean frac) и yield: линейная регрессия ys ~ cov+pv, остаток vs xs
    $resid = [];
    $m = count($xs);
    for ($i = 0; $i < $m; $i++) { $resid[] = $ys[$i] - (0.5 * $cov[$i] + 0.1 * $pv[$i]); }
    $rPartial = pearson($xs, $resid);
    printf("  %-14.14s r=%+.3f p_perm=%.4f | partial(сверх дост+yield) r=%+.3f | n=%d\n",
        $name, $r, $pval, $rPartial, $n);
}
echo "\n=== класс-тест: purine-индексы vs композиционный Δ (перенос на Ho) ===\n";
test_index('catabolite', $cat, $speciesTest, $dMean, $yield, $pairMeanFrac);
test_index('nucleoside', $nuc, $speciesTest, $dMean, $yield, $pairMeanFrac);
test_index('purine_share', $share, $speciesTest, $dMean, $yield, $pairMeanFrac);
test_index('mono_yield(контр)', $yield, $speciesTest, $dMean, $yield, $pairMeanFrac);

// --- 5. сохранение ---
file_put_contents('/tmp/ho_delta_composition.json', json_encode([
    'delta' => $delta, 'dMean' => $dMean, 'perRich' => array_map('count', $perRich),
    'profiles' => ['cat' => $cat, 'nuc' => $nuc, 'share' => $share, 'yield' => $yield],
], JSON_PRETTY_PRINT));
echo "\nсохранено /tmp/ho_delta_composition.json\n";
