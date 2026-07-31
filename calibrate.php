<?php
require __DIR__ . '/vendor/autoload.php';

$lines = file(__DIR__ . '/data/metrics.jsonl');
$data = [];
foreach ($lines as $l) {
    $r = json_decode(trim($l), true);
    if ($r && isset($r["sleep"]) && isset($r["energy"]) && $r["sleep"] !== null && $r["energy"] !== null) {
        $data[] = [(float)$r["sleep"], (float)$r["energy"]];
    }
}
$X = array_map(fn($r) => [$r[0]], $data);
$y = array_column($data, 1);
$grammar = new BeeSwarm\Core\Grammar();
$N = 20; $minCv = 1.0;
for ($i = 0; $i < $N; $i++) {
    $s = $y; shuffle($s);
    $r = BeeSwarm\Core\Search::find($X, $s, $grammar, 2);
    if ($r && $r["cv"] < $minCv) $minCv = $r["cv"];
}
$real = BeeSwarm\Core\Search::find($X, $y, $grammar, 2);
echo "rows=" . count($data) . " trials=$N\n";
echo "min CV (null): " . round($minCv, 4) . "\n";
echo "real CV: " . round($real["cv"] ?? 99, 4) . " → " . (($real["cv"] ?? 1) < $minCv ? "★ ОТКРЫТИЕ" : "шум") . "\n";
