<?php
declare(strict_types=1);
// BOUNDARY PROBE (07.11): at what sigma does the TRUE form pass cert_v2?
// Ratio-CV gate (cert_x) vs MAE-CV (E50's numpy metric). Island + composite.
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
$islandA = fn (array $r): float => $r[0] * $r[1];
printf("%6s %5s | %s\n", 'sigma', 'seed', 'composite gate (true form)          | island A gate (true form)     | mae_cv_full');
foreach ([0.005, 0.01, 0.02, 0.05, 0.1, 0.15, 0.2, 0.3, 0.35, 0.4] as $sg) {
    foreach ([401, 402, 403] as $seed) {
        [$Xc, $yc] = genN2($comp, 9001 + $seed, 200, $sg, $seed);
        $vC = cert_v2('(x0×x1)+(x3×x4²)+(x5)', $Xc, $yc, $seed);
        $maxC = max(array_map(fn ($v) => $v ?? 99.0, $vC['cvs']));
        [$Xa, $ya] = genN2($islandA, 7000 + $seed, 200, $sg, $seed + 2000);
        $vA = cert_v2('(x0×x1)', $Xa, $ya, $seed);
        $maxA = max(array_map(fn ($v) => $v ?? 99.0, $vA['cvs']));
        // E50-style MAE-CV on full data for comparison
        $maeCv = 0.0;
        $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula('(x0×x1)+(x3×x4²)+(x5)', $Xc);
        $ae = 0.0; $ay = 0.0;
        foreach ($vals as $i => $p) { $ae += abs($p - $yc[$i]); $ay += abs($yc[$i]); }
        $maeCv = $ae / $ay;
        printf("%6.3f %5d | P:%d max_cv:%6.3f                    | P:%d max_cv:%6.3f             | %6.4f\n",
            $sg, $seed, $vC['pass'] ? 1 : 0, $maxC, $vA['pass'] ? 1 : 0, $maxA, $maeCv);
    }
}
echo "BOUNDARY_DONE\n";
