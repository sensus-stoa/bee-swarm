<?php
declare(strict_types=1);
// EXP-051-B v2.1: amended spurious-forms pilot, ONE RUN PER PROCESS.
// Process isolation = trail-DB (roads) never leaks across runs; crash-safe JSONL.
// argv: [1]=out.jsonl [2]=mode(contour|direct|shuffled) [3]=sigma [4]=seed
//       [5]=heldNoise(indep|same)
// Contour arm v2 = E48-FAITHFUL island decomposition: island tasks
// (A: x0*x1, B: x3*x4^2, C: x5) searched and certified INDIVIDUALLY
// (ratio gate cert_v2 on each island task), then merged, then the
// composite gate. Rationale (instrument fix after sanity FAIL of the
// composite-ladder variant): E40 - no beam gradient to the 3rd factor
// on the composite (E=0 by symmetry); measured: x3×x4² never
// materializes in composite trails (warm 8 and 24 identical, lib=4);
// E48 islands cert@gen1. The island-level individual certification IS
// the regularization mechanism under test.
// Gates of record: cert_v2 (ratio-CV). Offline secondary: MAE-CV on
// the same splits (mechanism test: ratio tail-driven vs MAE flat).
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$db = BeeSwarm\Infra\Database::get();

$outPath = (string) $argv[1];
$mode = (string) ($argv[2] ?? 'contour');
$sigma = (float) ($argv[3] ?? 0.0);
$seed = (int) ($argv[4] ?? 401);
$heldMode = (string) ($argv[5] ?? 'indep');

$TRUE_TERMS = ['x0×x1', 'x3×x4²', 'x5'];
$MAX_CERT_FORMS_PER_ISLAND = 5;  // material budget: distinct certified forms per island
$ISLAND_GENS = 3;
$ISLAND_GEN_WALL = 20.0;

function genNoisy(callable $law, int $seed, int $n, float $sigma, int $noiseSeed): array {
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

function mae_cv(string $formulaX, array $Xe, array $ye): ?float {
    $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($formulaX, $Xe);
    if (! is_array($vals) || count($vals) !== count($ye)) { return null; }
    $ae = 0.0; $ay = 0.0;
    foreach ($vals as $i => $p) {
        if (! is_numeric($p) || ! is_finite((float) $p)) { return null; }
        $ae += abs((float) $p - (float) $ye[$i]);
        $ay += abs((float) $ye[$i]);
    }
    if ($ay == 0.0) { return null; }
    return $ae / $ay;
}

function mae_v2(string $formulaX, array $Xall, array $yall, int $taskSeed): array {
    $cvs = [];
    for ($sp = 0; $sp < 3; $sp++) {
        [, , $Xe, $ye] = split_eval($Xall, $yall, $taskSeed + $sp * 104729);
        $cvs[] = mae_cv($formulaX, $Xe, $ye);
    }
    return $cvs;
}

function trueComboMatch(string $comboForm, array $trueTerms): bool {
    $t = explode('+', str_replace(['(', ')'], '', $comboForm));
    $t = array_map('trim', $t);
    sort($t);
    $ref = $trueTerms;
    sort($ref);
    return $t == $ref;
}

/** Evolve+certify on ONE island task; return distinct certified X-forms. */
function islandSearch(callable $law, int $seed, float $sigma, int $noiseSeed, array $labels, int $gens, float $genWall): array {
    [$Xi, $yi] = genNoisy($law, 9001 + $seed, 200, $sigma, $noiseSeed);
    mt_srand($noiseSeed);
    $ledger = new BeeSwarm\Hive\RouteLedger();
    $ledger->register(ladder(0, 'isl' . $noiseSeed));
    for ($k = 0; $k < 7; $k++) {
        $g = RouteMutator::randomGenome();
        $g['budget_fraction'] = 0.30; $g['max_steps'] = count($g['actions']);
        if (RouteGenome::validate($g) === []) { $ledger->register($g); }
    }
    $forms = []; $attempts = 0; $foundLog = [];
    for ($gen = 0; $gen < $gens; $gen++) {
        $wall = 0.0;
        foreach ($ledger->active() as $g) {
            if ($wall >= $genWall) { break; }
            $res = RouteExecutor::run($g, $Xi, $yi, $labels, min(12.0, $genWall - $wall), 0.10, [], 1);
            $wall += $res['wall'];
            if ($res['found']) {
                $attempts++;
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $noEval = null;
                $v2 = cert_v2($fx, $Xi, $yi, $noiseSeed, $noEval);
                if (count($foundLog) < 14) {
                    $foundLog[] = ['form' => $fx, 'pass' => ($v2 !== null && $v2['pass']),
                        'cvs' => $v2['cvs'] ?? null, 'reason' => $v2['reason'] ?? null];
                }
                if ($v2 !== null && $v2['pass']) { $forms[$fx] = 1; }
            }
        }
        if (count($forms) >= $GLOBALS['MAX_CERT_FORMS_PER_ISLAND']) { break; }
        foreach ($ledger->topForReproduction(3) as $p) {
            if (count($ledger->active()) >= 12) { break; }
            $child = RouteMutator::mutate($p, mt_rand(0, 8));
            if ($child !== null && RouteGenome::validate($child) === []) { $ledger->register($child); }
        }
    }
    return ['forms' => array_keys($forms), 'found_attempts' => $attempts, 'found_log' => $foundLog];
}

$composite = fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5];

[$Xt, $yt] = genNoisy($composite, 9001 + $seed, 200, $sigma, $seed);
$heldNoiseSeed = ($heldMode === 'same') ? $seed : $seed + 1000;
[$Xh, $yh] = genNoisy($composite, 9001 + $seed, 100, $sigma, $heldNoiseSeed);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));

if ($mode === 'shuffled') {
    mt_srand($seed + 777);
    $ys = $yt;
    shuffle($ys);
    $yt = $ys;
}

$result = [];
if ($mode === 'direct') {
    // REFERENCE arm (E39 known-failure): direct search on the composite, 5 gens.
    mt_srand($seed);
    $ledger = new BeeSwarm\Hive\RouteLedger();
    $ledger->register(ladder(0, 'dir'));
    for ($k = 0; $k < 7; $k++) {
        $g = RouteMutator::randomGenome();
        $g['budget_fraction'] = 0.30; $g['max_steps'] = count($g['actions']);
        if (RouteGenome::validate($g) === []) { $ledger->register($g); }
    }
    $certs = 0; $certLog = [];
    for ($gen = 0; $gen < 5; $gen++) {
        $wall = 0.0;
        foreach ($ledger->active() as $g) {
            if ($wall >= 30.0) { break; }
            $res = RouteExecutor::run($g, $Xt, $yt, $labels, min(12.0, 30.0 - $wall), 0.10, [], 1);
            $wall += $res['wall'];
            if ($res['found']) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $noEval = null;
                $v2 = cert_v2($fx, $Xt, $yt, $seed, $noEval);
                if ($v2 !== null && $v2['pass']) {
                    $certs++;
                    $hcv = cv_eval($fx, $Xh, $yh);
                    $certLog[] = ['form' => $fx, 'train_cvs' => $v2['cvs'], 'held_cv' => $hcv,
                        'held_mae_cv' => mae_cv($fx, $Xh, $yh), 'train_mae_cvs' => mae_v2($fx, $Xt, $yt, $seed)];
                }
            }
        }
        foreach ($ledger->topForReproduction(3) as $p) {
            if (count($ledger->active()) >= 12) { break; }
            $child = RouteMutator::mutate($p, mt_rand(0, 8));
            if ($child !== null && RouteGenome::validate($child) === []) { $ledger->register($child); }
        }
    }
    $result = ['arm' => 'direct_reference', 'certs_train' => $certs, 'certs' => $certLog];
} else {
    // ---------- CONTOUR arm v2: island decomposition + individual certs + merge ----------
    $islands = [
        'A' => ['law' => fn (array $r): float => $r[0] * $r[1], 'noiseSeed' => $seed + 2000],
        'B' => ['law' => fn (array $r): float => $r[3] * $r[4] * $r[4], 'noiseSeed' => $seed + 3000],
        'C' => ['law' => fn (array $r): float => $r[5], 'noiseSeed' => $seed + 4000],
    ];
    $islandOut = [];
    foreach ($islands as $name => $cfg) {
        $s = islandSearch($cfg['law'], $seed, $sigma, $cfg['noiseSeed'], $labels, $ISLAND_GENS, $ISLAND_GEN_WALL);
        $islandOut[$name] = $s;
    }
    // true-form presence at island level (the regularization filter's recall);
    // island forms are stored parenthesized — compare trimmed
    $trim = fn (array $fs): array => array_map(fn (string $f): string => trim($f, '()'), $fs);
    $trueInIsland = [
        'A' => in_array('x0×x1', $trim($islandOut['A']['forms']), true) ? 1 : 0,
        'B' => in_array('x3×x4²', $trim($islandOut['B']['forms']), true) ? 1 : 0,
        'C' => in_array('x5', $trim($islandOut['C']['forms']), true) ? 1 : 0,
    ];
    $spuriousIslandForms = 0;
    foreach ($islandOut as $name => $s) {
        foreach ($trim($s['forms']) as $f) {
            if (! in_array($f, $TRUE_TERMS, true)) { $spuriousIslandForms++; }
        }
    }
    // merge enumeration: one certified form per island -> composite gate
    $certs = 0; $falseCerts = 0; $heldPass = 0; $trueCerts = 0; $certLog = [];
    foreach ($islandOut['A']['forms'] as $a) {
        foreach ($islandOut['B']['forms'] as $b) {
            foreach ($islandOut['C']['forms'] as $c) {
                $comboForm = '(' . $a . ')+(' . $b . ')+(' . $c . ')';
                $cv = cv_eval($comboForm, array_slice($Xt, 160), array_slice($yt, 160));
                if ($cv !== null && $cv <= 0.10) {
                    $noEval = null;
                    $v2 = cert_v2($comboForm, $Xt, $yt, $seed + 500, $noEval);
                    if ($v2 !== null && $v2['pass']) {
                        $certs++;
                        $hcv = cv_eval($comboForm, $Xh, $yh);
                        $spurious = ($hcv === null || $hcv > 0.10);
                        if ($spurious) { $falseCerts++; } else { $heldPass++; }
                        $isTrue = trueComboMatch($comboForm, $TRUE_TERMS);
                        if ($isTrue) { $trueCerts++; }
                        // SECONDARY (mechanism test): offline MAE-CV gate on the SAME splits
                        $tm = mae_v2($comboForm, $Xt, $yt, $seed + 500);
                        $maePass = ($tm[0] !== null && $tm[1] !== null && $tm[2] !== null
                            && $tm[0] <= 0.10 && $tm[1] <= 0.10 && $tm[2] <= 0.10);
                        $heldMae = mae_cv($comboForm, $Xh, $yh);
                        $certLog[] = ['form' => $comboForm, 'true_match' => $isTrue,
                            'train_cvs' => $v2['cvs'], 'held_cv' => $hcv, 'spurious_ratio' => $spurious,
                            'train_mae_cvs' => $tm, 'mae_pass_train' => $maePass,
                            'held_mae_cv' => $heldMae,
                            'spurious_mae' => ($heldMae === null || $heldMae > 0.10)];
                    }
                }
            }
        }
    }
    $result = ['arm' => 'contour_v2_islands',
        'islands' => ['A' => ['certs' => count($islandOut['A']['forms']), 'attempts' => $islandOut['A']['found_attempts'], 'found_log' => $islandOut['A']['found_log'] ?? []],
                      'B' => ['certs' => count($islandOut['B']['forms']), 'attempts' => $islandOut['B']['found_attempts'], 'found_log' => $islandOut['B']['found_log'] ?? []],
                      'C' => ['certs' => count($islandOut['C']['forms']), 'attempts' => $islandOut['C']['found_attempts'], 'found_log' => $islandOut['C']['found_log'] ?? []]],
        'island_forms' => ['A' => $islandOut['A']['forms'], 'B' => $islandOut['B']['forms'], 'C' => $islandOut['C']['forms']],
        'true_in_island' => $trueInIsland,
        'spurious_island_forms' => $spuriousIslandForms,
        'certs_train' => $certs, 'held_pass' => $heldPass, 'false_certs' => $falseCerts,
        'true_certs' => $trueCerts, 'certs' => $certLog];
}

$line = json_encode(['mode' => $mode, 'sigma' => $sigma, 'seed' => $seed,
    'held' => $heldMode, 'result' => $result], JSON_UNESCAPED_UNICODE);
file_put_contents($outPath, $line . "\n", FILE_APPEND);
echo 'RUN_DONE mode=' . $mode . ' sigma=' . $sigma . ' seed=' . $seed . ' certs=' . ($result['certs_train'] ?? '?')
    . ' false=' . ($result['false_certs'] ?? '-') . ' true_certs=' . ($result['true_certs'] ?? '-') . "\n";
