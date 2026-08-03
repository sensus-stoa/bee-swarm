<?php
/**
 * cross_domain_test.php
 * Кросс-доменный поиск через custom-операторы (не трогая BASE_OPS).
 * ./cross_domain_test.php
 */
require_once __DIR__ . '/vendor/autoload.php';

use BeeSwarm\Hive\DiscoveryEngine;
use BeeSwarm\Core\Grammar;

$engine = new DiscoveryEngine();
$base = Grammar::baseOpNames();

echo "═══ CROSS-DOMAIN INVARIANT SEARCH ═══\n";
echo "BASE: " . implode(', ', $base) . "\n";
echo "CUSTOM (already in applyCustom): sq, log2, pow2, pow3, inverse\n\n";

// Все операторы которые работают в applyCustom
$allOps = array_merge($base, ['add','sub','mul','div','abs','sq','log2','pow2','pow3','inverse']);

// ═══ ФИЗИКА: Kepler T² ∝ r³ ═══
echo "─── ФИЗИКА: Kepler T² ∝ r³ ───\n";
$X = []; $y = [];
for ($i = 0; $i < 25; $i++) {
    $r = 0.3 + $i * 0.6;
    $X[] = [$r];
    $y[] = pow($r, 1.5) + rand(-300, 300) / 100000;
}
$results = $engine->discover($X, $y, $allOps, 0.15, ['r']);
foreach ($results as $d) {
    if (!str_contains($d['atom'], 'R')) echo "  CV=" . round($d['cv'], 3) . " {$d['atom']}\n";
}

// ═══ БИОЛОГИЯ: Kleiber M ∝ m^0.75 ═══
echo "\n─── БИОЛОГИЯ: Kleiber M ∝ m^0.75 ───\n";
$X = []; $y = [];
for ($i = 0; $i < 25; $i++) {
    $m = 0.2 + $i * 300;
    $X[] = [$m];
    $y[] = pow($m, 0.75) + rand(-2000, 2000) / 100;
}
$results = $engine->discover($X, $y, $allOps, 0.15, ['m']);
foreach ($results as $d) {
    if (!str_contains($d['atom'], 'R')) echo "  CV=" . round($d['cv'], 3) . " {$d['atom']}\n";
}

// ═══ CS: Quicksort ∝ n×log(n) ═══
echo "\n─── CS: Quicksort ∝ n×log(n) ───\n";
$X = []; $y = [];
for ($i = 0; $i < 25; $i++) {
    $n = 10 + $i * 400;
    $X[] = [$n];
    $y[] = $n * log($n) + rand(-20000, 20000) / 100;
}
$results = $engine->discover($X, $y, $allOps, 0.20, ['n']);
foreach ($results as $d) {
    if (!str_contains($d['atom'], 'R')) echo "  CV=" . round($d['cv'], 3) . " {$d['atom']}\n";
}

// ═══ МАТЕМАТИКА: Prime gaps ∝ log(n) ═══
echo "\n─── МАТЕМАТИКА: Prime gaps ∝ log(n) ───\n";
$gaps = [1,2,2,4,2,4,2,4,6,2,6,4,2,4,6,6,2,6,4,2,6,4,6,8,4];
$X = []; $y = [];
for ($i = 0; $i < count($gaps); $i++) {
    $X[] = [(float)($i + 2)];
    $y[] = $gaps[$i] + rand(-50, 50) / 1000;
}
$results = $engine->discover($X, $y, $allOps, 0.25, ['n']);
foreach ($results as $d) {
    if (!str_contains($d['atom'], 'R')) echo "  CV=" . round($d['cv'], 3) . " {$d['atom']}\n";
}

echo "\n═══ CROSS-DOMAIN OPERATORS ═══\n";
echo "log2: log₂(x) — математика, CS\n";
echo "sq:   x²      — физика (T²∝r³ → r² появляется)\n";
echo "Эти операторы уже есть в applyCustom.\n";
echo "Пчёлы найдут их через NESTED (sq⁻¹=√, exp⁻¹=log, × самоприменить=^).\n";
