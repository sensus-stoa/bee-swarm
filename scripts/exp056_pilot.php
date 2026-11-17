<?php
declare(strict_types=1);
// EXP-056: progress-reward stigmergy on C_deep (prereg: Benchmarks/EXP056_progress/).
// Modes:
//   run seed arm libF ledgerF outF   — confirmatory arm run:
//       fresh / exact(3 L1 terms as rungs) / templates(full instantiation dump)
//       shuffled(pi-permuted dump) / progress(top-5 shapes by frozen ledger)
//   probe seed shape libF outF       — isolated single-rung L probe (phase-1)
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');
require_once __DIR__ . '/exp045/cert_utils.php';

const WIDTH = 5;
const CHAIN_LADDERS = 8;

function genDeep(int $seed, int $n): array {
    $s = $seed + 7777;
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

function templatesFor(string $term): array {
    return match (trim($term, '()')) {
        'x0×x1' => ['(x0×_)', '(_×x1)', '(_×_)'],
        'x3×x4²' => ['(x3×_²)', '(_×x4²)', '(_×_²)', '(_²)'],
        'x5' => [],
        default => [],
    };
}

function sweepAll(array $exactTerms, int $width): array {
    $out = [];
    foreach ($exactTerms as $term) {
        foreach (templatesFor($term) as $tpl) {
            preg_match_all('/x(\d+)/', $tpl, $mm);
            $occupied = array_map('intval', $mm[1]);
            $free = array_values(array_diff(range(0, $width - 1), $occupied));
            $n = substr_count($tpl, '_');
            if ($n === 1) {
                foreach ($free as $a) { $out[] = str_replace('_', 'x' . $a, $tpl); }
            } elseif ($n === 2) {
                foreach ($free as $a) {
                    foreach ($free as $b) {
                        if ($a === $b) { continue; }
                        $out[] = str_replace('_', 'x' . $b, preg_replace('/_/', 'x' . $a, $tpl, 1));
                    }
                }
            }
        }
    }
    return $out;
}

function xToLabels(string $f): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'c' . $m[1], $f);
}

function writeRungs(array $xForms, array $labels, array $X, array $y): array {
    if ($xForms === []) { return ['writes' => 0, 'rungs_written' => 0]; }
    $plaus = array_map(fn (string $f): array => ['name' => xToLabels($f), 'corr' => 0.0], $xForms);
    $rm = new ReflectionMethod(Search::class, 'writeTrailMarks');
    $rm->setAccessible(true);
    $written = 0; $calls = 0;
    foreach (array_chunk($plaus, 5) as $chunk) {
        $rm->invoke(null, $labels, $chunk, INF, INF, [], [], $X, $y);
        $calls++;
        $written += count(Search::$lastTrailWritten ?? []);
    }
    return ['writes' => $calls, 'rungs_written' => $written];
}

$mode = (string) ($argv[1] ?? '');
$seed = (int) ($argv[2] ?? 0);
$arm = (string) ($argv[3] ?? '');
$libF = (string) ($argv[4] ?? '');
$ledgerF = (string) ($argv[5] ?? '');
$outF = (string) ($argv[6] ?? '');
$t0 = microtime(true);

[$Xdeep, $ydeep] = genDeep($seed, 200);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, WIDTH - 1));
$targetForm = '((x0×x1)×(x2×x3))+x4';

$l1 = null;
if ($arm !== 'fresh') {
    if (! is_file($libF)) { fwrite(STDERR, "L1_MISSING\n"); exit(3); }
    $l1 = null;
    foreach (file($libF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (($r['mode'] ?? '') === 'l1build' && (int) $r['seed'] === $seed) { $l1 = $r['lib']; }
    }
    if ($l1 === null) { fwrite(STDERR, "L1_MISSING seed=$seed\n"); exit(3); }
}
$exactTerms = [];
if ($l1 !== null) { foreach ($l1 as $forms) { $exactTerms = array_merge($exactTerms, $forms); } }

// ---- rung material per arm ----
$writeSet = [];
$ledgerRows = [];
switch ($arm) {
    case 'fresh':
        break;
    case 'exact':
        $writeSet = $exactTerms;
        foreach ($writeSet as $f) {
            $noEval = null;
            $v2 = cert_v2($f, $Xdeep, $ydeep, $seed + 900, $noEval);
            $ledgerRows[] = ['shape' => $f, 'N' => 1, 'M' => (($v2 !== null && $v2['pass']) ? 1 : 0), 'cert_pass' => ($v2 !== null && $v2['pass']) ? 1 : 0, 'seed' => $seed, 'arm' => 'exact'];
        }
        break;
    case 'templates':
        $writeSet = sweepAll($exactTerms, WIDTH);
        break;
    case 'shuffled':
        $writeSet = sweepAll($exactTerms, WIDTH);
        // pi-permute every form (fixed 424242 permutation, width-consistent)
        $idx = range(0, WIDTH - 1); $ps = 424242;
        for ($i = WIDTH - 1; $i > 0; $i--) { $ps = ($ps * 1103515245 + 12345) & 0x7FFFFFFF; $j = (int) floor(($ps / 0x7FFFFFFF) * ($i + 1)); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
        $writeSet = array_map(fn (string $f): string => (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'x' . $idx[(int) $m[1]], $f), $writeSet);
        break;
    case 'progress':
        // top-5 shapes by frozen ledger progress (dev-measured, ctx-shift test)
        if (! is_file($ledgerF)) { fwrite(STDERR, "LEDGER_MISSING\n"); exit(5); }
        $agg = [];
        foreach (file($ledgerF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (($r['shape'] ?? '') === '') { continue; }
            $key = $r['shape'];
            $agg[$key] = $agg[$key] ?? ['prog' => 0.0, 'n' => 0];
            $agg[$key]['prog'] += (float) ($r['progress'] ?? 0.0);
            $agg[$key]['n'] += 1;
        }
        $ranked = [];
        foreach ($agg as $shape => $a) {
            $pher = ($a['prog'] + 0.01) / ($a['n'] + 2);
            $ranked[] = ['shape' => $shape, 'pher' => $pher];
        }
        usort($ranked, fn ($a, $b) => $b['pher'] <=> $a['pher']);
        $top5 = array_slice(array_column($ranked, 'shape'), 0, 5);
        $writeSet = $top5;
        break;
}

// ---- road write (rungs) ----
$wr = writeRungs($writeSet, $labels, $Xdeep, $ydeep);

// ---- chains on C_deep ----
$flights = 0; $certs = 0; $certForm = null;
for ($i = 0; $i < CHAIN_LADDERS; $i++) {
    $res = RouteExecutor::run(ladder($i, 'deep' . $seed), $Xdeep, $ydeep, $labels, 12.0, 0.10, [], 1);
    $flights++;
    if ($res['found']) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval = null;
        $v2 = cert_v2($fx, $Xdeep, $ydeep, $seed + 600, $noEval);
        if ($v2 !== null && $v2['pass']) {
            $certs++; $certForm = $fx;
            break; // terminal
        }
    }
}

$row = ['mode' => 'run', 'task' => 'cdeep', 'arm' => $arm, 'seed' => $seed,
    'certs_C_deep' => $certs, 'cert_form' => $certForm,
    'flights' => $flights, 'rungs_written' => count($writeSet),
    'road_admitted' => $wr['rungs_written'],
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
file_put_contents($outF, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
if ($arm === 'exact' && $ledgerRows !== []) {
    foreach ($ledgerRows as $lr) {
        file_put_contents(dirname($outF) . '/exp056_exact_ledger.jsonl', json_encode($lr, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}
echo 'RUN_DONE seed=' . $seed . ' arm=' . $arm . ' certs=' . $certs . ' | ' . ($certForm === null ? 'NONE' : $certForm) . "\n";
