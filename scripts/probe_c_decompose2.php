<?php
declare(strict_types=1);

// C-DECOMPOSE PILOT v2 (owner fallback: hand-crafted C). T1 = term-level
// decomposition of C on sub-task data; T3 = counter; T2 = road transplant.
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;

putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$outPath = $argv[1] ?? '/tmp/c_decompose2.json';
$db = BeeSwarm\Infra\Database::get();

$laws = [
    'composite' => fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5],
    'A' => fn (array $r): float => $r[0] * $r[1],
    'B' => fn (array $r): float => $r[3] * $r[4] * $r[4],
    'counter' => fn (array $r): float => -($r[0] * $r[1]),
];
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
[$Xc, $yc] = genData($laws['composite'], 9001);
[$Xa, $ya] = genData($laws['A'], 9002);
[$Xb, $yb] = genData($laws['B'], 9003);
[$Xk, $yk] = genData($laws['counter'], 9004);
$labelsC = array_map(fn (int $i): string => "c{$i}", range(0, 5));

// hand-crafted C (the engine syntax: x^2 rendering observed in sweep forms)
$Cform = '((x0×x1)+(x3×x4²))+x5';
$selfCv = cv_eval($Cform, array_slice($Xc, 160), array_slice($yc, 160));
fwrite(STDERR, 'C self-CV on composite: ' . var_export($selfCv, true) . "\n");
if ($selfCv === null || $selfCv > 0.10) {
    $alt = '((x0×x1)+(x3×(x4×x4)))+x5';
    $selfCv2 = cv_eval($alt, array_slice($Xc, 160), array_slice($yc, 160));
    fwrite(STDERR, 'alt syntax CV: ' . var_export($selfCv2, true) . "\n");
    if ($selfCv2 !== null && $selfCv2 <= 0.10) {
        $Cform = $alt;
        $selfCv = $selfCv2;
    } else {
        file_put_contents($outPath, json_encode(['pilot' => 'c_decompose_v2', 'blocked' => 'hand-C syntax rejected by the evaluator', 'tried' => [$selfCv, $selfCv2]], JSON_PRETTY_PRINT));
        echo 'BLOCKED: hand-C syntax' . "\n";
        exit(0);
    }
}

// T1: depth-0 '+' split -> per-term cv on sub-task data
$terms = [];
$depth = 0; $cur = '';
for ($i = 0; $i < strlen($Cform); $i++) {
    $ch = $Cform[$i];
    if ($ch === '(') { $depth++; }
    if ($ch === ')') { $depth--; }
    if ($ch === '+' && $depth === 0) { $terms[] = trim($cur); $cur = ''; continue; }
    $cur .= $ch;
}
$terms[] = trim($cur);
$t1rows = [];
$T1pass = true;
foreach ($terms as $ti => $term) {
    if ($ti === 0) { [$Xt, $yt] = [$Xa, $ya]; $name = 'A'; }
    elseif ($ti === 1) { [$Xt, $yt] = [$Xb, $yb]; $name = 'B'; }
    else { [$Xt, $yt] = [$Xc, $yc]; $name = 'x5'; }
    $n = count($yt);
    $cv = cv_eval($term, array_slice($Xt, $n - 40), array_slice($yt, $n - 40));
    $ok = $cv !== null && $cv <= 0.10;
    if ($ti < 2 && ! $ok) { $T1pass = false; }
    $t1rows[] = ['term' => $term, 'on' => $name, 'cv' => $cv, 'pass' => $ok];
    fwrite(STDERR, 'T1 [' . $term . '] on ' . $name . ': cv=' . var_export($cv, true) . ' pass=' . var_export($ok, true) . "\n");
}

// T3: counter
$cvCounter = cv_eval($Cform, array_slice($Xk, 160), array_slice($yk, 160));
$T3breaks = $cvCounter === null || $cvCounter > 0.10;
fwrite(STDERR, 'T3 counter cv=' . var_export($cvCounter, true) . ' breaks=' . var_export($T3breaks, true) . "\n");

// T2: the road transplant (the T0 chain's marks are NOT here - the fresh
// process; build the organ road by executing the chain on the COMPOSITE first)
$road = [];
function ladder(int $i): array
{
    return [
        'id' => 'route_' . substr(sha1('decomp_ladder_' . $i), 0, 8),
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
$labelsC = array_map(fn (int $i): string => "c{$i}", range(0, 5));
mt_srand(4242);
$roadBuilt = 0;
for ($i = 0; $i < 8; $i++) {
    RouteExecutor::run(ladder($i), $Xc, $yc, $labelsC, 12.0, 0.10, [], 1);
    $roadBuilt = max($roadBuilt, (int) $db->query('SELECT COUNT(*) AS c FROM trail_marks')->fetch()['c']);
}
fwrite(STDERR, 'organ road built: ' . $roadBuilt . " marks\n");

function miniEvolve(array $X, array $y, array $labels, bool $wipe, int $seed, int $gens = 15): array
{
    $db = BeeSwarm\Infra\Database::get();
    if ($wipe) {
        $db->exec('DELETE FROM trail_marks');
    }
    mt_srand($seed);
    $ledger = new BeeSwarm\Hive\RouteLedger();
    $seedG = [
        'id' => 'route_' . substr(sha1('t2seed_' . $seed), 0, 8),
        'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ],
    ];
    $ledger->register($seedG);
    for ($k = 0; $k < 4; $k++) {
        $g = RouteMutator::randomGenome();
        $g['budget_fraction'] = 0.30;
        $g['max_steps'] = count($g['actions']);
        if (RouteGenome::validate($g) === []) { $ledger->register($g); }
    }
    $certs = 0; $firstCert = null; $forms = [];
    for ($gen = 0; $gen < $gens; $gen++) {
        $wall = 0.0;
        foreach ($ledger->active() as $g) {
            if ($wall >= 30.0) { break; }
            $res = RouteExecutor::run($g, $X, $y, $labels, min(12.0, 30.0 - $wall), 0.10, [], 1);
            $wall += $res['wall'];
            if ($res['found']) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $forms[$fx] = 1;
                $noEval = null;
                $v2 = cert_v2($fx, $X, $y, $seed, $noEval);
                if ($v2 !== null && $v2['pass']) {
                    $certs++;
                    if ($firstCert === null) { $firstCert = $gen + 1; }
                }
            }
        }
        foreach ($ledger->topForReproduction(3) as $p) {
            if (count($ledger->active()) >= 12) { break; }
            $child = RouteMutator::mutate($p, mt_rand(0, 8));
            if ($child !== null && RouteGenome::validate($child) === []) { $ledger->register($child); }
        }
    }
    return ['certs_novel' => $certs, 'first_cert_gen' => $firstCert, 'distinct_forms' => count($forms)];
}
$t2a = miniEvolve($Xa, $ya, $labelsC, false, 771); // the organ road persists
$t2b = miniEvolve($Xa, $ya, $labelsC, true, 772);  // fresh road control
fwrite(STDERR, 'T2 transplant: ' . json_encode($t2a) . ' control: ' . json_encode($t2b) . "\n");

$out = [
    'pilot' => 'c_decompose_v2', 'C_form' => $Cform, 'C_self_cv' => $selfCv,
    'T1_decomposition' => ['terms' => $t1rows, 'pass' => $T1pass],
    'T3_counter' => ['cv' => $cvCounter, 'breaks' => $T3breaks],
    'T2_transplant' => ['with_road' => $t2a, 'fresh_road' => $t2b, 'organ_road_marks' => $roadBuilt],
];
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'DONE c_decompose_v2: T1=' . var_export($T1pass, true) . ' T3breaks=' . var_export($T3breaks, true) . ' T2a=' . json_encode($t2a) . ' T2b=' . json_encode($t2b) . "\n";
