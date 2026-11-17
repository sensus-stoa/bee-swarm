<?php
declare(strict_types=1);
require_once __DIR__ . '/exp045/cert_utils.php';
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$db = BeeSwarm\Infra\Database::get();

function genN2(callable $law, int $seed, int $n, float $sigma, int $noiseSeed): array {
    $s = $seed;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = function (float $a, float $b) use ($lcg): float { return $a + $lcg() * ($b - $a); };
    $ns = $noiseSeed + 777;
    $nrand = function () use (&$ns): float { $ns = ($ns * 1103515245 + 12345) & 0x7FFFFFFF; return $ns / 0x7FFFFFFF; };
    $X = []; $y = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $X[] = $row;
        $noise = $sigma > 0 ? $sigma * sqrt(-2 * log(max(1e-12, $nrand()))) * cos(2 * M_PI * $nrand()) : 0.0;
        $y[] = $law($row) + $noise;
    }
    return [$X, $y];
}
$comp = fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5];
foreach ([0.0, 0.05] as $sg) {
    [$X, $y] = genN2($comp, 9401, 200, $sg, 401);
    $flat = 'x0×x1+x3×x4²+x5';                 // no parens
    $pared = '(x0×x1)+(x3×x4²)+(x5)';          // parenthesized per term
    $vFlat = cert_v2($flat, $X, $y, 401);
    $vPar  = cert_v2($pared, $X, $y, 401);
    echo "sigma=$sg cert_v2(FLAT)   cvs=" . json_encode($vFlat['cvs']) . " pass=" . json_encode($vFlat['pass']) . "\n";
    echo "sigma=$sg cert_v2(PARED)  cvs=" . json_encode(array_map(fn ($v) => $v === null ? null : round($v, 6), $vPar['cvs'])) . " pass=" . json_encode($vPar['pass']) . "\n";
    // single-term island positive control too
    $vIsland = cert_v2('(x0×x1)', $X, $y, 401);
    echo "sigma=$sg cert_v2(island x0×x1) pass=" . json_encode($vIsland['pass']) . " (expected FAIL: term alone != composite y)\n";
}
echo "PREC_DONE\n";
