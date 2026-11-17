<?php
declare(strict_types=1);
// Gate-only scan: cert_v2 of the TRUE island forms across all 15 grid seeds.
// Separates gate-side recall from search-side recall.
require_once __DIR__ . '/exp045/cert_utils.php';
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';

function genN3(callable $law, int $seed, int $n, float $sigma, int $noiseSeed): array {
    $s = $seed;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = function (float $a, float $b) use ($lcg): float { return $a + $lcg() * ($b - $a); };
    $ns = $noiseSeed + 777;
    $nrand = function () use (&$ns): float { $ns = ($ns * 1103515245 + 12345) & 0x7FFFFFFF; return $ns / 0x7FFFFFFF; };
    $X = []; $y = []; $miny = 1e9;
    for ($i = 0; $i < $n; $i++) {
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $X[] = $row;
        $noise = $sigma > 0 ? $sigma * sqrt(-2 * log(max(1e-12, $nrand()))) * cos(2 * M_PI * $nrand()) : 0.0;
        $y[] = $law($row) + $noise;
        if (abs($y[$i]) < $miny) { $miny = abs($y[$i]); }
    }
    return [$X, $y, $miny];
}
$iA = fn (array $r): float => $r[0] * $r[1];
$iB = fn (array $r): float => $r[3] * $r[4] * $r[4];
foreach ([0.002, 0.005] as $sg) {
    $pA = 0; $pB = 0;
    echo "sigma=$sg\n";
    foreach (range(401, 415) as $seed) {
        [$X, $y, $mA] = genN3($iA, 9001 + $seed, 200, $sg, $seed + 2000);
        $vA = cert_v2('(x0×x1)', $X, $y, $seed + 2000);
        [$X2, $y2, $mB] = genN3($iB, 9001 + $seed, 200, $sg, $seed + 3000);
        $vB = cert_v2('(x3×x4²)', $X2, $y2, $seed + 3000);
        $pA += $vA['pass'] ? 1 : 0; $pB += $vB['pass'] ? 1 : 0;
        printf("  seed %d A:%s(max %.3f,miny %.4f) B:%s(max %.3f,miny %.4f)\n", $seed,
            $vA['pass'] ? 'PASS' : 'FAIL', max(array_map(fn ($v) => $v ?? 9, $vA['cvs'])), $mA,
            $vB['pass'] ? 'PASS' : 'FAIL', max(array_map(fn ($v) => $v ?? 9, $vB['cvs'])), $mB);
    }
    echo "island A pass $pA/15, island B pass $pB/15\n";
}
echo "SCAN_DONE\n";
