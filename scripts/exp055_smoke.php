<?php
declare(strict_types=1);
// EXP-055 mechanics smoke (pre-build verification): can a probe write rungs
// (failed-cert 2-factor instantiations) into the C_deep road via reflection
// on Search::writeTrailMarks, and does a subsequent warm-chain ladder READ
// them (USE_MARKS telemетрия)? No engine changes.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');

// C_deep composite: (x0*x1)*(x2*x3) + x4, width 5, sigma 0
$seed = 401; $n = 200;
$s = 9401; $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
$u = fn (float $a, float $b): float => $a + $lcg() * ($b - $a);
$X = []; $y = [];
for ($i = 0; $i < $n; $i++) {
    $r = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
    $y[] = ($r[0] * $r[1]) * ($r[2] * $r[3]) + $r[4];
    $X[] = $r;
}
$labels = array_map(fn (int $i): string => "c{$i}", range(0, 4));

// Rungs (label-space): true organs of the depth-2 sub-structure
$rungs = ['(c0×c1)', '(c2×c3)'];
$plaus = array_map(fn (string $f): array => ['name' => $f, 'corr' => 0.0], $rungs);

// WRITE via reflection on private static
$rm = new ReflectionMethod(Search::class, 'writeTrailMarks');
$rm->setAccessible(true);
$rm->invoke(null, $labels, $plaus, INF, INF, [], [], $X, $y);

$st = (function () { return ['written' => Search::$lastTrailWritten ?? null, 'input' => Search::$lastTrailWriterInput ?? null]; })();
echo "WRITE: ", json_encode($st), "\n";

// READ: one warm-chain ladder on C_deep — do USE_MARKS picks return the rungs?
$ladder = ['id' => 'route_h55_1', 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
    'actions' => [
        ['op' => 'USE_MARKS', 'k' => 5],
        ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ['op' => 'USE_MARKS', 'k' => 5],
        ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
    ]];
$res = RouteExecutor::run($ladder, $X, $y, $labels, 12.0, 0.10, [], 1);
foreach ($res['records'] as $ri => $rec) {
    if (($rec['op'] ?? '') !== 'USE_MARKS') continue;
    $marks = $rec['marks'] ?? $rec['marks_read'] ?? null;
    echo "rec$ri USE_MARKS: ", json_encode($marks === null ? array_keys($rec) : $marks), "\n";
}
echo "SMOKE_DONE found=", json_encode($res['found']), " stop=", json_encode($res['stop'] ?? null), "\n";
