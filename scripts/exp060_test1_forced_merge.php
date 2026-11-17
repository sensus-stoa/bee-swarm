<?php
declare(strict_types=1);
// EXP-060 Test 1: forced merge on seed 404 (Class A - missing x5, x5 on the road).
// Question: does the forced-merge form certify on 404's OWN data (404-derived splits)?
// If yes: the form is representable/evaluable/certifiable - the search simply
// never reaches it (the owner's branch "search does not attempt the merge").
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\ExpressionEvaluator;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');

function genRowsT(int $seed, int $n): array {
    $s = $seed + 555;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = fn (float $a, float $b): float => $a + $lcg() * ($b - $a);
    $X = [];
    for ($i = 0; $i < $n; $i++) {
        $r = [];
        for ($c = 0; $c < 6; $c++) { $r[] = $u(-2, 2); }
        $X[] = $r;
    }
    return $X;
}
function lawT(array $r): float { return $r[0] * $r[2] * $r[2] + $r[3] * $r[4] + $r[5]; }

$forced = '((x5+(x3×x4))+(x0×x2²))';   // 404's partial + the missing x5 = the composite
foreach ([404, 409] as $seed) {
    $X = genRowsT($seed, 200);
    $y = array_map('lawT', $X);
    // the arithmetic check first
    $vals = ExpressionEvaluator::evaluateFormula($forced, $X);
    $maxAbsErr = 0.0;
    foreach ($y as $i => $yv) { $maxAbsErr = max($maxAbsErr, abs($yv - (float) $vals[$i])); }
    // the cert on THIS seed's own splits
    $noEval = null;
    $v2 = cert_v2($forced, $X, $y, $seed + 600, $noEval);
    echo 'seed=', $seed, ' max_abs_err=', var_export($maxAbsErr, true),
         ' cert_pass=', (($v2 !== null && $v2['pass']) ? 1 : 0),
         ' reason=', var_export($v2['reason'] ?? null, true), "\n";
}
