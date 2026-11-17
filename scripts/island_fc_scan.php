<?php
declare(strict_types=1);
// EXP-051-B island-level false-cert scan (07.11): certs exist ONLY at the
// island level under additive noise (composite pipeline dies: island B
// structurally uncertifiable — x4^2 guarantees min|y| ~ 1e-4).
// Primary prereg metric, measurable regime: certified island form vs
// held-out island data with INDEPENDENT noise seed. Plus same-seed control.
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$db = BeeSwarm\Infra\Database::get();

function genN3(callable $law, int $seed, int $n, float $sigma, int $noiseSeed): array {
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
function ladder(int $i, string $salt): array {
    return ['id' => 'route_' . substr(sha1($salt . '_' . $i), 0, 8), 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}
// deterministic search trajectory = identical to probe_spurious_v2 islandSearch
function islandSearch(callable $law, int $seed, float $sigma, int $noiseSeed, array $labels): array {
    [$Xi, $yi] = genN3($law, 9001 + $seed, 200, $sigma, $noiseSeed);
    mt_srand($noiseSeed);
    $ledger = new BeeSwarm\Hive\RouteLedger();
    $ledger->register(ladder(0, 'isl' . $noiseSeed));
    for ($k = 0; $k < 7; $k++) {
        $g = RouteMutator::randomGenome();
        $g['budget_fraction'] = 0.30; $g['max_steps'] = count($g['actions']);
        if (RouteGenome::validate($g) === []) { $ledger->register($g); }
    }
    $forms = [];
    for ($gen = 0; $gen < 3; $gen++) {
        $wall = 0.0;
        foreach ($ledger->active() as $g) {
            if ($wall >= 20.0) { break; }
            $res = RouteExecutor::run($g, $Xi, $yi, $labels, min(12.0, 20.0 - $wall), 0.10, [], 1);
            $wall += $res['wall'];
            if ($res['found']) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $noEval = null;
                $v2 = cert_v2($fx, $Xi, $yi, $noiseSeed, $noEval);
                if ($v2 !== null && $v2['pass']) { $forms[$fx] = 1; }
            }
        }
        if (count($forms) >= 5) { break; }
        foreach ($ledger->topForReproduction(3) as $p) {
            if (count($ledger->active()) >= 12) { break; }
            $child = RouteMutator::mutate($p, mt_rand(0, 8));
            if ($child !== null && RouteGenome::validate($child) === []) { $ledger->register($child); }
        }
    }
    return array_keys($forms);
}

$islands = [
    'A' => ['law' => fn (array $r): float => $r[0] * $r[1], 'true' => 'x0×x1', 'off' => 2000],
    'B' => ['law' => fn (array $r): float => $r[3] * $r[4] * $r[4], 'true' => 'x3×x4²', 'off' => 3000],
    'C' => ['law' => fn (array $r): float => $r[5], 'true' => 'x5', 'off' => 4000],
];
$labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));
$out = [];

foreach ([0.002, 0.005] as $sigma) {
    foreach (range(401, 415) as $seed) {
        foreach ($islands as $name => $cfg) {
            $ns = $seed + $cfg['off'];
            $forms = islandSearch($cfg['law'], $seed, $sigma, $ns, $labels);
            if (! $forms) { continue; }
            // independent-noise held-out (prereg primary)
            [$Xh, $yh] = genN3($cfg['law'], 9001 + $seed, 100, $sigma, $ns + 1500);
            // same-seed held-out (mechanism control)
            [$Xs, $ys] = genN3($cfg['law'], 9001 + $seed, 100, $sigma, $ns);
            foreach ($forms as $f) {
                $hcv = cv_eval($f, $Xh, $yh);
                $scv = cv_eval($f, $Xs, $ys);
                $isTrue = (trim($f, '()') === $cfg['true']);
                $out[] = ['sigma' => $sigma, 'seed' => $seed, 'island' => $name, 'form' => $f,
                    'true' => $isTrue, 'train_pass' => true,
                    'held_cv' => $hcv, 'false_cert' => ($hcv === null || $hcv > 0.10),
                    'same_held_cv' => $scv, 'false_cert_same' => ($scv === null || $scv > 0.10)];
            }
        }
    }
}
file_put_contents('/home/ninjacat/.bee_swarm/Benchmarks/island_falsecert.jsonl',
    implode("\n", array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $out)) . "\n");
// aggregate
$agg = [];
foreach ($out as $r) {
    $k = $r['sigma'] . '|' . $r['island'];
    $a = $agg[$k] ??= ['n' => 0, 'false' => 0, 'false_same' => 0, 'true_n' => 0, 'spur_n' => 0];
    $a['n']++;
    $a['false'] += $r['false_cert'] ? 1 : 0;
    $a['false_same'] += $r['false_cert_same'] ? 1 : 0;
    $r['true'] ? $a['true_n']++ : $a['spur_n']++;
}
echo "=== AGGREGATE (island-level false certs) ===\n";
foreach ($agg as $k => $v) { echo "$k: certs=$v[n] (true {$v['true_n']} / spurious {$v['spur_n']}) false_cert(indep)={$v['false']} false_cert(same)={$v['false_same']}\n"; }
echo "ISLAND_SCAN_DONE\n";
