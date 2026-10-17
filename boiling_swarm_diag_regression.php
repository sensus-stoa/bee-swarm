<?php
/**
 * boiling_swarm_diag_regression.php — THE pre-registered diagnostic run.
 *
 * MECHANISM HYPOTHESIS: binary 0/1 labels starve CV→0 search (engine expects
 * continuous targets). PREDICTION (before running): on a continuous target
 * (film fraction, 60-s boxcar of y) discover() finds a formula with cv<0.35
 * on >=2/4 benches, and the skeleton transfers. If still NOISE on all 4 ->
 * features are genuinely weak, refusal is final. No threshold tuning either way.
 */
require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Hive\DiscoveryEngine;
use BeeSwarm\Core\Grammar;

$csvPath = __DIR__ . '/code/data_swarm/features_boiling.csv';
$rows = [];
$fh = fopen($csvPath, 'r'); $hdr = fgetcsv($fh);
while (($r = fgetcsv($fh)) !== false) $rows[] = array_combine($hdr, $r);
fclose($fh);

$feats = ['hf_ratio','mid_ratio','lf_ratio','env_skew','burst10','hf_p90p50'];
$byBench = [];
foreach ($rows as $r) {
    if ($r['excl'] == '1') continue;
    $x = []; foreach ($feats as $i => $f) $x[$i] = (float)$r[$f];
    $byBench[$r['bench']][] = ['t' => (float)$r['t'], 'x' => $x, 'y' => (float)$r['y']];
}
$labels = $feats;
$ops = array_merge(Grammar::baseOpNames(), ['add','sub','mul','div','abs','sq','log2','pow2','pow3','inverse']);
$eng = new DiscoveryEngine();
putenv('SEARCH_DEPTH=2'); putenv('SEARCH_DEPTH_MAX=4');

$results = [];
foreach ($byBench as $bk => $data) {
    usort($data, fn($a,$b) => $a['t'] <=> $b['t']);
    $n = count($data);
    // continuous target: 60-s centered boxcar of y (film fraction)
    $y = []; 
    foreach ($data as $j => $row) {
        $lo = $row['t'] - 30; $hi = $row['t'] + 30;
        $sum = 0; $cnt = 0;
        foreach ($data as $k => $r2) {
            if ($r2['t'] >= $lo && $r2['t'] <= $hi) { $sum += $r2['y']; $cnt++; }
        }
        $y[] = $cnt ? $sum / $cnt : 0.0;
    }
    $X = array_column($data, 'x');
    echo "─── $bk (n=$n, target=film fraction 60s boxcar) ───\n"; flush();
    $t0 = microtime(true);
    [$found, $cv, $formula, $cvTest, $class] = $eng->discover($X, $y, $ops, 0.35, $labels);
    echo "  found=" . var_export($found, true) . " cv=$cv cvTest=$cvTest class=$class (" . round(microtime(true)-$t0,1) . "s)\n";
    if ($formula !== null) echo "  FORMULA: $formula\n";
    $results[$bk] = ['found'=>$found,'cv'=>$cv,'formula'=>$formula,'cvTest'=>$cvTest,'class'=>$class,'n'=>$n];
    flush();
    file_put_contents("/tmp/diag_$bk.json", json_encode(['x'=>$X,'y'=>$y]));
}
file_put_contents(__DIR__ . '/code/data_swarm/diag_regression_results.json', json_encode($results, JSON_PRETTY_PRINT));
echo "saved diag_regression_results.json\n";
