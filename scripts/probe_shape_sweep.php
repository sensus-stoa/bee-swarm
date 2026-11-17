<?php
declare(strict_types=1);

// SHAPE SWEEP (owner 0h-check): does ANY route shape certify heat@301?
// Direct enumeration, no evolution. Random shapes (bf=max, min 3 actions),
// each: fresh road + 12s channel + strict cert_v2 (independence kept).
require_once __DIR__ . '/exp045/cert_utils.php';

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;

putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';

$n = (int) (getenv('SWEEP_N') ?: 200);
$outPath = $argv[1] ?? '/tmp/shape_sweep.json';

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
    $yall[] = $row[0] * $row[1] * $row[2]; // heat GT = x0*x1*x2
}
[$X, $y, $Xe, $ye] = split_eval($Xall, $yall, $taskSeed + 301);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, count($X[0]) - 1));

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

$db = BeeSwarm\Infra\Database::get();
$rows = [];
$certs = 0;
$forms = [];
for ($i = 1; $i <= $n; $i++) {
    mt_srand(500000 + $i);
    $g = RouteMutator::randomGenome();
    $g['budget_fraction'] = RouteGenome::BUDGET_MAX;
    $g['max_steps'] = count($g['actions']);
    $err = RouteGenome::validate($g);
    if ($err !== []) {
        $rows[] = ['i' => $i, 'invalid' => $err];
        continue;
    }
    $db->exec('DELETE FROM trail_marks');
    $res = RouteExecutor::run($g, $X, $y, $labels, 12.0, 0.10, [], 1);
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
    if ($fx !== null) {
        $forms[$fx] = ($forms[$fx] ?? 0) + 1;
    }
    $shapes = [];
    foreach ($g['actions'] as $a) {
        if ($a['op'] === 'FLIGHT') {
            $shapes[] = 'F:' . $a['grammar'] . ':' . $a['beam'] . ':d' . $a['depth'];
        } elseif ($a['op'] === 'USE_MARKS') {
            $shapes[] = 'M' . $a['k'];
        } else {
            $shapes[] = 'B';
        }
    }
    $rows[] = [
        'i' => $i, 'cert' => $cert, 'found' => (bool) $res['found'],
        'cov' => round(heatStructuralCoverage($fx), 3),
        'shape' => $shapes,
        'form' => $fx !== null ? substr($fx, 0, 60) : null,
    ];
    if ($i % 25 === 0) {
        fwrite(STDERR, 'sweep ' . $i . '/' . $n . ' certs=' . $certs . "\n");
    }
}
$certRows = array_values(array_filter($rows, fn (array $r): bool => ! empty($r['cert'])));
$covs = array_values(array_filter(array_map(fn (array $r): float => (float) ($r['cov'] ?? 0.0), $rows), fn (float $c): bool => $c > 0.0));
$out = [
    'task' => 'heat@301', 'n' => $n, 'certs' => $certs,
    'cert_rate' => round($certs / $n, 4),
    'distinct_forms' => count($forms),
    'max_coverage' => $covs === [] ? 0.0 : round(max($covs), 3),
    'mean_coverage_pos' => $covs === [] ? 0.0 : round(array_sum($covs) / count($covs), 3),
    'certifying_shapes' => array_map(fn (array $r): array => ['i' => $r['i'], 'shape' => $r['shape'], 'form' => $r['form']], $certRows),
    'rows' => $rows,
];
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'DONE sweep: certs=' . $certs . '/' . $n . ' maxCov=' . $out['max_coverage'] . ' forms=' . count($forms) . ' -> ' . $outPath . "\n";

