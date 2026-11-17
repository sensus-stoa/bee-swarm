<?php
declare(strict_types=1);
// EXP-057: composition layer (owner ladder level 4; prereg exp057_prereg.md).
// C_deep' = x0*x2^2 + x3*x4 + x5 (width 6, sigma 0). Deterministic-constructive class:
// composed templates PROD(x_a,_) o SQUARE(_) -> PROD(x_a, SQUARE(x_c)) generate the
// depth-3 organ x_a*x_c^2; synthetic islands (organ's own law) certify the organ;
// certified organ rungs enter the road; the additive chain assembles the composite;
// terminal = cert_v2 pass on the real C_deep' rows.
// Arms: fresh (bare chains) / exact (L1 terms as rungs, no square-in-this-task)
// / composed (the level-4 pipeline) / shufcomposed (pi-permuted organ rungs).
// ONE php process per run (road isolation).
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');
require_once __DIR__ . '/exp045/cert_utils.php';

const WIDTH = 6;
const CHAIN_LADDERS = 8;

function genRows(int $seed, int $n): array {
    $s = $seed + 555; // C_deep' data lineage (disjoint from C1/C2 generations)
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = fn (float $a, float $b): float => $a + $lcg() * ($b - $a);
    $X = [];
    for ($i = 0; $i < $n; $i++) {
        $r = [];
        for ($c = 0; $c < WIDTH; $c++) { $r[] = $u(-2, 2); }
        $X[] = $r;
    }
    return $X;
}
function compositeLaw(array $r): float {
    return $r[0] * $r[2] * $r[2] + $r[3] * $r[4] + $r[5];
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

function writeRungsReflection(array $xForms, array $labels, array $X, array $y): array {
    if ($xForms === []) { return ['writes' => 0, 'admitted' => 0]; }
    $plaus = array_map(fn (string $f): array => ['name' => xToLabels($f), 'corr' => 0.0], $xForms);
    $rm = new ReflectionMethod(Search::class, 'writeTrailMarks');
    $rm->setAccessible(true);
    $written = 0; $calls = 0;
    foreach (array_chunk($plaus, 5) as $chunk) {
        $rm->invoke(null, $labels, $chunk, INF, INF, [], [], $X, $y);
        $calls++;
        $written += count(Search::$lastTrailWritten ?? []);
    }
    return ['writes' => $calls, 'admitted' => $written];
}

function xToLabels(string $f): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'c' . $m[1], $f);
}

$mode = (string) ($argv[1] ?? '');
$seed = (int) ($argv[2] ?? 0);
$arm = (string) ($argv[3] ?? 'fresh');
$outF = (string) ($argv[4] ?? '/tmp/exp057_default.jsonl');
$t0 = microtime(true);

$genRowsOut = genRows($seed, 200);
var_dump('POSTGEN', count($genRowsOut), gettype($genRowsOut[0] ?? null), gettype($genRowsOut));
$Xr = $genRowsOut;
var_dump('POSTDES', count($Xr), gettype($Xr[0] ?? null));
$labels = array_map(fn (int $i): string => "c{$i}", range(0, WIDTH - 1));
$Yreal = array_map('compositeLaw', $Xr);

// ---- rung material per arm ----
$rungX = [];
$stage2 = []; // synthetic-organ certification stage (the positive reference)
if ($arm === 'exact') {
    // EXP-053 semantics: L1 exact terms attempted as rungs (none is a subterm
    // of the C_deep' composite - decoy rungs, the boundary repeated)
    $rungX = ['(x0×x1)', '(x3×x4²)', 'x5'];
} elseif ($arm === 'composed' || $arm === 'shufcomposed') {
    // STAGE 2: composed-template instantiation (x_a * x_c^2, a != c) certified
    // on its OWN synthetic island (organ's law, sigma 0) - the positive
    // reference of the pipeline. The composed shape is NOT reachable by the
    // Level-B enumeration of L1 (needs the SQUARE composed into the product).
    $organs = [];
    foreach (range(0, WIDTH - 1) as $a) {
        foreach (range(0, WIDTH - 1) as $c) {
            if ($a === $c) { continue; }
            $organs[] = "(x{$a}×x{$c}²)";
        }
    }
    $certPool = [];
    foreach ($organs as $oi => $organ) {
        // synthetic island task: rows + the organ's own law via the evaluator
        $s = 7700 + $seed * 31 + $oi;
        $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
        $u = fn (float $a, float $b): float => $a + $lcg() * ($b - $a);
        $Xsyn = []; $Ysyn = [];
        for ($i = 0; $i < 200; $i++) {
            $r = [];
            for ($c = 0; $c < WIDTH; $c++) { $r[] = $u(-2, 2); }
            $Xsyn[] = $r;
        }
        $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($organ, $Xsyn);
        $Ysyn = array_map(fn ($p): float => (float) $p, $vals);
        $noEval = null;
        $v2 = cert_v2($organ, $Xsyn, $Ysyn, $seed + 800 + $oi, $noEval);
        $ok = ($v2 !== null && $v2['pass']);
        $stage2[] = ['organ' => $organ, 'cert_pass' => $ok ? 1 : 0, 'reason' => $v2['reason'] ?? null];
        if ($ok) { $certPool[] = $organ; }
    }
    $rungX = $certPool;
    if ($arm === 'shufcomposed') {
        // pi-permute the certified organ rungs (fixed 424242 permutation) -
        // the control: garbage organ rungs on the road must NOT compose
        $idx = range(0, WIDTH - 1); $ps = 424242;
        for ($i = WIDTH - 1; $i > 0; $i--) { $ps = ($ps * 1103515245 + 12345) & 0x7FFFFFFF; $j = (int) floor(($ps / 0x7FFFFFFF) * ($i + 1)); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
        $rungX = array_map(fn (string $f): string => (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'x' . $idx[(int) $m[1]], $f), $rungX);
    }
}

// ---- rung-write (road) ----
$wr = writeRungsReflection($rungX, $labels, $Xr, array_map('compositeLaw', $Xr));

// ---- chains on C_deep' (8 ladders; terminal cert_v2 on the real rows) ----
$flights = 0; $certs = 0; $certForm = null;
for ($i = 0; $i < CHAIN_LADDERS; $i++) {
    $res = RouteExecutor::run(ladder($i, 'deep57' . $seed), $Xr, $Yreal, $labels, 12.0, 0.10, [], 1);
    $flights++;
    if ($res['found']) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval = null;
        $v2 = cert_v2($fx, $Xr, $Yreal, $seed + 600, $noEval);
        if ($v2 !== null && $v2['pass']) { $certs++; $certForm = $fx; break; }
    }
}

$row = ['mode' => 'run', 'task' => 'cdeep2', 'arm' => $arm, 'seed' => $seed,
    'certs_C_deep2' => $certs, 'cert_form' => $certForm,
    'organ_pool' => count($rungX), 'stage2_pass' => count(array_filter($stage2, fn ($r) => ($r['cert_pass'] ?? 0) === 1)),
    'flights' => $flights, 'road_writes' => $wr['writes'], 'road_admitted' => $wr['admitted'],
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
if ($stage2 !== []) { $row['stage2'] = $stage2; }
file_put_contents($outF, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE arm=' . $arm . ' seed=' . $seed . ' certs=' . $certs
    . ' organ_pool=' . count($rungX) . ' | ' . ($certForm ?? 'NONE') . "\n";
