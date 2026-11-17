<?php
declare(strict_types=1);
// EXP-058: relevance-gated road admission. Prereg: Benchmarks/EXP058_road_gate/exp058_prereg.md
// Question: was the E54/E55/E57 negative stack a k=5 read-buffer displacement artifact?
// CLI (4-positional, asserted): php exp058_pilot.php run SEED ARM OUT
// Arms: fresh | exact | composed | composed_gated | shufcomposed_gated
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');

const WIDTH = 6;
const CHAIN_LADDERS = 8;
const GATE_K = 3;

function genRows(int $seed, int $n): array {
    $s = $seed + 555;
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
function xToLabels(string $f): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'c' . $m[1], $f);
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
function pearson(array $a, array $b): float {
    $n = min(count($a), count($b));
    if ($n < 3) { return NAN; }
    $ma = array_sum($a) / $n; $mb = array_sum($b) / $n;
    $cov = 0.0; $va = 0.0; $vb = 0.0;
    for ($i = 0; $i < $n; $i++) { $da = $a[$i] - $ma; $db = $b[$i] - $mb; $cov += $da * $db; $va += $da * $da; $vb += $db * $db; }
    if ($va <= 0.0 || $vb <= 0.0) { return NAN; }
    return $cov / sqrt($va * $vb);
}

$mode = (string) ($argv[1] ?? '');
if ($mode !== 'run' || count($argv) !== 5) { fwrite(STDERR, "ARGV: usage run SEED ARM OUT\n"); exit(4); }
$seed = (int) $argv[2];
$arm = (string) $argv[3];
$outF = (string) $argv[4];
$t0 = microtime(true);

$Xr = genRows($seed, 200);
$Yreal = array_map('compositeLaw', $Xr);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, WIDTH - 1));

$rungX = [];
$stage2 = [];
$gateAdmitted = [];

if ($arm === 'exact') {
    // E57 semantics: L1 decoy rungs, ungated
    $rungX = ['(x0×x1)', '(x3×x4²)', 'x5'];
} elseif ($arm === 'composed' || $arm === 'composed_gated' || $arm === 'shufcomposed_gated' || $arm === 'shufcomposed') {
    // STAGE 2: 30 composed organs (x_a x_c^2, a != c), each certified on its
    // own sigma-0 synthetic island (cert-positive material, as E57)
    $organs = [];
    foreach (range(0, WIDTH - 1) as $a) {
        foreach (range(0, WIDTH - 1) as $c) {
            if ($a === $c) { continue; }
            $organs[] = "(x{$a}×x{$c}²)";
        }
    }
    $certPool = [];
    foreach ($organs as $oi => $organ) {
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
        // string-level pi-permutation: for the UNGATED shuf arm only. Note
        // (amendment 1): this is vacuous as a set operation on the complete
        // family; the arm exists for E57 comparability. The GATED shuf control
        // (shufcomposed_gated) uses unpermuted strings + column-permuted corr.
        $idx = range(0, WIDTH - 1); $ps = 424242;
        for ($i = WIDTH - 1; $i > 0; $i--) { $ps = ($ps * 1103515245 + 12345) & 0x7FFFFFFF; $j = (int) floor(($ps / 0x7FFFFFFF) * ($i + 1)); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
        $rungX = array_map(fn (string $f): string => (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'x' . $idx[(int) $m[1]], $f), $rungX);
    }
    if ($arm === 'composed_gated' || $arm === 'shufcomposed_gated') {
        // THE GATE (prereg + amendment 1): top-3 by |pearson|; for the shuf
        // control the corr is computed on the pi-424242 COLUMN-PERMUTED X
        // (permuting the organ STRINGS is vacuous: a bijection on the complete
        // (a != c) family yields the same set)
        $Xg = $Xr;
        if ($arm === 'shufcomposed_gated') {
            $idx = range(0, WIDTH - 1); $ps = 424242;
            for ($i = WIDTH - 1; $i > 0; $i--) { $ps = ($ps * 1103515245 + 12345) & 0x7FFFFFFF; $j = (int) floor(($ps / 0x7FFFFFFF) * ($i + 1)); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
            $Xg = array_map(function (array $r) use ($idx): array {
                $out = [];
                foreach ($idx as $src) { $out[] = $r[$src]; }
                return $out;
            }, $Xr);
        }
        $scored = [];
        foreach ($rungX as $organ) {
            $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($organ, $Xg);
            if (!is_array($vals)) { continue; }
            $c = pearson(array_map(fn ($p): float => (float) $p, $vals), $Yreal);
            if (is_nan($c)) { continue; }
            $scored[] = ['organ' => $organ, 'abs_corr' => abs($c)];
        }
        usort($scored, fn ($x, $y2) => $y2['abs_corr'] <=> $x['abs_corr']); // stable: ties keep (a,c) order
        $rungX = array_slice(array_column($scored, 'organ'), 0, GATE_K);
        $gateAdmitted = $rungX;
    }
}

$wr = writeRungsReflection($rungX, $labels, $Xr, $Yreal);

$flights = 0; $certs = 0; $certForm = null;
for ($i = 0; $i < CHAIN_LADDERS; $i++) {
    $res = RouteExecutor::run(ladder($i, 'deep58' . $seed), $Xr, $Yreal, $labels, 12.0, 0.10, [], 1);
    $flights++;
    if ($res['found']) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval = null;
        $v2 = cert_v2($fx, $Xr, $Yreal, $seed + 600, $noEval);
        if ($v2 !== null && $v2['pass']) {
            $certs++; $certForm = $fx;
            break;
        }
    }
}
$consumed = [];
foreach ($rungX as $cand) {
    $ct = trim($cand, '()');
    if ($ct !== '' && str_contains((string) $certForm, $ct)) { $consumed[] = $ct; }
}

$row = ['mode' => 'run', 'task' => 'cdeep2', 'arm' => $arm, 'seed' => $seed,
    'certs_C_deep2' => $certs, 'cert_form' => $certForm,
    'organ_pool' => count($rungX), 'stage2_pass' => count(array_filter($stage2, fn ($r) => ($r['cert_pass'] ?? 0) === 1)),
    'flights' => $flights, 'road_writes' => $wr['writes'], 'road_admitted' => $wr['admitted'],
    'consumed_rungs' => $consumed,
    'gate_admitted' => $gateAdmitted,
    'gate_hits' => in_array('(x0×x2²)', $gateAdmitted, true) ? 1 : 0,
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
if ($stage2 !== []) { $row['stage2'] = $stage2; }
file_put_contents($outF, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE arm=' . $arm . ' seed=' . $seed . ' certs=' . $certs
    . ' organ_pool=' . count($rungX) . ' gate=' . implode(',', $gateAdmitted)
    . ' | ' . ($certForm ?? 'NONE') . "\n";
