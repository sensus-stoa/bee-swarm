<?php
declare(strict_types=1);

// ISLAND-DECOMPOSITION PILOT (owner step-2, 05.11): per-block islands evolve
// on their OWN sub-tasks; the merge = syntactic ('+'); the composite is never
// searched directly. Control: direct evolution on the composite.
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;

putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$outPath = $argv[1] ?? '/tmp/islands.json';
$db = BeeSwarm\Infra\Database::get();

function genData(callable $law, int $seed, int $n = 200): array
{
    $s = $seed;
    $lcg = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
        return $s / 0x7FFFFFFF;
    };
    $u = function (float $a, float $b) use ($lcg): float {
        return $a + $lcg() * ($b - $a);
    };
    $X = []; $y = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $X[] = $row;
        $y[] = $law($row);
    }
    return [$X, $y];
}
$laws = [
    'A' => fn (array $r): float => $r[0] * $r[1],
    'B' => fn (array $r): float => $r[3] * $r[4] * $r[4],
    'composite' => fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5],
];
[$Xa, $ya] = genData($laws['A'], 9002);
[$Xb, $yb] = genData($laws['B'], 9003);
[$Xc, $yc] = genData($laws['composite'], 9001);
$labelsC = array_map(fn (int $i): string => "c{$i}", range(0, 5));
$labelsAB = $labelsC;

function ladder(int $i, string $salt): array
{
    return [
        'id' => 'route_' . substr(sha1('isl_' . $salt . '_' . $i), 0, 8),
        'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ],
    ];
}

function islandEvolve(array $X, array $y, array $labels, int $seed, int $gens = 10, string $salt = 'i'): array
{
    // step-3 (450s, 0h config): the per-gen channel from env; the per-genome
    // share keeps the 0.4 ratio (12/30 in the default config)
    $genChannel = (float) (getenv('DIRECT_BUDGET') ?: 30.0);
    $genShare = $genChannel * 0.4;
    mt_srand($seed);
    $ledger = new BeeSwarm\Hive\RouteLedger();
    for ($i = 0; $i < 3; $i++) {
        $ledger->register(ladder($i, $salt));
    }
    for ($k = 0; $k < 5; $k++) {
        $g = RouteMutator::randomGenome();
        $g['budget_fraction'] = 0.30;
        $g['max_steps'] = count($g['actions']);
        if (RouteGenome::validate($g) === []) { $ledger->register($g); }
    }
    $cert = null; $certForm = null; $bestCv = 9.99; $forms = [];
    for ($gen = 0; $gen < $gens; $gen++) {
        $wall = 0.0;
        foreach ($ledger->active() as $g) {
            if ($wall >= $genChannel) { break; }
            $res = RouteExecutor::run($g, $X, $y, $labels, min($genShare, $genChannel - $wall), 0.10, [], 1);
            $wall += $res['wall'];
            $fx = null;
            if ($res['found']) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $forms[$fx] = 1;
                $noEval = null;
                $v2 = cert_v2($fx, $X, $y, $seed, $noEval);
                if ($v2 !== null && $v2['pass'] && $cert === null) {
                    $cert = $gen + 1;
                    $certForm = $fx;
                }
            } else {
                $pf = (string) ($res['partial_form'] ?? 'none');
                if ($pf !== '' && $pf !== 'none') {
                    $fx = RouteExecutor::expandLocalAtoms(labels_to_x($pf), $res['local_atoms']);
                }
            }
            if ($fx !== null) {
                $c = cv_eval($fx, array_slice($X, 160), array_slice($y, 160));
                if ($c !== null && $c < $bestCv) { $bestCv = $c; }
            }
        }
        foreach ($ledger->topForReproduction(3) as $p) {
            if (count($ledger->active()) >= 12) { break; }
            $child = RouteMutator::mutate($p, mt_rand(0, 8));
            if ($child !== null && RouteGenome::validate($child) === []) { $ledger->register($child); }
        }
    }
    return ['cert_gen' => $cert, 'cert_form' => $certForm, 'best_cv' => $bestCv === 9.99 ? null : round($bestCv, 4), 'distinct_forms' => count($forms)];
}

// islands (separate DB contexts not needed: the road = wiped per island)
if (getenv('DIRECT_ONLY') === '1') {
    // step-3 mode: the direct-composite probe only (the 450s envelope test)
    $direct = islandEvolve($Xc, $yc, $labelsC, 803, (int) (getenv('DIRECT_GENS') ?: 3), 'd');
    $out = ['pilot' => 'direct450', 'direct_composite' => $direct, 'budget' => (float) (getenv('DIRECT_BUDGET') ?: 450.0)];
    file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo 'DONE direct450: cert=' . var_export($direct['cert_gen'], true) . ' bestCv=' . var_export($direct['best_cv'], true) . ' forms=' . $direct['distinct_forms'] . "
";
    exit(0);
}
$islandA = islandEvolve($Xa, $ya, $labelsAB, 801, 10, 'a');
$db->exec('DELETE FROM trail_marks');
$islandB = islandEvolve($Xb, $yb, $labelsAB, 802, 10, 'b');
$db->exec('DELETE FROM trail_marks');
fwrite(STDERR, 'island A: ' . json_encode($islandA) . "\n");
fwrite(STDERR, 'island B: ' . json_encode($islandB) . "\n");

// MERGE (syntactic, E42): champion forms + x5 -> the composite, never searched
$mergeCv = null; $merged = null;
if ($islandA['cert_form'] !== null && $islandB['cert_form'] !== null) {
    $merged = '(' . $islandA['cert_form'] . ')+(' . $islandB['cert_form'] . ')+x5';
    $mergeCv = cv_eval($merged, array_slice($Xc, 160), array_slice($yc, 160));
}
fwrite(STDERR, 'MERGE: ' . (string) $merged . ' cv=' . var_export($mergeCv, true) . "\n");

// control: DIRECT evolution on the composite (predicted 0 by E39)
$direct = islandEvolve($Xc, $yc, $labelsC, 803, 15, 'd');
fwrite(STDERR, 'direct composite: ' . json_encode($direct) . "\n");

$out = [
    'pilot' => 'island_decomposition',
    'island_A' => $islandA,
    'island_B' => $islandB,
    'merged_form' => $merged,
    'merged_cv' => $mergeCv,
    'merge_pass' => $mergeCv !== null && $mergeCv <= 0.10,
    'direct_composite' => $direct,
];
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'DONE islands: A=' . json_encode($islandA) . ' B=' . json_encode($islandB) . ' mergePass=' . var_export($out['merge_pass'], true) . ' directCert=' . var_export($direct['cert_gen'], true) . "\n";
