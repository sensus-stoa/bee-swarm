<?php
// S2.3-IGNORANCE: verify_2_3 CLI — thin wrapper (logic in IgnoranceAccuracyVerify).
// Env-header: факты в отчёте, не предположения. Гвард персистентной БД —
// внутри класса (V23_VERIFY_OK=1 для opt-in).
require __DIR__ . '/../vendor/autoload.php';

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=10');
putenv('SEARCH_DEPTH=2');

$report = \BeeSwarm\Hive\IgnoranceAccuracyVerify::report();

echo "=== verify_2_3 (§3.3 self-model of ignorance) ===\n";
echo 'PASS: ' . ($report['pass'] ? 'YES' : 'NO') . "\n";
echo "SCORE: {$report['score']}/10\n";
echo "KINDS: {$report['kinds']}\n";
foreach ($report['cases'] as $c) {
    echo sprintf(
        "%s truth=%-8s diag=%-8s %s depth_val=%s\n",
        $c['id'],
        $c['truth'],
        (string) $c['diagnosis'],
        $c['ok'] ? 'OK ' : 'MISS',
        $c['depth_validation']
    );
}
foreach ($report['pending'] as $p) {
    echo "PENDING: {$p}\n";
}
echo 'ENV: ' . json_encode($report['env']) . "\n";

exit($report['pass'] ? 0 : 1);
