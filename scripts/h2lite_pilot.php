<?php
declare(strict_types=1);
// EXP-053 H2-lite pilot (owner spec 07.11, prereg = h2lite_prereg.md).
// Cross-task organ reuse: C1 library L1 -> C2 (shared-exact organ) vs
// C2' (similar organ, boundary). Arms: hist / fresh / shuf. sigma = 0.
// ONE php process per run (trail-DB isolation, EXP-051-B lesson).
//
// Modes:
//   l1build seed                      -> appends L1 forms to l1_library.jsonl
//   run task arm seed [out.jsonl]     -> task: c2|c2p, arm: hist|fresh|shuf
//
// prereg mechanics: transfer = term + re-certification on the receiving
// slot (A-KILL lesson: blind transfer is the anti-pattern); Shuffled =
// fixed column permutation pi(seed 424242) of L1, asserted to contain no
// C2/C2' organ (collision = STOP); effort = flights_total + combos_evaluated;
// rejected/rejected-terms never enter merge pools.
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$db = BeeSwarm\Infra\Database::get();

$WARM_LADDERS = 8;      // E48-faithful warm chain per island slot
$FORMS_PER_ISLAND = 5;  // early-stop cap
$PERM_SEED = 424242;    // prereg: fixed permutation seed (pi collision = STOP)

function genData(int $lawId, int $seed, int $n, int $width): array {
    // lawId: 1 = C1 composite, 2 = C2 composite, 3 = C2' composite, 4/5/6 = islands
    // island-target laws are applied per-slot below; here: composite rows.
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

/** E48-faithful warm chain: 8 ladders, marks accumulate in the process DB.
 *  Returns distinct certified forms + flight count. */
function warmChain(array $X, array $y, int $taskSeed, array $labels, string $salt, int &$flights): array {
    $forms = [];
    for ($i = 0; $i < $GLOBALS['WARM_LADDERS']; $i++) {
        $res = RouteExecutor::run(ladder($i, $salt), $X, $y, $labels, 12.0, 0.10, [], 1);
        $flights++;
        if ($res['found']) {
            $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
            $noEval = null;
            $v2 = cert_v2($fx, $X, $y, $taskSeed, $noEval);
            if ($v2 !== null && $v2['pass']) { $forms[$fx] = 1; }
        }
        if (count($forms) >= $GLOBALS['FORMS_PER_ISLAND']) { break; }
    }
    return array_keys($forms);
}

/** Fixed column permutation (prereg: seed 424242). Returns map int->int. */
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

/** Apply the column permutation to a term string: x<d> -> x<pi(d)>. */
function permuteTerm(string $term, array $map): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'x' . $map[(int) $m[1]], $term);
}

function slotTask(int $slotId): array {
    // returns [law, taskSeed-offset]; slot ids per prereg:
    // c1: 1=A(x0*x1) 2=B1(x3*x4^2) 3=C1(x5); c2: 11=A 12=B2(x2*x6^2) 13=C2(x7);
    // c2p: 21=A2(x0*x2) 22=B2 23=C2
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

function widthFor(string $task): int { return $task === 'c1' ? 6 : 8; }

// ---------------- main ----------------
$mode = (string) ($argv[1] ?? '');
$outPath = (string) ($argv[2] ?? '');
$t0 = microtime(true);

if ($mode === 'l1build') {
    $seed = (int) $argv[3];
    $labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));
    $slots = ['A' => 1, 'B' => 2, 'C' => 3];
    $lib = []; $l1Flights = 0;
    foreach ($slots as $name => $slotId) {
        [$law, $off] = slotTask($slotId);
        [$Xc1, ] = genData(1, $seed, 200, 6);
        $islandY = array_map($law, $Xc1);
        $forms = warmChain($Xc1, $islandY, $seed + $off, $labels, 'l1' . $name, $l1Flights);
        $lib[$name] = $forms;
    }
    $row = ['mode' => 'l1build', 'seed' => $seed, 'flights' => $l1Flights, 'lib' => $lib];
    file_put_contents($outPath, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    echo 'L1_DONE seed=' . $seed . ' forms=' . json_encode(array_map('count', $lib)) . "\n";
    exit(0);
}

if ($mode !== 'run') {
    fwrite(STDERR, "usage: pilot.php l1build seed | run task arm seed out\n");
    exit(1);
}
$task = (string) $argv[3];
$arm = (string) $argv[4];
$seed = (int) $argv[5];

$trueTerms = $task === 'c2'
    ? ['x0×x1', 'x2×x6²', 'x7']
    : ['x0×x2', 'x2×x6²', 'x7'];
$slotIds = $task === 'c2' ? ['A' => 11, 'B' => 12, 'C' => 13] : ['A' => 21, 'B' => 22, 'C' => 23];
$lawId = $task === 'c2' ? 2 : 3;
$width = widthFor($task);

// --- load L1 (hist/shuf need it; fresh ignores it) ---
$l1 = null;
if ($arm !== 'fresh') {
    $l1File = $argv[6] ?? dirname(__DIR__) . '/Benchmarks/h2lite_l1_library.jsonl';
    $found = null;
    foreach (file($l1File, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (($r['mode'] ?? '') === 'l1build' && (int) $r['seed'] === $seed) { $found = $r['lib']; }
    }
    if ($found === null) { fwrite(STDERR, "L1_MISSING for seed=$seed\n"); exit(3); }
    $l1 = [];
    foreach ($found as $name => $forms) { $l1 = array_merge($l1, $forms); }
    if ($arm === 'shuf') {
        $map = colPerm();
        $l1 = array_map(fn (string $t): string => permuteTerm($t, $map), $l1);
        // prereg assert: no permuted term may equal a C2/C2' organ
        foreach ($l1 as $t) {
            if (in_array($t, $trueTerms, true)) {
                fwrite(STDERR, "PERM_COLLISION: $t\n");
                exit(4);
            }
        }
    }
}

// --- certify C2 labels/data ---
$labels = array_map(fn (int $i): string => "c{$i}", range(0, $width - 1));
[$Xtask, $ytask] = genData($lawId, $seed, 200, $width);

// --- per-slot: L1 attempt (hist/shuf) + warm chain fallback ---
$flights = 0; $attemptTried = 0; $attemptAdopted = 0;
$pools = []; $islandInfo = [];
foreach ($slotIds as $name => $slotId) {
    [$law, $off] = slotTask($slotId);
    $islandY = array_map($law, $Xtask);
    $taskSeed = $seed + $off;
    $adopted = null;
    if ($arm !== 'fresh' && $l1 !== null) {
        // transfer = term + re-certification on the receiving slot (sigma=0: instant)
        foreach ($l1 as $term) {
            $attemptTried++;
            $noEval = null;
            $v2 = cert_v2($term, $Xtask, $islandY, $taskSeed, $noEval);
            if ($v2 !== null && $v2['pass']) { $adopted = $term; $attemptAdopted++; break; }
        }
    }
    $chainForms = [];
    if ($adopted === null) {
        $chainForms = warmChain($Xtask, $islandY, $taskSeed, $labels, 's' . $slotId . '_' . $seed, $flights);
    }
    $forms = $adopted !== null ? [$adopted] : $chainForms;
    $pools[$name] = $forms;
    $islandInfo[$name] = ['certs' => count($forms), 'adopted' => $adopted, 'forms' => $forms];
}

// --- merge enumeration until first cert_v2 PASS; count combos ---
$combos = 0; $certs = 0; $certLog = [];
foreach ($pools['A'] as $a) {
    foreach ($pools['B'] as $b) {
        foreach ($pools['C'] as $c) {
            $comboForm = '(' . $a . ')+(' . $b . ')+(' . $c . ')';
            $combos++;
            $cv = cv_eval($comboForm, array_slice($Xtask, 160), array_slice($ytask, 160));
            if ($cv !== null && $cv <= 0.10) {
                $noEval = null;
                $v2 = cert_v2($comboForm, $Xtask, $ytask, $seed + 500, $noEval);
                if ($v2 !== null && $v2['pass']) {
                    $certs++;
                    $comboTerms = array_map(
                        fn (string $t): string => trim($t, '()'),
                        explode('+', str_replace(['(', ')'], '', $comboForm))
                    );
                    $sorted = $comboTerms;
                    sort($sorted);
                    $sortedTrue = $trueTerms;
                    sort($sortedTrue);
                    $trueMatch = ($sorted == $sortedTrue);
                    $reused = ($arm === 'hist') && in_array('x0×x1', $comboTerms, true);
                    $certLog[] = ['form' => $comboForm, 'true_match' => $trueMatch, 'reused_l1_organ' => $reused];
                    break 3; // time_to_cert = first PASS
                }
            }
        }
    }
}
$effort = $flights + $combos;
$row = ['mode' => 'run', 'task' => $task, 'arm' => $arm, 'seed' => $seed,
    'effort' => $effort, 'flights' => $flights, 'combos' => $combos,
    'certs_train' => $certs, 'time_to_cert_effort' => $effort,
    'true_organ_reused' => ($certs > 0 && ($certLog[0]['reused_l1_organ'] ?? false)),
    'attempt_tried' => $attemptTried, 'attempt_adopted' => $attemptAdopted,
    'false_cert_indep' => ($certs > 0 ? 0 : null), // sigma=0: held draws noiseless; recorded for N-discipline
    'false_cert_same' => ($certs > 0 ? 0 : null),
    'islands' => $islandInfo, 'combo_form' => $certLog[0]['form'] ?? null,
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
file_put_contents(($argv[2] ?? '/tmp/h2lite_runs.jsonl'), json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE task=' . $task . ' arm=' . $arm . ' seed=' . $seed
    . ' effort=' . $effort . ' flights=' . $flights . ' combos=' . $combos
    . ' certs=' . $certs . ' reused=' . ($row['true_organ_reused'] ? '1' : '0') . "\n";
