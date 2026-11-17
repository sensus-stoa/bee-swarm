<?php
declare(strict_types=1);
// EXP-054: parameterized templates (owner spec 08.11, prereg = h2lite_t_prereg.md).
// Arms: fresh / exact (=EXP-053 hist) / templates (exact + one-subtree-wildcard
// generalizations, LEVEL B literal rule) / shuffled (pi-permuted anchors).
// cert_x = DUAL-CHANNEL (cert-v2.1, E52). ONE php process per run.
// Metrics of record: effort = flights+combos; cert_v2_evaluations (all calls);
// effort_with_instantiations = effort + cert_v2_evaluations; instantiation
// attempts counted separately; first-PASS adoption stops the candidate sweep.
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$db = BeeSwarm\Infra\Database::get();

$WARM_LADDERS = 8;
$FORMS_PER_ISLAND = 5;
$PERM_SEED = 424242;

function genData(int $lawId, int $seed, int $n, int $width): array {
    $s = $seed;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = function (float $a, float $b) use ($lcg): float { return $a + $lcg() * ($b - $a); };
    $X = []; $y = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [];
        for ($c = 0; $c < $width; $c++) { $row[] = $u(-2, 2); }
        $r = $row;
        $y[] = match ($lawId) {
            1 => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5],
            2 => $r[0] * $r[1] + $r[2] * $r[6] * $r[6] + $r[7],
            3 => $r[0] * $r[2] + $r[2] * $r[6] * $r[6] + $r[7],
            default => throw new \InvalidArgumentException('bad lawId'),
        };
        $X[] = $row;
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

function warmChain(array $X, array $y, int $taskSeed, array $labels, string $salt, int &$flights, int &$certCalls): array {
    $forms = [];
    for ($i = 0; $i < $GLOBALS['WARM_LADDERS']; $i++) {
        $res = RouteExecutor::run(ladder($i, $salt), $X, $y, $labels, 12.0, 0.10, [], 1);
        $flights++;
        if ($res['found']) {
            $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
            $noEval = null;
            $v2 = cert_v2($fx, $X, $y, $taskSeed, $noEval);
            $certCalls++;
            if ($v2 !== null && $v2['pass']) { $forms[$fx] = 1; }
        }
        if (count($forms) >= $GLOBALS['FORMS_PER_ISLAND']) { break; }
    }
    return array_keys($forms);
}

/** LEVEL B literal rule (prereg section 2): one non-leaf subtree -> '_' at a
 *  time + the fully wildcarded case; leaf terms generate none. */
function templatesFor(string $term): array {
    return match (trim($term, '()')) {
        'x0×x1' => ['(x0×_)', '(_×x1)', '(_×_)'],
        'x3×x4²' => ['(x3×_²)', '(_×x4²)', '(_×_²)', '(_²)'],
        default => [],
    };
}

/** Wildcard instantiation candidates: columns in order, excluding the
 *  template's fixed (occupied) variables; multi-wildcard = ordered distinct
 *  pairs; duplicates (x_a×x_a) excluded. */
function tplCandidates(string $tpl, int $width): \Generator {
    preg_match_all('/x(\d+)/', $tpl, $m);
    $occupied = array_map('intval', $m[1]);
    $free = array_values(array_diff(range(0, $width - 1), $occupied));
    $n = substr_count($tpl, '_');
    if ($n === 1) {
        foreach ($free as $a) { yield str_replace('_', 'x' . $a, $tpl); }
        return;
    }
    if ($n === 2) {
        foreach ($free as $a) {
            foreach ($free as $b) {
                if ($a === $b) { continue; }
                $first = preg_replace('/_/', 'x' . $a, $tpl, 1);
                yield str_replace('_', 'x' . $b, $first);
            }
        }
    }
}

function slotTask(int $slotId): array {
    return match ($slotId) {
        1  => [fn (array $r): float => $r[0] * $r[1], 3100],
        2  => [fn (array $r): float => $r[3] * $r[4] * $r[4], 3200],
        3  => [fn (array $r): float => $r[5], 3300],
        11 => [fn (array $r): float => $r[0] * $r[1], 4100],
        12 => [fn (array $r): float => $r[2] * $r[6] * $r[6], 4200],
        13 => [fn (array $r): float => $r[7], 4300],
        21 => [fn (array $r): float => $r[0] * $r[2], 4400],
        22 => [fn (array $r): float => $r[2] * $r[6] * $r[6], 4500],
        23 => [fn (array $r): float => $r[7], 4600],
    };
}
function widthFor(string $task): int { return $task === 'c2' || $task === 'c2p' ? 8 : 6; }

function colPerm(): array {
    $idx = range(0, 7);
    $s = $GLOBALS['PERM_SEED'];
    for ($i = 7; $i > 0; $i--) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
        $j = (int) floor(($s / 0x7FFFFFFF) * ($i + 1));
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $map = [];
    foreach (range(0, 7) as $c) { $map[$c] = $idx[$c]; }
    return $map;
}
function permuteTerm(string $term, array $map): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'x' . $map[(int) $m[1]], $term);
}

$mode = (string) ($argv[1] ?? '');
$outPath = (string) ($argv[2] ?? '');
$t0 = microtime(true);

if ($mode === 'l1build') {
    $seed = (int) $argv[3];
    $labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));
    $lib = []; $l1Flights = 0; $l1Certs = 0;
    foreach (['A' => 1, 'B' => 2, 'C' => 3] as $name => $slotId) {
        [$law, $off] = slotTask($slotId);
        [$Xc1, ] = genData(1, $seed, 200, 6);
        $islandY = array_map($law, $Xc1);
        $forms = warmChain($Xc1, $islandY, $seed + $off, $labels, 'l1' . $name, $l1Flights, $l1Certs);
        $lib[$name] = $forms;
    }
    $row = ['mode' => 'l1build', 'seed' => $seed, 'flights' => $l1Flights, 'lib' => $lib];
    file_put_contents($outPath, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    echo 'L1_DONE seed=' . $seed . ' forms=' . json_encode(array_map('count', $lib)) . "\n";
    exit(0);
}

if ($mode !== 'run') { fwrite(STDERR, "usage: l1build seed | run task arm seed out [l1file]\n"); exit(1); }
$task = (string) $argv[3];
$arm = (string) $argv[4];
$seed = (int) $argv[5];
if (! in_array($task, ['c2', 'c2p'], true) || ! in_array($arm, ['fresh', 'exact', 'templates', 'shuffled'], true)) {
    fwrite(STDERR, "bad task/arm\n"); exit(1);
}

$trueTerms = $task === 'c2' ? ['x0×x1', 'x2×x6²', 'x7'] : ['x0×x2', 'x2×x6²', 'x7'];
$slotIds = $task === 'c2' ? ['A' => 11, 'B' => 12, 'C' => 13] : ['A' => 21, 'B' => 22, 'C' => 23];
$lawId = $task === 'c2' ? 2 : 3;
$width = widthFor($task);

$l1 = null;
if ($arm !== 'fresh') {
    $l1File = $argv[6] ?? dirname(__DIR__) . '/Benchmarks/h2lite_l1_library.jsonl';
    $found = null;
    foreach (file($l1File, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (($r['mode'] ?? '') === 'l1build' && (int) $r['seed'] === $seed) { $found = $r['lib']; }
    }
    if ($found === null) { fwrite(STDERR, "L1_MISSING seed=$seed\n"); exit(3); }
    $exact = []; $templates = [];
    foreach ($found as $name => $forms) {
        foreach ($forms as $f) {
            $exact[] = $f;
            // prereg: templates exist ONLY in the templates/shuffled arms;
            // the exact arm reproduces EXP-053 (exact-terms-only attempts)
            if ($arm === 'templates' || $arm === 'shuffled') {
                foreach (templatesFor($f) as $tp) { $templates[] = $tp; }
            }
        }
    }
    if ($arm === 'shuffled') {
        $map = colPerm();
        $exact = array_map(fn (string $t): string => permuteTerm($t, $map), $exact);
        $templates = array_map(fn (string $t): string => permuteTerm($t, $map), $templates);
        foreach (array_merge($exact, $templates) as $t) {
            if (in_array($t, $trueTerms, true)) { fwrite(STDERR, "PERM_COLLISION: $t\n"); exit(4); }
        }
    }
    $l1 = ['exact' => $exact, 'templates' => $templates];
}

$labels = array_map(fn (int $i): string => "c{$i}", range(0, $width - 1));
[$Xtask, $ytask] = genData($lawId, $seed, 200, $width);

$flights = 0; $certCalls = 0; $instCalls = 0;
$attemptTried = 0; $attemptAdopted = 0;

/** Try L1 candidates on one receiving slot, TIERED (prereg amendment):
 *  T1 = exact terms (2 anchors), T2 = templates with exactly 1 fixed var,
 *  T3 = fully-wildcarded. First cert_v2 PASS = adopted; per-slot cap 40
 *  instantiation cert_v2 calls (starved -> chain fallback). Returns
 *  [adopted|null, tier|null, starved]. */
$trySlot = function (array $Xs, array $Ys, int $taskSeed, ?array $l1) use ($width, &$certCalls, &$instCalls, &$attemptTried, &$attemptAdopted): array {
    if ($l1 === null) { return [null, null, false]; }
    $triedSet = [];
    $instSlot = 0;
    $starved = false;
    foreach ($l1['exact'] as $term) { // T1
        $key = trim($term, '()');
        if (isset($triedSet[$key])) { continue; }
        $triedSet[$key] = 1;
        $attemptTried++;
        $noEval = null;
        $v2 = cert_v2($term, $Xs, $Ys, $taskSeed, $noEval);
        $certCalls++;
        if ($v2 !== null && $v2['pass']) { $attemptAdopted++; return [$term, 'T1', false]; }
    }
    $t2 = []; $t3 = [];
    foreach ($l1['templates'] as $tpl) {
        preg_match_all('/x(\d+)/', $tpl, $m);
        $anchors = count(array_unique($m[1]));
        if ($anchors >= 1) { $t2[] = $tpl; } else { $t3[] = $tpl; }
    }
    fwrite(STDERR, 'TRY slot: exact=' . count($l1['exact']) . ' templates=' . count($l1['templates']) . " t2=" . count($t2) . " t3=" . count($t3) . "\n");
    foreach (['T2' => $t2, 'T3' => $t3] as $tier => $tpls) {
        foreach ($tpls as $tpl) {
            foreach (tplCandidates($tpl, $width) as $cand) {
                if ($instSlot >= 40) { $starved = true; return [null, $tier, true]; }
                $key = trim($cand, '()');
                if (isset($triedSet[$key])) { continue; }
                $triedSet[$key] = 1;
                $attemptTried++; $instCalls++; $instSlot++;
                $noEval = null;
                $v2 = cert_v2($cand, $Xs, $Ys, $taskSeed, $noEval);
                $certCalls++;
                if ($v2 !== null && $v2['pass']) { $attemptAdopted++; return [$cand, $tier, false]; }
            }
        }
    }
    return [null, null, $starved];
};

$pools = []; $islandInfo = []; $tiers = []; $starvedSlots = 0;
foreach ($slotIds as $name => $slotId) {
    [$law, $off] = slotTask($slotId);
    $islandY = array_map($law, $Xtask);
    $taskSeed = $seed + $off;
    $tryRes = ($arm !== 'fresh') ? $trySlot($Xtask, $islandY, $taskSeed, $l1) : [null, null, false];
    [$adopted, $tier, $starved] = $tryRes;
    if ($starved) { $starvedSlots++; }
    if ($tier !== null) { $tiers[] = $tier; }
    $chainForms = [];
    if ($adopted === null) {
        $chainForms = warmChain($Xtask, $islandY, $taskSeed, $labels, 's' . $slotId . '_' . $seed, $flights, $certCalls);
    }
    $pools[$name] = $adopted !== null ? [$adopted] : $chainForms;
    $islandInfo[$name] = ['certs' => count($pools[$name]), 'adopted' => $adopted,
        'adopted_tier' => $adopted !== null ? $tier : null, 'starved' => $starved, 'forms' => $pools[$name]];
}

$combos = 0; $certs = 0; $certForm = null;
foreach ($pools['A'] as $a) {
    foreach ($pools['B'] as $b) {
        foreach ($pools['C'] as $c) {
            $comboForm = '(' . $a . ')+(' . $b . ')+(' . $c . ')';
            $combos++;
            $cv = cv_eval($comboForm, array_slice($Xtask, 160), array_slice($ytask, 160));
            if ($cv !== null && $cv <= 0.10) {
                $noEval = null;
                $v2 = cert_v2($comboForm, $Xtask, $ytask, $seed + 500, $noEval);
                $certCalls++;
                if ($v2 !== null && $v2['pass']) {
                    $certs++; $certForm = $comboForm;
                    break 3;
                }
            }
        }
    }
}
$effort = $flights + $combos;
$row = ['mode' => 'run', 'task' => $task, 'arm' => $arm, 'seed' => $seed,
    'effort' => $effort, 'flights' => $flights, 'combos' => $combos,
    'cert_v2_evaluations' => $certCalls, 'instantiation_attempts' => $instCalls,
    'effort_with_instantiations' => $effort + $certCalls,
    'attempt_tried' => $attemptTried, 'attempt_adopted' => $attemptAdopted,
    'certs_train' => $certs, 'cert_form' => $certForm,
    'adopted_slots' => count(array_filter($islandInfo, fn ($i) => $i['adopted'] !== null)),
    'adopted_tiers' => $tiers, 'starved_slots' => $starvedSlots,
    'islands' => $islandInfo,
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
file_put_contents($outPath, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE task=' . $task . ' arm=' . $arm . ' seed=' . $seed
    . ' effort=' . $effort . ' cert_evals=' . $certCalls . ' ewi=' . $row['effort_with_instantiations']
    . ' adopted=' . $row['adopted_slots'] . ' certs=' . $certs . "\n";
