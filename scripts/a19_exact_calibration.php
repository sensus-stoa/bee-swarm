<?php
declare(strict_types=1);

/**
 * A19.4 — калибровка scale-aware exact tolerance (BUG-EXP040-EXACT-FLOOR).
 *
 * Grid: 6 синтетических законов × 6 масштабов y (1e-9..1e6) × 3 режима
 * (true-exact / near-exact δ=1e-15 / константа). Ожидания:
 *   true-exact rate   = 1.0 (фикс не убивает exact-ветку ни на одном масштабе)
 *   near-exact rate   = 1.0 (float-шум вычисления остаётся «exact»)
 *   constant-exact rate = 0.0 (болезнь EXP-040 не воспроизводится ни на одном масштабе)
 *
 * Env-шапка (канон 31.08): :memory:, пустые источники, beam 10.
 */
putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');

require dirname(__DIR__) . '/vendor/autoload.php';

use BeeSwarm\Core\Search;

$laws = [
    'dot' => fn (array $x): float => $x[0] * $x[3] + $x[1] * $x[4] + $x[2] * $x[5],
    'kinetic' => fn (array $x): float => 0.5 * ($x[0] * $x[0] + $x[1] * $x[1] + $x[2] * $x[2]),
    'quad' => fn (array $x): float => $x[0] * $x[1] * $x[2],
    'ratio' => fn (array $x): float => $x[0] / $x[1],
    'sqrtmul' => fn (array $x): float => sqrt($x[0]) * $x[1],
    'affine' => fn (array $x): float => 2.0 * $x[0] + 1.0,
];
$scales = [1.0e-9, 1.0e-6, 1.0e-3, 1.0, 1.0e3, 1.0e6];

// Детерминированный LCG (не mt_rand — воспроизводимость прогона).
$seed = 4242;
$lcg = function () use (&$seed): float {
    $seed = ($seed * 1103515245 + 12345) % 2147483648;

    return $seed / 2147483648;
};

$rows = [];
foreach ($laws as $lname => $f) {
    foreach ($scales as $a) {
        $X = [];
        $y = [];
        for ($i = 0; $i < 12; $i++) {
            $x = [1.0 + $lcg(), 1.0 + $lcg(), 1.0 + $lcg(), 1.0 + $lcg(), 1.0 + $lcg(), 1.0 + $lcg()];
            $X[] = $x;
            $y[] = $a * $f($x);
        }
        // true-exact: pred = y (после float-пересчёта той же формулы)
        $trueVec = [];
        // near-exact: pred = y·(1+1e-15) — предел float-точности умножения
        $nearVec = [];
        // константа: pred = a (масштаб цели) — болезнь x8/G
        $constVec = array_fill(0, 12, $a);
        foreach ($y as $i => $yi) {
            $trueVec[] = $a * $f($X[$i]);
            $nearVec[] = $yi * (1.0 + 1.0e-15);
        }
        $rows[] = [
            'law' => $lname,
            'scale' => $a,
            'kind' => 'true',
            'cv' => Search::cv($trueVec, $y),
        ];
        $rows[] = [
            'law' => $lname,
            'scale' => $a,
            'kind' => 'near',
            'cv' => Search::cv($nearVec, $y),
        ];
        $rows[] = [
            'law' => $lname,
            'scale' => $a,
            'kind' => 'const',
            'cv' => Search::cv($constVec, $y),
        ];
    }
}

$agg = [
    'true' => [0, 0],
    'near' => [0, 0],
    'const' => [0, 0],
];
$badTrue = [];
$badConst = [];
foreach ($rows as $r) {
    $isExact = ($r['cv'] === 0.0);
    $agg[$r['kind']][1]++;
    if ($isExact) {
        $agg[$r['kind']][0]++;
    }
    if ($r['kind'] === 'true' && ! $isExact) {
        $badTrue[] = "{$r['law']}@{$r['scale']} cv={$r['cv']}";
    }
    if ($r['kind'] === 'const' && $isExact) {
        $badConst[] = "{$r['law']}@{$r['scale']}";
    }
}

echo 'ENV: SWARM_DB_PATH=', getenv('SWARM_DB_PATH'), ' FORAGER_SOURCES=', getenv('FORAGER_SOURCES'), ' SEARCH_BEAM_K=', getenv('SEARCH_BEAM_K'), PHP_EOL;
printf("true-exact rate:   %d/%d\n", $agg['true'][0], $agg['true'][1]);
printf("near-exact rate:   %d/%d\n", $agg['near'][0], $agg['near'][1]);
printf("constant-exact:    %d/%d (ОЖИДАЕТСЯ 0)\n", $agg['const'][0], $agg['const'][1]);
if ($badTrue !== []) {
    echo 'FALSE-REJECTS: ', implode(', ', array_slice($badTrue, 0, 10)), PHP_EOL;
}
if ($badConst !== []) {
    echo 'FALSE-EXACTS: ', implode(', ', array_slice($badConst, 0, 10)), PHP_EOL;
}
$ok = $agg['true'][0] === $agg['true'][1] && $agg['near'][0] === $agg['near'][1] && $agg['const'][0] === 0;
echo $ok ? 'A19.4 CALIBRATION: PASS' : 'A19.4 CALIBRATION: FAIL', PHP_EOL;
