<?php
declare(strict_types=1);
// EXP-063: diversity-retrieval A/B. Prereg: tests/TrailReadDiversityTest.php (EXP-063 prereg)
// Вопрос: даёт ли cluster-based retrieval (TRAIL_READ_DIVERSITY=1, greedy column-coverage
// + corr fill) pool-hit (c3×c4) -> композит -> cert чаще, чем legacy top-5 по uses?
// Фиксировано: TRAIL_READ_K=5, TRAIL_ADMIT_SQ_FACTOR=1, 16 ladders, cdeep2, beam10nl d=2.
// Факторы: READ = ctrl (uses order) | div (TRAIL_READ_DIVERSITY=1)
//          ADM  = eval (legacy admission) | cvargmin (TRAIL_ADMISSION_MODE=cv_argmin)
// CLI (7-positional): php exp063_diversity_ab.php run SEED READ ADM RUNGS OUT
// RUNGS: decoy (E57/E58: приманки '(x0×x1)','(x3×x4²)','x5') | true (E62-forensics
// канон: истинные rungs '(x0×x2²)','(x3×c4)','x5') | partial (ЧАСТИЧНЫЙ сид:
// '(x0×x2²)','x5' — (x3×c4) должен быть найден; честный тест diversity-retrieval:
// цель появляется в дороге через FLIGHT/merge, у ранкера есть материал и запас).
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Core\ExpressionEvaluator;

putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');
putenv('TRAIL_ADMIT_SQ_FACTOR=1');

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
    // Сид по ОДНОЙ ступени за вызов: ROAD V4 caps (≤2 прочих за вылет) при
    // групповом сиде cull'ят третью ступень (воспроизведено: (x3×c4) вылетает
    // при proxy-порядке x5 > (x3×c4)). Ручной засев = по ступени за вызов.
    foreach ($plaus as $single) {
        $rm->invoke(null, $labels, [$single], INF, INF, [], [], $X, $y);
        $calls++;
        $written += count(Search::$lastTrailWritten ?? []);
    }
    return ['writes' => $calls, 'admitted' => $written];
}

$mode = (string) ($argv[1] ?? '');
if ($mode !== 'run' || count($argv) !== 7) { fwrite(STDERR, "ARGV: usage run SEED READ ADM RUNGS OUT\n"); exit(4); }
$seed = (int) $argv[2];
$readCfg = (string) $argv[3];   // ctrl | div
$admCfg = (string) $argv[4];    // eval | cvargmin
$rungsCfg = (string) $argv[5];  // decoy | true
$outF = (string) $argv[6];
if (!in_array($readCfg, ['ctrl', 'div'], true) || !in_array($admCfg, ['eval', 'cvargmin'], true)
    || !in_array($rungsCfg, ['decoy', 'true', 'partial'], true)) {
    fwrite(STDERR, "CFG: READ=ctrl|div ADM=eval|cvargmin RUNGS=decoy|true|partial only\n"); exit(4);
}
if ($readCfg === 'div') { putenv('TRAIL_READ_DIVERSITY=1'); } else { putenv('TRAIL_READ_DIVERSITY'); putenv('TRAIL_READ_ORDER=uses'); }
if ($admCfg === 'cvargmin') { putenv('TRAIL_ADMISSION_MODE=cv_argmin'); } else { putenv('TRAIL_ADMISSION_MODE'); }
$t0 = microtime(true);

$Xr = genRows($seed, 200);
$Yreal = array_map('compositeLaw', $Xr);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, WIDTH - 1));

// Сид дороги: decoy = семантика E57/E58 (приманки), true = истинные rungs,
// partial = только (x0×x2²)+x5 — (x3×c4) ищется, честный тест retrieval.
$rungSeed = $rungsCfg === 'true'
    ? ['(x0×x2²)', '(x3×x4)', 'x5']
    : ($rungsCfg === 'partial'
        ? ['(x0×x2²)', 'x5']
        : ['(x0×x1)', '(x3×x4²)', 'x5']);
$wr = writeRungsReflection($rungSeed, $labels, $Xr, $Yreal);

$flights = 0; $certs = 0; $certForm = null; $clickLadder = null;
$fBest = null; $cvBest = null; $allRecords = [];
for ($i = 0; $i < LADDERS; $i++) {
    $res = RouteExecutor::run(ladder($i, 'deep63' . $seed), $Xr, $Yreal, $labels, 12.0, 0.10, [], 1);
    $flights++;
    $cv = $res['best_cv_seen'] ?? null;
    if (is_numeric($cv)) { $cvBest = (float) $cv; }
    $allRecords[] = $res['records'] ?? [];
    if (!empty($res['partial_form']) && $res['partial_form'] !== 'none') {
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

// Pool-hit телеметрия (критерий пререга): (c3×c4) и (c0×c2²) в read_injected пулов.
$poolHitC3C4 = 0; $poolHitPartial = 0; $injTotal = 0; $fetchTotal = 0;
foreach ($allRecords as $recs) {
    foreach ($recs as $r) {
        $inj = $r['read_injected'] ?? ($r['trail']['read_injected'] ?? []);
        $fet = $r['read_fetched'] ?? ($r['trail']['read_fetched'] ?? []);
        $injTotal += count($inj); $fetchTotal += count($fet);
        foreach ($inj as $f) {
            if (str_contains((string) $f, '(c3×c4)')) { $poolHitC3C4++; }
            if (str_contains((string) $f, '(c0×c2²)')) { $poolHitPartial++; }
        }
    }
}
$atoms = $fBest === null ? 0 : preg_match_all('/x\d+/', $fBest);
$compHit = ($fBest !== null && str_contains($fBest, 'c3×c4') && $atoms > 0) ? 1 : 0;

$row = ['mode' => 'run', 'task' => 'cdeep2_ab', 'read' => $readCfg, 'adm' => $admCfg, 'rungs' => $rungsCfg,
    'seed' => $seed, 'ladders' => LADDERS,
    'certs_C_deep2' => $certs, 'cert_form' => $certForm, 'click_ladder' => $clickLadder,
    'f_best' => $fBest, 'cv_best' => $cvBest, 'comp_hit' => $compHit,
    'pool_hit_c3c4' => $poolHitC3C4, 'pool_hit_partial' => $poolHitPartial,
    'injected_total' => $injTotal, 'fetched_total' => $fetchTotal,
    'admission_mode' => $admCfg === 'cvargmin' ? 'cv_argmin' : 'eval',
    'sq_factor' => '1', 'is_constant' => ($fBest === null || $atoms === 0) ? 1 : 0,
    'ladder_records' => $allRecords,
    'flights' => $flights, 'road_writes' => $wr['writes'], 'road_admitted' => $wr['admitted'],
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
file_put_contents($outF, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE read=' . $readCfg . ' adm=' . $admCfg . ' rungs=' . $rungsCfg . ' seed=' . $seed
    . ' certs=' . $certs . ' pool_c3c4=' . $poolHitC3C4 . ' comp=' . $compHit
    . ' cv_best=' . var_export($cvBest, true) . ' | ' . ($certForm ?? 'NONE') . "\n";
