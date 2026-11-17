<?php
declare(strict_types=1);
// EXP-055: template-enabled capability on OUT-OF-ENVELOPE C_deep (prereg:
// Benchmarks/EXP055_capability/exp055_prereg.md + amendments 1-3).
// C_deep = (x0*x1)*(x2*x3) + x4, sigma=0, width 5. Fresh = E39 0-certs.
// Arms differ ONLY in rung material written to the road before chains
// (ONE php process per run - trail isolation retained):
//   fresh:  no rungs
//   exact:  L1 exact terms (x0x1, x3x4^2, x5) as rungs
//   templates: ALL Level-B instantiations of L1 (failed-cert included!)
//   shuffled: pi-permuted garbage versions of the templates set
// rung-write via reflection on Search::writeTrailMarks (smoke-verified:
// written=2/2, admitted past noise-guard via INF bestCvSeen).
// Ledger: every instantiation attempt + cert outcome appended to
// exp055_rung_ledger.jsonl (frozen before the consumption block - amendment 2).
// consumption arm (separate mode, runs LAST reading the frozen ledger):
//   'consumption' = write ORDER prioritized by ledger pheromone (M+1)/(N+2).
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Core\ExpressionEvaluator;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');
require_once __DIR__ . '/exp045/cert_utils.php';

const WIDTH = 5;
const WARM_LADDERS = 8;

function genDeep(int $seed, int $n): array {
    $s = $seed + 7777; // distinct data lineage from C1/C2 cameras
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = fn (float $a, float $b): float => $a + $lcg() * ($b - $a);
    $X = []; $y = [];
    for ($i = 0; $i < $n; $i++) {
        $r = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $y[] = ($r[0] * $r[1]) * ($r[2] * $r[3]) + $r[4];
        $X[] = $r;
    }
    return [$X, $y];
}

function ladder(int $i, string $salt): array {
    return ['id' => 'route_' . substr(sha1($salt . '_' . $i), 0, 8), 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}

/** Level-B template set of L1 exact terms, instantiated over width columns. */
function templatesFor(string $term): array {
    return match (trim($term, '()')) {
        'x0×x1' => ['(x0×_)', '(_×x1)', '(_×_)'],
        'x3×x4²' => ['(x3×_²)', '(_×x4²)', '(_×_²)', '(_²)'],
        'x5' => [], // leaf: nothing to generalize
        default => [],
    };
}

/** Instantiate a template over columns 0..width-1; dedup commutative pairs. */
function tplCandidates(string $tpl, int $width): array {
    preg_match_all('/x(\d+)/', $tpl, $m);
    $occupied = array_map('intval', $m[1]);
    $free = array_values(array_diff(range(0, $width - 1), $occupied));
    $n = substr_count($tpl, '_');
    $out = [];
    if ($n === 1) {
        foreach ($free as $a) { $out[] = str_replace('_', 'x' . $a, $tpl); }
    } elseif ($n === 2) {
        $seenPair = [];
        foreach ($free as $a) {
            foreach ($free as $b) {
                if ($a === $b) { continue; }
                $first = preg_replace('/_/', 'x' . $a, $tpl, 1);
                $f = str_replace('_', 'x' . $b, $first);
                $key = implode('+', array_unique([$a, $b])) . '|' . preg_replace('/\d+/', '#', $f) . '#' . substr_count($f, '²');
                if (isset($seenPair[$key])) { continue; }
                $seenPair[$key] = 1;
                $out[] = $f;
            }
        }
    }
    return $out;
}

/** x-space form -> label-space form (inverse of labels_to_x mapping used in probes). */
function xToLabels(string $f): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'c' . $m[1], $f);
}

/** Write rungs into the road via reflection (chunks of <=5 plausible). */
function writeRungs(array $forms, array $labels, array $X, array $y, int &$writtenCalls): int {
    if ($forms === []) { return 0; }
    $plaus = array_map(fn (string $f): array => ['name' => xToLabels($f), 'corr' => 0.0], $forms);
    $rm = new ReflectionMethod(Search::class, 'writeTrailMarks');
    $rm->setAccessible(true);
    $total = 0;
    foreach (array_chunk($plaus, 5) as $chunk) {
        $rm->invoke(null, $labels, $chunk, INF, INF, [], [], $X, $y);
        $writtenCalls++;
        $total += count(Search::$lastTrailWritten ?? []);
    }
    return $total;
}

$mode = (string) ($argv[1] ?? '');
$outPath = (string) ($argv[2] ?? '');
$seed = (int) ($argv[3] ?? 0);

// ---- L1 (from EXP-053 library, forms identical across sigma=0 seeds) ----
function loadL1(string $libFile, int $seed): array {
    foreach (file($libFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (($r['mode'] ?? '') === 'l1build' && (int) $r['seed'] === $seed) {
            $all = [];
            foreach ($r['lib'] as $forms) { $all = array_merge($all, $forms); }
            return $all;
        }
    }
    fwrite(STDERR, "L1_MISSING seed=$seed\n"); exit(3);
}

$libFile = $argv[4] ?? dirname(__DIR__) . '/Benchmarks/h2lite_l1_library.jsonl';

if ($mode === 'l1build') {
    exit(0); // L1 covers seeds 401-415 already; dev seeds 451-465 built by caller via h2lite_pilot l1build
}

$arm = (string) ($argv[5] ?? 'fresh');
if (! in_array($arm, ['fresh', 'exact', 'templates', 'shuffled', 'consumption'], true)) {
    fwrite(STDERR, "bad arm ($arm)\n"); exit(1);
}

[$Xdeep, $ydeep] = genDeep($seed, 200);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, WIDTH - 1));
$targetForm = '((x0×x1)×(x2×x3))+x4';
$t0 = microtime(true);

// ---- candidate rung material per arm ----
$cands = [];
switch ($arm) {
    case 'fresh':
        break;
    case 'exact':
        $cands = loadL1($libFile, $seed); // x0×x1, x3×x4², x5 (true-organ rung only)
        break;
    case 'templates':
        foreach (loadL1($libFile, $seed) as $l1t) {
            foreach (templatesFor($l1t) as $tpl) { $cands = array_merge($cands, tplCandidates($tpl, WIDTH)); }
        }
        break;
    case 'shuffled':
        $map = []; $idx = range(0, WIDTH - 1);
        $ps = 424242;
        for ($i = WIDTH - 1; $i > 0; $i--) { $ps = ($ps * 1103515245 + 12345) & 0x7FFFFFFF; $j = (int) floor(($ps / 0x7FFFFFFF) * ($i + 1)); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
        foreach (range(0, WIDTH - 1) as $c) { $map[$c] = $idx[$c]; }
        foreach (loadL1($libFile, $seed) as $l1t) {
            $pt = (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'x' . $map[(int) $m[1]], $l1t);
            foreach (templatesFor($l1t) as $tpl) { $cands = array_merge($cands, tplCandidates($pt, WIDTH)); }
        }
        break;
    case 'consumption':
        // ledger pheromone order: (M+1)/(N+2) desc, cold-start Laplace; reads the
        // frozen ledger rows accumulated by the earlier arms' phase-1 runs.
        $ledgerFile = $argv[6] ?? dirname(__DIR__) . '/Benchmarks/exp055_rung_ledger.jsonl';
        if (! is_file($ledgerFile)) { fwrite(STDERR, "LEDGER_MISSING\n"); exit(5); }
        $ph = [];
        foreach (file($ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (($r['shape'] ?? '') === '') { continue; }
            $key = $r['shape'];
            $ph[$key] = $ph[$key] ?? ['shape' => $key, 'N' => 0, 'M' => 0];
            $ph[$key]['N'] += (int) ($r['attempted'] ?? 1);
            $ph[$key]['M'] += (int) ($r['cert_pass'] ?? 0);
        }
        uasort($ph, fn (array $a, array $b): int => (($b['M'] + 1) / ($b['N'] + 2)) <=> (($a['M'] + 1) / ($a['N'] + 2)));
        foreach (array_keys($ph) as $shape) { $cands[] = $shape; }
        break;
}

// ---- ledger recording: every instantiation attempt + cert outcome (phase-1 data) ----
$ledger = [];
$noEval = null;
foreach ($cands as $cand) {
    $v2 = cert_v2($cand, $Xdeep, $ydeep, $seed + 900, $noEval);
    $ledger[] = ['shape' => $cand, 'attempted' => 1, 'cert_pass' => ($v2 !== null && $v2['pass']) ? 1 : 0, 'seed' => $seed];
    $cert_evals = ($cert_evals ?? 0) + 1;
}
$cert_evals = $cert_evals ?? 0;

// ---- ROAD WRITE: ALL candidate rungs (failed-cert included - prereg section 2) ----
$writeCalls = 0;
$writtenCopies = writeRungs($cands, $labels, $Xdeep, $ydeep, $writeCalls);

// ---- warm chains on the deep composite: the composition test ----
$flights = 0; $certs = 0; $certForm = null;
for ($i = 0; $i < WARM_LADDERS; $i++) {
    $res = RouteExecutor::run(ladder($i, 'deep' . $seed), $Xdeep, $ydeep, $labels, 12.0, 0.10, [], 1);
    $flights++;
    if ($res['found']) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval2 = null;
        $v2 = cert_v2($fx, $Xdeep, $ydeep, $seed + 600, $noEval2);
        if ($v2 !== null && $v2['pass']) {
            $certs++; $certForm = $fx;
            break; // terminal capability event
        }
    }
}
// consumed rungs = candidate rungs appearing as substrings of the cert form
$consumed = [];
foreach ($cands as $cand) {
    $ct = trim($cand, '()');
    if ($ct !== '' && str_contains((string) $certForm, $ct)) { $consumed[] = $ct; }
}

$row = ['mode' => 'run', 'task' => 'cdeep', 'arm' => $arm, 'seed' => $seed,
    'certs_C_deep' => $certs, 'cert_form' => $certForm,
    'flights' => $flights, 'rungs_written' => count($cands), 'road_writes' => $writtenCopies,
    'cert_v2_evaluations' => $cert_evals ?? count($ledger),
    'consumed_rungs' => $consumed, 'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
if ($ledger !== []) {
    foreach ($ledger as $lr) {
        file_put_contents(dirname(__DIR__) . '/Benchmarks/exp055_rung_ledger.jsonl', json_encode($lr, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}
file_put_contents($outPath, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE task=cdeep arm=' . $arm . ' seed=' . $seed . ' certs=' . $certs
    . ' rungs=' . count($cands) . ' consumed=' . count($consumed) . ' wall_ms=' . $row['wall_ms'] . "\n";
