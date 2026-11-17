<?php
declare(strict_types=1);
// EXP-060: residual structure test. Prereg: Benchmarks/EXP060_residual/exp060_prereg.md
// Owner spec verbatim. Dumps f_best (RouteExecutor best_formula_x), cv_best,
// complexity, residual r_i = y_i - f_best(x_i). Arms: exact | fresh. 16 ladders fixed.
// CLI (4-positional, asserted): php exp060_pilot.php run SEED ARM OUT
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');

const WIDTH = 6;
const LADDERS = 16;

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

$mode = (string) ($argv[1] ?? '');
if ($mode !== 'run' || count($argv) !== 5) { fwrite(STDERR, "ARGV: usage run SEED ARM OUT\n"); exit(4); }
$seed = (int) $argv[2];
$arm = (string) $argv[3];
if (!in_array($arm, ['exact', 'fresh'], true)) { fwrite(STDERR, "ARM: exact|fresh only\n"); exit(4); }
$outF = (string) $argv[4];
$t0 = microtime(true);

$Xr = genRows($seed, 200);
$Yreal = array_map('compositeLaw', $Xr);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, WIDTH - 1));

$rungX = [];
if ($arm === 'exact') {
    $rungX = ['(x0×x1)', '(x3×x4²)', 'x5'];
}
$wr = writeRungsReflection($rungX, $labels, $Xr, $Yreal);

$flights = 0; $certs = 0; $certForm = null; $clickLadder = null; $cvSeq = [];
$fBest = null; $cvBest = null; $allRecords = [];
for ($i = 0; $i < LADDERS; $i++) {
    $res = RouteExecutor::run(ladder($i, 'deep59' . $seed), $Xr, $Yreal, $labels, 12.0, 0.10, [], 1);
    $flights++;
    $cv = $res['best_cv_seen'] ?? null;
    $cvSeq[] = is_numeric($cv) ? round((float) $cv, 4) : null;
    $allRecords[] = $res['records'] ?? [];
    if (is_numeric($cv)) { $cvBest = (float) $cv; }
    if (!empty($res['partial_form']) && $res['partial_form'] !== 'none') {
        // EXP-060 f_best source: partial_form = the argmin-cv partial form
        // (WU PARTIAL-EVAL), independent of computeL. best_formula_x is
        // computeL-gated and stays null on out-of-envelope tasks.
        $fBest = (string) $res['partial_form'];
    }
    if ($res['found']) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval = null;
        $v2 = cert_v2($fx, $Xr, $Yreal, $seed + 600, $noEval);
        if ($v2 !== null && $v2['pass']) {
            $certs++; $certForm = $fx; $clickLadder = $i + 1;
            if (is_string($fx)) { $fBest = $fx; $cvBest = 0.0; }
            break;
        }
    }
}

// residual dump vs f_best
$atoms = $fBest === null ? 0 : preg_match_all('/x\d+/', $fBest);
$ops = $fBest === null ? 0 : (substr_count($fBest, '×') + substr_count($fBest, '+') + substr_count($fBest, '−') + substr_count($fBest, '²') + substr_count($fBest, '/'));
$isConstant = ($fBest === null || $atoms === 0);
$residual = null;
if ($fBest !== null && !$isConstant) {
    $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($fBest, $Xr);
    if (is_array($vals)) {
        $residual = [];
        foreach ($Yreal as $i => $yv) { $residual[] = round($yv - (float) $vals[$i], 6); }
    }
}

$row = ['mode' => 'run', 'task' => 'cdeep2', 'arm' => $arm, 'seed' => $seed, 'ladders' => LADDERS,
    'certs_C_deep2' => $certs, 'cert_form' => $certForm, 'click_ladder' => $clickLadder,
    'f_best' => $fBest, 'cv_best' => $cvBest,
    'admission_mode' => getenv('TRAIL_ADMISSION_MODE') ?: 'eval',
    'sq_factor' => getenv('TRAIL_ADMIT_SQ_FACTOR') ?: '0',
    'best_form_complexity' => $atoms + $ops, 'is_constant' => $isConstant ? 1 : 0,
    'residual' => $residual,
    'ladder_records' => $allRecords,
    'flights' => $flights, 'road_writes' => $wr['writes'], 'road_admitted' => $wr['admitted'],
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
file_put_contents($outF, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE arm=' . $arm . ' seed=' . $seed . ' certs=' . $certs
    . ' cv_best=' . var_export($cvBest, true) . ' f_best=' . ($fBest ?? 'NULL')
    . ' complexity=' . ($atoms + $ops) . ' | ' . ($certForm ?? 'NONE') . "\n";
