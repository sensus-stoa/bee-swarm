<?php
declare(strict_types=1);
// SPURIOUS-PILOT DIAGNOSTIC (07.11): why certs_train = 0 everywhere.
// (1) POSITIVE CONTROL: cert_v2/cv_eval on the TRUE composite form.
// (2) MATERIAL: what do the ladders actually produce at sigma=0.05 and sigma=0?
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$db = BeeSwarm\Infra\Database::get();

function genNoisyDiag(callable $law, int $seed, int $n, float $sigma, int $noiseSeed): array {
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
function ladderDiag(int $i, string $salt): array {
    return ['id' => 'route_' . substr(sha1($salt . '_' . $i), 0, 8), 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}
$composite = fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5];
$labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));

foreach ([0.05, 0.0] as $SIGMA) {
    $seed = 401;
    [$Xt, $yt] = genNoisyDiag($composite, 9001 + $seed, 200, $SIGMA, $seed);
    echo "########## SIGMA=$SIGMA ##########\n";
    echo "=== POSITIVE CONTROL: gate on the TRUE form ===\n";
    $v2 = cert_v2('x0×x1+x3×x4²+x5', $Xt, $yt, $seed);
    echo "cert_v2(true composite) = " . json_encode($v2) . "\n";
    $cv40 = cv_eval('(x0×x1)+(x3×x4²)+(x5)', array_slice($Xt, 160), array_slice($yt, 160));
    echo "cv_eval(combo w/parens, rows160-199) = " . json_encode($cv40) . "\n";
    $v2term = cert_v2('x0×x1', $Xt, $yt, $seed);
    echo "cert_v2(single term x0×x1) = " . json_encode($v2term) . "\n";

    echo "=== MATERIAL: 2 ladders ===\n";
    for ($i = 0; $i < 2; $i++) {
        $res = RouteExecutor::run(ladderDiag($i, 'cnt'), $Xt, $yt, $labels, 12.0, 0.10, [], 1);
        echo "ladder $i: found=" . json_encode($res['found']) . " wall=" . round($res['wall'], 1) . " stop=" . json_encode($res['stop'] ?? null)
            . " best_L=" . json_encode($res['best_L_valid']) . " best_fx=" . json_encode($res['best_formula_x'] ?? null) . " n_rec=" . count($res['records']) . "\n";
        if ($res['found']) { echo "  cert_formula: " . $res['cert_formula'] . " -> x: " . labels_to_x((string) $res['cert_formula']) . "\n"; }
        $ops = [];
        foreach ($res['records'] as $rec) { $ops[] = $rec['op'] ?? '?'; }
        echo "  ops: " . implode(',', $ops) . "\n";
        foreach ($res['records'] as $ri => $rec) {
            if (($rec['op'] ?? '') !== 'FLIGHT') { continue; }
            $w = $rec['trail']['written'] ?? 'NO_KEY';
            $s = is_array($w) ? ('n=' . count($w) . ' first3=' . json_encode(array_slice($w, 0, 3))) : json_encode($w);
            echo "  rec$ri trail.written: $s\n";
        }
    }
}
echo "DIAG_DONE\n";
