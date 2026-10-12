<?php
declare(strict_types=1);

/**
 * Проба A19-gate на heldout-пути (testRatio=0.2, как DiscoveryEngine):
 * точные законы y=x0+x1, y=x0*x1, шумная линейка, метрик-домен.
 */

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');

require dirname(__DIR__) . '/vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

$seed = 777;
$lcg = function () use (&$seed): float {
    $seed = ($seed * 1103515245 + 12345) % 2147483648;

    return $seed / 2147483648;
};

$cases = [
    'add-exact' => fn (array $x): float => $x[0] + $x[1],
    'mul-exact' => fn (array $x): float => $x[0] * $x[1],
    'sum3-exact' => fn (array $x): float => $x[0] + $x[1] + $x[2],
    'linear-noisy' => null, // отдельно
];

foreach ($cases as $name => $f) {
    $X = [];
    $y = [];
    for ($i = 0; $i < 30; $i++) {
        $x = [1.0 + floor($lcg() * 10), 1.0 + floor($lcg() * 10), 1.0 + floor($lcg() * 10)];
        $X[] = $x;
        $y[] = $f($x);
    }
    $g = Grammar::fromOps(Grammar::baseOpNames());
    [$found, $cv, $formula, $cvTest, $class, $diag] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);
    echo $name, ': found=', var_export($found, true), ' cv=', var_export($cv, true),
        ' cvTest=', var_export($cvTest, true), ' formula=', $formula, ' diag=', var_export($diag, true), PHP_EOL;
}

// Шумная линейная задача (должна остаться found: модель существенно лучше среднего)
$X = [];
$y = [];
for ($i = 0; $i < 30; $i++) {
    $x = [1.0 + $lcg() * 10];
    $X[] = $x;
    $y[] = 2.0 * $x[0] + 5.0 + ($lcg() - 0.5) * 0.2;
}
$g = Grammar::fromOps(Grammar::baseOpNames());
[$found, $cv, $formula, $cvTest, $class, $diag] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);
echo 'linear-noisy: found=', var_export($found, true), ' cv=', var_export($cv, true),
    ' cvTest=', var_export($cvTest, true), ' formula=', $formula, ' diag=', var_export($diag, true), PHP_EOL;
