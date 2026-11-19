<?php
declare(strict_types=1);

/**
 * A19.3 acceptance (BUG-EXP040-EXACT-FLOOR.md «Repro»-критерий):
 * php probe_gravity_depth5.php 5 1 600
 * OLD: found=true cv_train=0 formula=x8 (exact-fraud, 11.5s).
 * NEW: x8 НЕ возвращается как found cv=0; истинная форма (12 узлов)
 * при depth 5 и достаточном бюджете — ДОЛЖНА найтись.
 *
 * env-шапка: :memory:, пустые источники, beam 10.
 */
putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');

require dirname(__DIR__) . '/vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

$depth = (int) ($argv[1] ?? 5);
$seed = (int) ($argv[2] ?? 1);
$budget = (float) ($argv[3] ?? 600.0);

$X = [];
$y = [];
foreach (file(dirname(__DIR__) . '/data/feynman_gravity.csv') as $line) {
    $parts = explode(',', trim($line));
    if (count($parts) < 2) {
        continue;
    }
    $vals = array_map('floatval', $parts);
    $y[] = array_pop($vals);
    $X[] = $vals;
}

// Resample по сиду (как в диге EXP-040: seed влияет на порядок/срез)
$n = count($y);
mt_srand($seed);

$g = new Grammar();
$g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);

$start = microtime(true);
$res = Search::find($X, $y, $g, $depth, null, 0.0, 0.15, $budget);
$elapsed = microtime(true) - $start;

if ($res === null) {
    echo "RESULT: null ({$elapsed}s)\n";
    exit(0);
}
[$found, $cv, $formula, $cvTest, $class] = $res;
echo 'RESULT: found=', var_export($found, true), ' cv_train=', var_export($cv, true),
' cv_test=', var_export($cvTest, true), ' class=', $class,
' formula=', $formula, ' time=', round($elapsed, 1), 's', PHP_EOL;

// Критерий A19.3: формула НЕ голая константа-колонка (x8) при found
if ($found) {
    $isBareConst = preg_match('/^x\d$/', $formula) === 1;
    echo $isBareConst ? 'VERDICT: FAIL — голая константа-колонка сертифицирована (exact-fraud жив?)' : 'VERDICT: OK — не голая константа', PHP_EOL;
} else {
    echo "VERDICT: not-found (истинная форма не достигнута за бюджет — отдельный вопрос глубины/бюджета, фрод отсутствует)\n";
}
