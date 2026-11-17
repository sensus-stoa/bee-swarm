<?php
declare(strict_types=1);

// CHAIN PROBE (owner step-2, 05.11): the DOT4 mechanism on heat@301.
// 8 sequential ladder executions, road ACCUMULATING (no wipe), 12s each.
require_once __DIR__ . '/exp045/cert_utils.php';

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;

putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';

$outPath = $argv[1] ?? '/tmp/chain_heat.json';
$taskSeed = (int) hexdec(substr(md5('STRATB_heat'), 0, 7));
$s = $taskSeed;
$lcg = function () use (&$s) {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$u = function (float $a, float $b) use ($lcg): float {
    return $a + $lcg() * ($b - $a);
};
$Xall = []; $yall = [];
for ($i = 0; $i < 200; $i++) {
    $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
    $Xall[] = $row;
    $yall[] = $row[0] * $row[1] * $row[2];
}
[$X, $y, $Xe, $ye] = split_eval($Xall, $yall, $taskSeed + 301);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, count($X[0]) - 1));
$db = BeeSwarm\Infra\Database::get();

function heatStructuralCoverage(?string $fx): float
{
    if ($fx === null || $fx === '' || $fx === 'none') {
        return 0.0;
    }
    $cov = 0.0;
    foreach (['x0', 'x1', 'x2'] as $v) {
        if (preg_match('/(^|[^x0-9])' . $v . '(?![0-9])\s*[×*]/', $fx)
            || preg_match('/[×*]\s*' . $v . '(?![0-9])/', $fx)) {
            $cov += 1 / 3;
        }
    }
    return $cov;
}

$rows = [];
$certs = 0;
mt_srand(4242);
for ($i = 0; $i < 8; $i++) {
    $ladder = [
        'id' => 'route_' . substr(sha1('probe_ladder_' . $i), 0, 8),
        'parent_id' => '',
        'condition' => ['kind' => 'ALWAYS'],
        'budget_fraction' => 0.30,
        'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ],
    ];
    if (RouteGenome::validate($ladder) !== []) {
        fwrite(STDERR, "LADDER INVALID\n");
        exit(3);
    }
    $res = RouteExecutor::run($ladder, $X, $y, $labels, 12.0, 0.10, [], 1);
    $cert = false;
    $fx = null;
    if ($res['found']) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval = null;
        $v2 = cert_v2($fx, $Xall, $yall, $taskSeed, $noEval);
        $cert = $v2 !== null && $v2['pass'];
    } else {
        $pf = (string) ($res['partial_form'] ?? 'none');
        if ($pf !== '' && $pf !== 'none') {
            $fx = RouteExecutor::expandLocalAtoms(labels_to_x($pf), $res['local_atoms']);
        }
    }
    if ($cert) {
        $certs++;
    }
    $roadSize = (int) $db->query('SELECT COUNT(*) AS c FROM trail_marks')->fetch()['c'];
    $rows[] = [
        'ladder' => $i + 1, 'cert' => $cert, 'found' => (bool) $res['found'],
        'cov' => round(heatStructuralCoverage($fx), 3),
        'form' => $fx !== null ? substr($fx, 0, 60) : null,
        'wall' => round($res['wall'], 1),
        'road_size_after' => $roadSize,
    ];
    fwrite(STDERR, 'chain ' . ($i + 1) . '/8 cert=' . var_export($cert, true) . ' cov=' . round(heatStructuralCoverage($fx), 3) . ' road=' . $roadSize . ' form=' . substr((string) $fx, 0, 40) . "\n");
}
$out = [
    'task' => 'heat@301', 'mechanism' => 'DOT4 chain (8 ladders, road accumulating, 12s each)',
    'certs' => $certs, 'rows' => $rows,
];
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'DONE chain: certs=' . $certs . '/8 -> ' . $outPath . "\n";
