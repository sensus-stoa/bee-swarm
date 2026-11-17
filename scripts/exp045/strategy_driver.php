<?php

// STRATEGY-TRANSFER campaign driver (prereg rev.5 FROZEN sha256 a9d99a90,
// MANIFEST.txt 02.11). Кампан-драйвер WU: фазы evolve / replayC / freeze /
// crosseval / compare / calibration. Склеено из exp045/driver.php (канал
// baseline, ledger, cert_utils), RouteMutator (0-8), SensorState (W-window),
// BudgetAccountant (A/C-бюджет, rev.5 edit 1).
//
// Слот = task x seed. Arms: A (эволюция с CLASS_INTEGRATOR-гейтом), B
// (no-strategy baseline), C (always-on replay того же генома, budget =
// A.execution_actual, BudgetAccountant::forC). Primary = novel-cert
// (дедуп fingerprint формы в пределах прогона), exact McNemar на парных
// слотах (A/B, A/C, C/B). TOTAL-бюджет равный: baseline 8s + routes 32s.
//
// Каналы детерминированы per-channel reseed (baseline/routes независимы от
// порядка потребления mt_rand — baseline байт-идентичен между A и B).
//
// Usage: php strategy_driver.php <phase> <arm> <seed> <familiesJson> <outDir>
//   phase: evolve (arm A|B) | replayC (arm C) | freeze | crosseval (arm A|B) | compare | calibration
// Env: EXP045_TASK_BUDGET (default 40), STRAT_POOL_OVERRIDE (TEST-ONLY,
//      comma list of task ids; кампания бегает без него), STRAT_DONOR_BUDGET_CAP.
// Кампания (90 слотов) — только по явному слову owner.

require_once __DIR__ . '/cert_utils.php';

use BeeSwarm\Hive\BudgetAccountant;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteLedger;
use BeeSwarm\Hive\RouteMutator;
use BeeSwarm\Hive\SensorState;
use BeeSwarm\Hive\TransferStats;

$phase = $argv[1] ?? '';
$arm = $argv[2] ?? '';
$seed = (int) ($argv[3] ?? 0);
$famJson = $argv[4] ?? '';
$outDir = $argv[5] ?? '/tmp/strategy_campaign';
if ($famJson === '' || ! is_file($famJson)) {
    fwrite(STDERR, "usage: strategy_driver.php <phase> <arm> <seed> <familiesJson> <outDir>\n");
    exit(2);
}
@mkdir($outDir, 0777, true);

// ---- FROZEN campaign constants (code-freeze MANIFEST; правка = новый фриз) ----
const STRAT_TOTAL_TASK_BUDGET = 40.0; // sec per slot (Stage A precedent)
const STRAT_BASELINE_SHARE = 0.2;     // baseline 8s / routes 32s
const STRAT_POP_N = 8;
const STRAT_ALPHA = 0.3;   // integrator alpha (frozen before campaign, prereg §2)
const STRAT_THETA = 0.05;  // activation threshold
const STRAT_N_MIN = 3;     // min FLIGHT observations before decision != UNKNOWN
const STRAT_W = 5;         // sensor W-window (last W applications of FLIGHT)
const STRAT_DONOR_SEEDS = [101, 102, 103];
const STRAT_CLASSES = ['SUM', 'PRODUCT', 'RATIO'];
const STRAT_MC_NEMAR_ALPHA = 0.05;
// Evolution pool: prereg rev.2.1 canon (D1-D6 + DOT4 + benchmarks = 10 tasks,
// 10 x 3 seeds = 30 slots/arm, 90 slots total). RATIO2/RATIO3 = cross-eval
// recipients (class-table RATIO>=3 satisfied: D3+D1+RATIO2+RATIO3).
const STRAT_POOL = ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'DOT4', 'dot', 'kinetic', 'heat'];
// Cross-eval: 3 fresh recipient instances per ordered class pair (prereg §3:
// 3 инстанса = 3 разные задачи-реципиента; fresh seeds = independence).
const STRAT_CROSS_RECIPIENT_N = 3;

$TASK_BUDGET = (float) (getenv('EXP045_TASK_BUDGET') !== false ? getenv('EXP045_TASK_BUDGET') : (string) STRAT_TOTAL_TASK_BUDGET);

$params = json_decode(file_get_contents($famJson), true);
if (! is_array($params)) {
    fwrite(STDERR, "bad families json\n");
    exit(2);
}
$taskById = [];
foreach ($params['tasks'] as $t) {
    $taskById[(string) $t['id']] = $t;
}
$benchClass = $params['_benchmarks'] ?? [];
$classMap = $params['_class_mapping'] ?? [];
// task id -> campaign class label (SUM/PRODUCT/RATIO)
$classOfTask = function (string $id) use ($taskById, $benchClass, $classMap): ?string {
    if (isset($benchClass[$id])) {
        return (string) $benchClass[$id];
    }
    if (! isset($taskById[$id])) {
        return null;
    }
    $raw = (string) $taskById[$id]['class'];
    foreach ($classMap as $label => $raws) {
        if (in_array($raw, $raws, true)) {
            return (string) $label;
        }
    }
    return null;
};

$poolOverride = getenv('STRAT_POOL_OVERRIDE');
$pool = $poolOverride !== false && $poolOverride !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $poolOverride))))
    : STRAT_POOL;

// ---- task materialization (same LCG as exp045/driver.php + probe_loop) ----
function gen_family(array $p, string $path): void
{
    $s = $p['seed'];
    $r = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

        return $s / 0x7FFFFFFF;
    };
    $u = function (float $a, float $b) use ($r): float {
        return $a + $r() * ($b - $a);
    };
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
        if (($p['class'] ?? '') === 'dot4') {
            $c[] = $u(0.5, 4);
        }
        switch ($p['class']) {
            case 'flux': $y = 2.0 * ($c[2] - $c[1]) * $c[0] / $c[3];
                break;
            case 'diffprod': $y = 1.5 * ($c[1] - $c[0]) * $c[0];
                break;
            case 'ratio': $y = ($c[0] * $c[1]) / $c[3];
                break;
            case 'composition': $y = ($c[0] - $c[1]) * ($c[0] - $c[1]) + $c[2] * $c[3];
                break;
            case 'power': $y = $c[0] * $c[0] + $c[1] * $c[2];
                break;
            case 'pureprod': $y = $c[0] * $c[3];
                break;
            case 'dot4': $y = $c[0] * $c[3] + $c[1] * $c[4] + $c[2] * $c[5] + $c[3] * $c[5];
                break;
            default: $y = 0.0;
        }
        if (! empty($p['noise'])) {
            $y *= 1 + ($r() - 0.5) * $p['noise'];
        }
        $row = [];
        foreach ($p['perm'] as $src) {
            $row[] = $c[$src];
        }
        $row[] = $y;
        $rows[] = $row;
    }
    $f = fopen($path, 'w');
    foreach ($rows as $r2) {
        fputcsv($f, $r2);
    }
    fclose($f);
}

// GT forms frozen by prereg §1 table; dot generator = probe_loop dot_task
// (canonical positive reference, u(-2,2), 6 features); kinetic = x0*x1^2,
// heat = x0*x1*x2 (prereg table). Same LCG style, seed-derived.
function gen_benchmark(string $id, int $seed, string $path): void
{
    $s = $seed;
    $r = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

        return $s / 0x7FFFFFFF;
    };
    $u = function (float $a, float $b) use ($r): float {
        return $a + $r() * ($b - $a);
    };
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        if ($id === 'dot') {
            $row[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
        } elseif ($id === 'kinetic') {
            // prereg §1: kinetic GT = x0 * x1^2 (features 0,1; rest = distractors)
            $row[] = $row[0] * $row[1] * $row[1];
        } elseif ($id === 'heat') {
            // prereg §1: heat GT = x0 * x1 * x2 (features 0,1,2; rest = distractors)
            $row[] = $row[0] * $row[1] * $row[2];
        } else {
            fwrite(STDERR, "unknown benchmark {$id}\n");
            exit(2);
        }
        $rows[] = $row;
    }
    $f = fopen($path, 'w');
    foreach ($rows as $r2) {
        fputcsv($f, $r2);
    }
    fclose($f);
}

function parse_csv(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $v = str_getcsv($line);
        if (count($v) < 2 || ! is_numeric($v[0])) {
            continue;
        }
        $nFeat = count($v) - 1;
        $X[] = array_map('floatval', array_slice($v, 0, $nFeat));
        $y[] = (float) $v[$nFeat];
    }

    return [$X, $y];
}

// GT-формы (prereg checklist: процитированы до freeze; D-формы = gen_family switch)
function gt_form(string $id): string
{
    return match ($id) {
        'D1' => '2.0*(c2-c1)*c0/c3 (flux)',
        'D2' => '1.5*(c1-c0)*c0 (diffprod)',
        'D3' => '(c0*c1)/c3 (ratio)',
        'D4' => '(c0-c1)^2+c2*c3 (composition)',
        'D5' => 'c0^2+c1*c2 (power)',
        'D6' => 'c0*c3 (pureprod)',
        'DOT4' => 'c0*c3+c1*c4+c2*c5+c3*c5 (dot4)',
        'dot' => 'x0*x3+x1*x4+x2*x5',
        'kinetic' => 'x0*x1^2',
        'heat' => 'x0*x1*x2',
        default => 'unknown',
    };
}

// ---- baseline channel (копия exp045/driver.php, гигиена без меток) ----
function baseline_flights(array $X, array $y, float $budget): array
{
    $t0 = microtime(true);
    $flights = [];
    $found = false;
    $tFound = null;
    $formula = '';
    $bestCv = 9.99;
    $g = BeeSwarm\Core\Grammar::fromOps(array_keys(BeeSwarm\Core\Grammar::BASE_OPS));
    for ($f = 1; $f <= 3; $f++) {
        $remain = $budget - (microtime(true) - $t0);
        if ($remain < 2.0) {
            break;
        }
        putenv('TRAIL_MARKS');
        $nFeat = count($X[0]);
        $lbls = array_map(fn (int $i): string => "c{$i}", range(0, $nFeat - 1));
        $r = BeeSwarm\Core\Search::find($X, $y, $g, min(2 + $f - 1, 3), $lbls, 0.2, 0.10, $remain);
        $flights[] = [
            'f' => $f,
            'found' => (bool) $r[0],
            'cv' => is_numeric($r[1]) ? (float) $r[1] : 9.99,
            'formula' => is_string($r[2] ?? null) ? (string) $r[2] : '',
        ];
        if ($r[0]) {
            $found = true;
            $tFound = round(microtime(true) - $t0, 1);
            $formula = (string) $r[2];
            $bestCv = 0.0;
            break;
        }
        if (is_numeric($r[1]) && $r[1] < $bestCv) {
            $bestCv = (float) $r[1];
        }
    }
    putenv('TRAIL_MARKS');

    return [
        'flights' => $flights,
        'found' => $found,
        't_first_found' => $tFound,
        'formula' => $formula,
        'best_cv' => $bestCv,
        'wall_total' => round(microtime(true) - $t0, 1),
    ];
}

/** Arm A: integrator condition generator (frozen params, random class). */
function integratorCondition(): array
{
    return [
        'kind' => RouteGenome::CONDITION_INTEGRATOR_PREFIX . STRAT_CLASSES[array_rand(STRAT_CLASSES)],
        'alpha' => STRAT_ALPHA,
        'theta' => STRAT_THETA,
        'n_min' => STRAT_N_MIN,
    ];
}

/** STRATEGY-C2 arm (i): CLASS_MATCH condition (no theta; n_min paired with A). */
function classMatchCondition(): array
{
    return [
        'kind' => RouteGenome::CONDITION_CLASSMATCH_PREFIX . STRAT_CLASSES[array_rand(STRAT_CLASSES)],
        'n_min' => STRAT_N_MIN,
    ];
}

/** condition generator by arm variant. */
function conditionByVariant(string $variant): array
{
    return $variant === 'classmatch' ? classMatchCondition() : integratorCondition();
}

/** STRATEGY-C2 hand-ladder seed (owner 03-04.11: form presence is the design
 * condition, not the claim). Class rotation covers the ladder pool:
 * dot=SUM, D1=RATIO, heat=PRODUCT. */
function handLadders(array $classes): array
{
    $ladders = [];
    foreach ($classes as $i => $cls) {
        $ladders[] = [
            'id' => 'route_' . substr(sha1('probe_ladder_' . $i), 0, 8),
            'parent_id' => '',
            'condition' => ['kind' => $cls],
            'budget_fraction' => 0.30,
            'max_steps' => 5,
            'actions' => [
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                ['op' => 'USE_MARKS', 'k' => 5],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                ['op' => 'USE_MARKS', 'k' => 5],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ],
        ];
    }

    return $ladders;
}

/** Arm A population seed: Stage A precedent (min 3 actions, bf = max). */
function seedStrategyPopulation(int $n): array
{
    $pop = [];
    for ($i = 0; $i < $n; $i++) {
        $g = RouteMutator::randomGenome();
        $g['condition'] = integratorCondition();
        while (count($g['actions']) < 3) {
            $g['actions'][] = RouteMutator::randomAction();
        }
        $g['max_steps'] = count($g['actions']);
        $g['budget_fraction'] = RouteGenome::BUDGET_MAX;
        $err = RouteGenome::validate($g);
        if ($err !== []) {
            fwrite(STDERR, 'SEED INVALID: ' . json_encode($err) . PHP_EOL);
            exit(3);
        }
        $pop[] = $g;
    }

    return $pop;
}

/** strip runtime stats: genes only (hash covers genes + condition genes). */
function genomeGenes(array $g): array
{
    unset($g['record'], $g['lineage']);

    return $g;
}

function genomeHash(array $g): string
{
    return 'sha256:' . hash('sha256', json_encode(genomeGenes($g)));
}

// ---- slot engine ----
/**
 * Один слот task x seed. Возвращает novel-cert бинарник + полную телеметрию.
 * $attemptPlan: list of {genome, forcedOn} — порядок попыток маршрута.
 * $budgetSec: бюджет канала маршрутов (A: фиксированный канал; C: forC).
 */
function runRouteChannel(
    array $attemptPlan,
    array $X,
    array $y,
    array $labels,
    float $budgetSec,
    ?SensorState $sensor,
    string $taskClass,
    array &$certFingerprints,
    array &$integratorState,
    int $taskSeed = 0,
    ?array $Xall = null,
    ?array $yall = null,
): array {
    $rows = [];
    $routeWall = 0.0;
    $routeActions = 0;
    $novelCert = false;
    $trace = [];
    global $TASK_BUDGET;
    foreach ($attemptPlan as $plan) {
        if ($routeWall >= $budgetSec - 0.5) {
            break;
        }
        $g = $plan['genome'];
        $share = min((float) $g['budget_fraction'] * $TASK_BUDGET, $budgetSec - $routeWall);
        if ($share < 1.0) {
            continue;
        }
        // Gate: arm A = CLASS_INTEGRATOR decision; forced = always on.
        if ($plan['forcedOn']) {
            $decision = ['activated' => true, 'reason' => 'FORCED_ON'];
        } else {
            $cond = $g['condition'];
            $kind = (string) ($cond['kind'] ?? '');
            $isGatable = str_starts_with($kind, RouteGenome::CONDITION_INTEGRATOR_PREFIX)
                || str_starts_with($kind, RouteGenome::CONDITION_CLASSMATCH_PREFIX);
            if (! $isGatable) {
                $decision = ['activated' => false, 'reason' => 'NOT_INTEGRATOR'];
            } else {
                $nObs = 0;
                $yieldT = 0.0;
                if ($sensor !== null) {
                    $t = PHP_INT_MAX - 1; // current end of buffer
                    $st = $sensor->stateWindow('FLIGHT', $t, STRAT_W);
                    $nObs = (int) $st['n'];
                    $yieldT = ($st['status'] === 'OK') ? (float) $st['Y'] : 0.0;
                }
                $gid = (string) $g['id'];
                $prev = (float) ($integratorState[$gid] ?? 0.0);
                $decision = RouteExecutor::conditionDecision($g, [
                    'task_class' => $taskClass,
                    'yield_t' => $yieldT,
                    'prev_state' => $prev,
                    'n_obs' => $nObs,
                ]);
                $integratorState[$gid] = (float) $decision['state'];
            }
        }
        $entry = [
            'id' => $g['id'],
            'gated_reason' => $decision['reason'],
        ];
        if (! $decision['activated']) {
            $entry['activated'] = false;
            $rows[] = $entry;
            $trace[] = $g['id'] . '|gate:' . $decision['reason'];
            continue;
        }
        $res = RouteExecutor::run($g, $X, $y, $labels, $share, 0.10, [], 1);
        $routeWall += $res['wall'];
        $routeActions += count($res['records']);
        $trace[] = $g['id'] . '|run:' . implode(';', array_map(
            fn (array $rec): string => $rec['op'] . ':' . substr((string) ($rec['knobs'] ?? ''), 0, 24),
            $res['records']
        ));
        // Sensor collection: FLIGHT applications (R = route best L_valid, loss;
        // positive yield = improvement per unit wall cost).
        if ($sensor !== null) {
            $prevBest = null;
            foreach ($res['records'] as $rec) {
                if (($rec['op'] ?? '') !== 'FLIGHT') {
                    continue;
                }
                $lv = $rec['l_valid'] ?? null;
                if ($prevBest !== null) {
                    $rAfter = ($lv !== null && $lv < $prevBest) ? (float) $lv : $prevBest;
                    $sensor->addObservation('FLIGHT', (float) $prevBest, $rAfter, max(0.001, (float) $rec['wall_s']));
                }
                if ($lv !== null) {
                    $prevBest = min($prevBest ?? PHP_FLOAT_MAX, (float) $lv);
                }
            }
        }
        // Certification: CANON V2 strict triple split + novel dedup by fp.
        $cert = null;
        if ($res['found']) {
            $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
            $noEvalReason = null;
            // CANON driver.php:556: cert_v2 on the FULL data (Xall/yall), not
            // the train split the search saw — independent withheld folds.
            $certV2 = cert_v2($fx, $Xall ?? $X, $yall ?? $y, $taskSeed, $noEvalReason);
            $cert = $res['found'] && $certV2 !== null && $certV2['pass'];
            if ($cert) {
                $fp = hash('sha256', $fx);
                if (! in_array($fp, $certFingerprints, true)) {
                    $certFingerprints[] = $fp;
                    $novelCert = true;
                    $entry['novel_form'] = $fx;
                }
            }
        }
        $entry['activated'] = true;
        $entry['found'] = (bool) $res['found'];
        $entry['certified'] = (bool) $cert;
        $entry['wall'] = round($res['wall'], 2);
        $entry['unspent'] = round($res['unspent'], 2);
        $entry['stop'] = $res['stop'];
        $rows[] = $entry;
    }

    return [
        'rows' => $rows,
        'trace' => $trace,
        'route_wall' => round($routeWall, 3),
        'route_actions' => $routeActions,
        'novel_cert' => $novelCert,
    ];
}

/**
 * Baseline channel + (optional) route channel for ONE slot.
 */
function runSlot(
    string $arm,
    array $task,
    string $taskClass,
    int $seed,
    int $slotIdx,
    RouteLedger $ledger,
    ?SensorState $sensor,
    array &$integratorState,
    array &$certFingerprints,
    bool $forceGates,
    float $routeBudgetOverride,
    ?array $attemptPlan = null,
): array {
    [$Xall, $yall] = [$task['_X'], $task['_y']];
    [$X, $y, $Xe, $ye] = split_eval($Xall, $yall, (int) $task['seed'] + $seed);
    $nFeat = count($X[0]);
    $labels = array_map(fn (int $i): string => "c{$i}", range(0, $nFeat - 1));
    $taskFp = 'sha1:' . sha1(json_encode(['labels' => $labels, 'id' => $task['id']]));

    $db = BeeSwarm\Infra\Database::get();
    $db->exec('DELETE FROM trail_marks');

    // per-channel reseed: baseline identical across arms for same (task, seed);
    // run seed mixed in (owner fix 03.11: RNG = f(taskSeed, seedIndex) —
    // different runs of the same task must not share one stream)
    global $TASK_BUDGET;
    mt_srand($task['seed'] * 1000003 + $seed * 100003 + $slotIdx * 97 + 13);
    $blBudget = $TASK_BUDGET * STRAT_BASELINE_SHARE;
    $bl = baseline_flights($X, $y, $blBudget);
    $cvE = $bl['found'] ? cv_eval($bl['formula'], $Xe, $ye) : null;
    $blCertified = $bl['found'] && $cvE !== null && $cvE <= 0.10;
    $blNovel = false;
    if ($blCertified) {
        $fp = hash('sha256', labels_to_x((string) $bl['formula']));
        if (! in_array($fp, $certFingerprints, true)) {
            $certFingerprints[] = $fp;
            $blNovel = true;
        }
    }

    $slot = [
        'task' => (string) $task['id'],
        'class' => $taskClass,
        'seed' => $seed,
        'baseline' => [
            'found' => $bl['found'],
            'certified' => $blCertified,
            'novel' => $blNovel,
            'cv_eval' => $cvE,
            'formula' => $bl['formula'],
            'wall' => $bl['wall_total'],
        ],
    ];

    if ($arm === 'B') {
        $slot['route'] = ['ran' => false];
        $slot['novel_cert'] = $blNovel;
        return $slot;
    }

    // ---- route channel ----
    mt_srand($task['seed'] * 1000003 + $seed * 100003 + $slotIdx * 97 + 29);
    $routeBudget = $routeBudgetOverride > 0
        ? $routeBudgetOverride
        : $TASK_BUDGET * (1.0 - STRAT_BASELINE_SHARE);
    // Accountant tracks the ROUTE channel only (matched 8s baseline channel
    // is identical across arms by reseed — cancels in paired McNemar).
    // execution_actual = route wall; evolution charges = reproduction overhead.
    $accountant = new BudgetAccountant($routeBudget, 100000, 0.30 * $TASK_BUDGET);

    // Gate-observable guard: when gates matter, the sensor must have data
    // (R2: standardized probe, collection separated from use).
    $probeWall = 0.0;
    if (! $forceGates && $sensor !== null) {
        $probeWall = ensureObservable($X, $y, $labels, $sensor, $routeBudget);
        if ($probeWall > 0) {
            $accountant->chargeEvolution($probeWall, 1);
        }
    }

    // attempt plan = population order (evolution state as of this slot)
    $plan = $attemptPlan ?? array_map(
        fn (array $g): array => ['genome' => $g, 'forcedOn' => $forceGates],
        $ledger->active()
    );
    // (b) reachability probe (owner 04.11): STRAT_FULL_DATA=1 feeds the FULL
    // instance to the search (200 rows vs train 160). Diagnostic only: cert_v2
    // folds over seen rows = in-sample; measures REACHABILITY, not cert quality.
    if (getenv('STRAT_FULL_DATA') === '1') {
        $rc = runRouteChannel($plan, $Xall, $yall, $labels, $routeBudget - $probeWall, $sensor, $taskClass, $certFingerprints, $integratorState, (int) $task['seed'], $Xall, $yall);
    } else {
        $rc = runRouteChannel($plan, $X, $y, $labels, $routeBudget - $probeWall, $sensor, $taskClass, $certFingerprints, $integratorState, (int) $task['seed'], $Xall, $yall);
    }
    $accountant->chargeExecution($rc['route_wall'], $rc['route_actions']);

    $slot['route'] = [
        'ran' => true,
        'budget' => round($routeBudget, 2),
        'wall' => $rc['route_wall'],
        'actions' => $rc['route_actions'],
        'rows' => $rc['rows'],
        'trace' => $rc['trace'],
        'execution_actual' => $accountant->executionActualWall(),
        'unspent_total' => round($accountant->unspentWall(), 2),
        'probe_wall' => round($probeWall, 2),
        'attempt_plan' => array_map(
            fn (array $p): array => ['genome' => genomeGenes($p['genome']), 'forcedOn' => $p['forcedOn']],
            $plan
        ),
    ];
    $slot['novel_cert'] = $blNovel || $rc['novel_cert'];

    return $slot;
}

// ---- task materialization ----
function materializeTask(string $id, int $seedIdx): array
{
    global $taskById;
    if (isset($taskById[$id])) {
        $t = $taskById[$id];
        $csv = sys_get_temp_dir() . '/strategy_' . $id . '_' . $seedIdx . '.csv';
        gen_family($t, $csv);
        [$X, $y] = parse_csv($csv);
        @unlink($csv);
        $t['_X'] = $X;
        $t['_y'] = $y;

        return $t;
    }
    // benchmarks dot/kinetic/heat (prereg GT forms)
    $csv = sys_get_temp_dir() . '/strategy_bench_' . $id . '_' . $seedIdx . '.csv';
    gen_benchmark($id, (int) hexdec(substr(md5('STRATB_' . $id), 0, 7)), $csv);
    [$X, $y] = parse_csv($csv);
    @unlink($csv);

    return ['id' => $id, 'class' => $id, 'seed' => (int) hexdec(substr(md5('STRATB_' . $id), 0, 7)), '_X' => $X, '_y' => $y];
}

/** Standardized probe (R2: сбор отделён от использования): TWO cheap FLIGHTs
 * into the sensor buffer — flight 1 establishes the reference best, flight 2
 * produces a measurable drop (single flight has no R_before -> zero obs).
 * Identical machinery for every arm that runs routes. */
function probeFlight(array $X, array $y, array $labels, SensorState $sensor, float $budget): float
{
    $g = [
        'id' => RouteMutator::newId(), // hex-id schema (route_[0-9a-f]{4,})
        'parent_id' => '',
        'condition' => ['kind' => 'ALWAYS'],
        'budget_fraction' => 0.30,
        'max_steps' => 2,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'default', 'depth' => 1],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'default', 'depth' => 1],
        ],
    ];
    $err = RouteGenome::validate($g);
    if ($err !== []) {
        fwrite(STDERR, 'PROBE INVALID: ' . json_encode($err) . PHP_EOL);
        exit(3);
    }
    $certFp = [];
    $state = [];
    $t0 = microtime(true);
    $plan = [['genome' => $g, 'forcedOn' => true]];
    $rc = runRouteChannel($plan, $X, $y, $labels, $budget, $sensor, '', $certFp, $state);

    return microtime(true) - $t0;
}

// ---- bootstrap guard: gate deadlock protection ----
// Fresh buffer -> n_obs=0 < n_min -> all gates UNKNOWN -> no flights -> buffer
// stays empty forever. Bootstrap: slot 0 runs FORCED-ON (same machinery as C);
// additionally, when n_obs < n_min, a standardized probe tops up the buffer.
function ensureObservable(array $X, array $y, array $labels, SensorState $sensor, float $channelLeft): float
{
    $probeWall = 0.0;
    while ($sensor->stateWindow('FLIGHT', PHP_INT_MAX - 1, STRAT_W)['n'] < STRAT_N_MIN) {
        if ($channelLeft - $probeWall < 2.0) {
            break; // probes consume the SAME route channel (honest TOTAL budget)
        }
        $probeWall += probeFlight($X, $y, $labels, $sensor, min(4.0, $channelLeft - $probeWall));
    }

    return $probeWall;
}

/** heat structural coverage (owner step-2 fix 05.11): a target factor counts
 * ONLY in multiplicative context (adjacent to a product operator) — additive
 * presence is not a factor match; distinct factors, N=3, no double count. */
function heatStructuralCoverage(?string $fx): float
{
    if ($fx === null || $fx === '' || $fx === 'none') {
        return 0.0;
    }
    $cov = 0.0;
    foreach (['x0', 'x1', 'x2'] as $v) {
        if (preg_match('/(^|[^x0-9])' . $v . '(?![0-9])\s*[×*]/', $fx)
            || preg_match('/[×*]\s*' . $v . '(?![0-9])/', $fx)) {
            $cov += 1 / 3;
        }
    }

    return $cov;
}

// ================= MAIN =================
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../../vendor/autoload.php';

$db = BeeSwarm\Infra\Database::get();
$RUN_ID = 'strategy_' . $phase . '_' . $arm . '_' . $seed;

switch ($phase) {
    // -------- Phase 1: Evolution (arms A, B) --------
    case 'evolve':
        if (! in_array($arm, ['A', 'B', 'I'], true)) {
            fwrite(STDERR, "evolve: arm must be A|I|B\n");
            exit(2);
        }
        // STRATEGY-C2 (owner 04.11): arm I = CLASS_MATCH gate (no theta);
        // hand-ladder seeded populations (form presence = design condition).
        $variant = ($arm === 'I') ? 'classmatch' : 'integrator';
        $condPrefix = ($arm === 'I') ? RouteGenome::CONDITION_CLASSMATCH_PREFIX : RouteGenome::CONDITION_INTEGRATOR_PREFIX;
        mt_srand($seed);
        $ledger = new RouteLedger();
        $sensor = new SensorState(STRAT_N_MIN);
        $integratorState = [];
        $certFingerprints = [];
        $evolutionOverhead = 0.0;
        if ($arm !== 'B') {
            $nHand = (int) (getenv('STRAT_HAND_LADDERS') ?: 4);
            foreach (handLadders(array_map(
                fn (string $cls): string => $condPrefix . $cls,
                ['SUM', 'RATIO', 'PRODUCT', 'SUM']
            )) as $i => $lg) {
                if ($i >= $nHand) {
                    break;
                }
                $ledger->register($lg);
            }
            foreach (seedStrategyPopulation(STRAT_POP_N - min($nHand, 4)) as $g) {
                $g['condition'] = conditionByVariant($variant);
                $err = RouteGenome::validate($g);
                if ($err !== []) {
                    fwrite(STDERR, 'SEED INVALID: ' . json_encode($err) . PHP_EOL);
                    exit(3);
                }
                $ledger->register($g);
            }
        }
        $slots = [];
        foreach (array_values($pool) as $slotIdx => $taskId) {
            $taskClass = $classOfTask($taskId);
            if ($taskClass === null) {
                fwrite(STDERR, "task {$taskId}: no class mapping\n");
                exit(2);
            }
            $task = materializeTask($taskId, $seed);
            // bootstrap slot 0 forced-on (deadlock guard); slots 1+ gated
            $forceGates = ($arm === 'A' && $slotIdx === 0);
            $slot = runSlot($arm, $task, $taskClass, $seed, $slotIdx, $ledger, $sensor, $integratorState, $certFingerprints, $forceGates, 0.0);
            // arm A: inter-task evolution (Stage A precedent, types 0..8)
            if ($arm === 'A') {
                $t0 = microtime(true);
                foreach ($ledger->topForReproduction(3) as $p) {
                    if (count($ledger->active()) >= STRAT_POP_N + 4) {
                        break;
                    }
                    $child = RouteMutator::mutate($p, mt_rand(0, 8));
                    if ($child === null) {
                        continue;
                    }
                    // arm invariant: conditions stay in the arm's family
                    $childKind = (string) ($child['condition']['kind'] ?? '');
                    $childInFamily = ($variant === 'classmatch')
                        ? str_starts_with($childKind, RouteGenome::CONDITION_CLASSMATCH_PREFIX)
                        : str_starts_with($childKind, RouteGenome::CONDITION_INTEGRATOR_PREFIX);
                    if (! $childInFamily) {
                        $child['condition'] = conditionByVariant($variant);
                    }
                    $err = RouteGenome::validate($child);
                    if ($err === []) {
                        $ledger->register($child);
                    }
                }
                $evolutionOverhead += microtime(true) - $t0;
            }
            $slots[] = $slot;
            fwrite(STDERR, "[{$RUN_ID}] {$taskId}({$taskClass}) novel=" . var_export($slot['novel_cert'], true) . PHP_EOL);
        }
        $novelN = count(array_filter($slots, fn (array $s): bool => (bool) $s['novel_cert']));
        $meanWall = count($slots) > 0 ? array_sum(array_map(fn (array $s): float => (float) ($s['route']['wall'] ?? 0.0), $slots)) / count($slots) : 0.0;
        $out = [
            'run_id' => $RUN_ID,
            'phase' => 'evolve',
            'arm' => $arm,
            'seed' => $seed,
            'pool' => $pool,
            'task_budget' => STRAT_TOTAL_TASK_BUDGET,
            'baseline_share' => STRAT_BASELINE_SHARE,
            'frozen_params' => ['alpha' => STRAT_ALPHA, 'theta' => STRAT_THETA, 'n_min' => STRAT_N_MIN, 'W' => STRAT_W],
            'slots' => $slots,
            'final_population' => $arm === 'A' ? array_map('genomeGenes', $ledger->active()) : [],
            'evolution_overhead_wall' => round($evolutionOverhead, 3),
            'summary' => [
                'n_slots' => count($slots),
                'novel_cert' => $novelN,
                'mean_route_wall' => round($meanWall, 2),
            ],
        ];
        $outPath = $outDir . '/evolve_' . $arm . '_' . $seed . '.json';
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE {$RUN_ID}: novelCert={$novelN}/" . count($slots) . " -> {$outPath}\n";
        break;

    // -------- Phase C: always-on replay (rev.5 edit 1, forC budget) --------
    case 'replayC':
        if ($arm !== 'C') {
            fwrite(STDERR, "replayC: arm must be C\n");
            exit(2);
        }
        $evolvePath = $outDir . '/evolve_A_' . $seed . '.json';
        if (! is_file($evolvePath)) {
            fwrite(STDERR, "missing {$evolvePath} (run evolve A first)\n");
            exit(2);
        }
        $evolve = json_decode(file_get_contents($evolvePath), true);
        $integratorState = [];
        $certFingerprints = [];
        $slots = [];
        foreach ($evolve['slots'] as $slotIdx => $aSlot) {
            $task = materializeTask((string) $aSlot['task'], $seed);
            $taskClass = (string) $aSlot['class'];
            $plan = [];
            foreach (($aSlot['route']['attempt_plan'] ?? []) as $p) {
                $plan[] = ['genome' => $p['genome'], 'forcedOn' => true];
            }
            // C budget = A's execution_actual on THIS slot (forC semantics)
            $cBudget = (float) ($aSlot['route']['execution_actual'] ?? 0.0);
            $slot = runSlot('C', $task, $taskClass, $seed, $slotIdx, new RouteLedger(), null, $integratorState, $certFingerprints, true, $cBudget, $plan);
            $slot['c_budget'] = round($cBudget, 2);
            $slots[] = $slot;
            fwrite(STDERR, "[{$RUN_ID}] {$aSlot['task']}({$taskClass}) budget={$cBudget}s novel=" . var_export($slot['novel_cert'], true) . PHP_EOL);
        }
        $novelN = count(array_filter($slots, fn (array $s): bool => (bool) $s['novel_cert']));
        $out = [
            'run_id' => $RUN_ID,
            'phase' => 'replayC',
            'arm' => 'C',
            'seed' => $seed,
            'slots' => $slots,
            'summary' => ['n_slots' => count($slots), 'novel_cert' => $novelN],
        ];
        $outPath = $outDir . '/evolve_C_' . $seed . '.json';
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE {$RUN_ID}: novelCert={$novelN}/" . count($slots) . " -> {$outPath}\n";
        break;

    // -------- Phase 2: Freeze (serialize + hash) --------
    case 'freeze':
        $frozen = ['_frozen_at' => date('c'), '_prereg_sha256' => 'a9d99a90c3ac7c61e47a05c4ed735f3c06ab6b2e294ed10168d80b122cd1569c', 'seeds' => []];
        foreach (STRAT_DONOR_SEEDS as $ds) {
            $p = $outDir . '/evolve_A_' . $ds . '.json';
            if (! is_file($p)) {
                fwrite(STDERR, "missing {$p}\n");
                exit(2);
            }
            $e = json_decode(file_get_contents($p), true);
            $genomes = [];
            foreach (($e['final_population'] ?? []) as $g) {
                $genomes[] = ['genome' => $g, 'sha256' => genomeHash($g)];
            }
            $frozen['seeds'][(string) $ds] = [
                'population' => $genomes,
                'mean_route_wall' => $e['summary']['mean_route_wall'] ?? null,
                'evolution_overhead_wall' => $e['evolution_overhead_wall'] ?? null,
            ];
        }
        $frozen['gt_forms'] = array_combine($pool, array_map('gt_form', $pool));
        $frozen['class_table'] = ['SUM' => ['D4', 'D5', 'DOT4', 'dot'], 'PRODUCT' => ['D2', 'D6', 'kinetic', 'heat'], 'RATIO' => ['D1', 'D3', 'RATIO2', 'RATIO3']];
        $frozen['constants'] = [
            'total_task_budget' => STRAT_TOTAL_TASK_BUDGET,
            'baseline_share' => STRAT_BASELINE_SHARE,
            'pop_n' => STRAT_POP_N,
            'alpha' => STRAT_ALPHA,
            'theta' => STRAT_THETA,
            'n_min' => STRAT_N_MIN,
            'W' => STRAT_W,
            'donor_seeds' => STRAT_DONOR_SEEDS,
            'mcnemar_alpha' => STRAT_MC_NEMAR_ALPHA,
        ];
        $outPath = $outDir . '/frozen_genomes.json';
        file_put_contents($outPath, json_encode($frozen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE freeze: " . count($frozen['seeds']) . " seeds -> {$outPath}\n";
        break;

    // -------- Phase 3: Cross-eval (frozen X-genomes on fresh Y instances) --------
    case 'crosseval':
        if (! in_array($arm, ['A', 'B'], true)) {
            fwrite(STDERR, "crosseval: arm must be A|B\n");
            exit(2);
        }
        $rawByClass = [];
        foreach (($classMap ?? []) as $label => $raws) {
            $rawByClass[(string) $label] = $raws;
        }
        $rows = [];
        if ($arm === 'A') {
            $evolvePath = $outDir . '/evolve_A_' . $seed . '.json';
            if (! is_file($evolvePath)) {
                fwrite(STDERR, "missing {$evolvePath}\n");
                exit(2);
            }
            $e = json_decode(file_get_contents($evolvePath), true);
            $finalPop = $e['final_population'] ?? [];
            $donorBudget = (float) ($e['summary']['mean_route_wall'] ?? 0.0);
            foreach (STRAT_CLASSES as $X) {
                foreach (STRAT_CLASSES as $Y) {
                    if ($X === $Y) {
                        continue; // within-class = evolution slots, not transfer
                    }
                    // donor: top genome of this seed with integrator class X
                    $donor = null;
                    foreach ($finalPop as $g) {
                        if (str_starts_with((string) $g['condition']['kind'], RouteGenome::CONDITION_INTEGRATOR_PREFIX)
                            && substr((string) $g['condition']['kind'], strlen(RouteGenome::CONDITION_INTEGRATOR_PREFIX)) === $X) {
                            $donor = $g;
                            break;
                        }
                    }
                    if ($donor === null) {
                        $rows[] = ['pair' => "{$X}->{$Y}", 'seed' => $seed, 'donor' => null, 'note' => 'no donor genome of class X in final population'];
                        continue;
                    }
                    // 3 fresh recipient instances (independence: STRATC seeds)
                    for ($j = 0; $j < STRAT_CROSS_RECIPIENT_N; $j++) {
                        $raws = $rawByClass[$Y] ?? [];
                        $raw = $raws === [] ? 'dot4' : $raws[$j % count($raws)];
                        $rSeed = (int) hexdec(substr(md5('STRATC_' . $X . '_' . $Y . '_' . $j), 0, 7));
                        $rTask = ['id' => "X{$X}_Y{$Y}_r{$j}", 'class' => $raw, 'seed' => $rSeed, 'perm' => range(0, 4), 'scale' => array_fill(0, 5, 1), 'noise' => 0.0];
                        if ($raw === 'dot4') {
                            $rTask['perm'] = range(0, 5);
                            $rTask['scale'] = array_fill(0, 6, 1);
                        }
                        $csv = sys_get_temp_dir() . '/strategy_cross_' . md5((string) $rSeed) . '.csv';
                        gen_family($rTask, $csv);
                        [$Xr, $yr] = parse_csv($csv);
                        @unlink($csv);
                        $rTask['_X'] = $Xr;
                        $rTask['_y'] = $yr;
                        $ledger = new RouteLedger();
                        $ledger->register($donor);
                        $sensor = new SensorState(STRAT_N_MIN);
                        $integratorState = [];
                        $certFingerprints = [];
                        // (i) gated: A-semantics on foreign class (expected CLASS_MISMATCH)
                        $gated = runSlot('A', $rTask, $Y, $seed, $j, $ledger, $sensor, $integratorState, $certFingerprints, false, 0.0, [['genome' => $donor, 'forcedOn' => false]]);
                        // (ii) always-on: transfer vehicle, budget = donor_budget
                        $ledger2 = new RouteLedger();
                        $ledger2->register($donor);
                        $sensor2 = new SensorState(STRAT_N_MIN);
                        $integratorState2 = [];
                        $certFingerprints2 = [];
                        $alwaysOn = runSlot('A', $rTask, $Y, $seed, $j, $ledger2, $sensor2, $integratorState2, $certFingerprints2, true, $donorBudget, [['genome' => $donor, 'forcedOn' => true]]);
                        $rows[] = [
                            'pair' => "{$X}->{$Y}",
                            'seed' => $seed,
                            'recipient' => $j,
                            'donor_id' => $donor['id'],
                            'donor_sha256' => genomeHash($donor),
                            'donor_budget' => round($donorBudget, 2),
                            'raw_class' => $raw,
                            'gated' => ['novel_cert' => $gated['novel_cert'], 'gate_reasons' => array_column(array_filter($gated['route']['rows'] ?? [], fn ($r2) => isset($r2['gated_reason'])), 'gated_reason')],
                            'alwayson' => ['novel_cert' => $alwaysOn['novel_cert'], 'wall' => $alwaysOn['route']['wall'] ?? 0.0],
                        ];
                    }
                }
            }
        } else {
            // arm B: baseline-only on the SAME recipient instances (controls)
            foreach (STRAT_CLASSES as $X) {
                foreach (STRAT_CLASSES as $Y) {
                    if ($X === $Y) {
                        continue;
                    }
                    $raws = $rawByClass[$Y] ?? [];
                    for ($j = 0; $j < STRAT_CROSS_RECIPIENT_N; $j++) {
                        $raw = $raws === [] ? 'dot4' : $raws[$j % count($raws)];
                        $rSeed = (int) hexdec(substr(md5('STRATC_' . $X . '_' . $Y . '_' . $j), 0, 7));
                        $rTask = ['id' => "X{$X}_Y{$Y}_r{$j}", 'class' => $raw, 'seed' => $rSeed, 'perm' => range(0, 4), 'scale' => array_fill(0, 5, 1), 'noise' => 0.0];
                        if ($raw === 'dot4') {
                            $rTask['perm'] = range(0, 5);
                            $rTask['scale'] = array_fill(0, 6, 1);
                        }
                        $csv = sys_get_temp_dir() . '/strategy_cross_' . md5((string) $rSeed) . '.csv';
                        gen_family($rTask, $csv);
                        [$Xr, $yr] = parse_csv($csv);
                        @unlink($csv);
                        $rTask['_X'] = $Xr;
                        $rTask['_y'] = $yr;
                        $integratorState = [];
                        $certFingerprints = [];
                        $slot = runSlot('B', $rTask, $Y, $seed, $j, new RouteLedger(), null, $integratorState, $certFingerprints, false, 0.0);
                        $rows[] = ['pair' => "{$X}->{$Y}", 'seed' => $seed, 'recipient' => $j, 'novel_cert' => $slot['novel_cert'], 'baseline' => $slot['baseline']];
                    }
                }
            }
        }
        $outPath = $outDir . '/crosseval_' . $arm . '_' . $seed . '.json';
        file_put_contents($outPath, json_encode(['run_id' => $RUN_ID, 'phase' => 'crosseval', 'arm' => $arm, 'seed' => $seed, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE {$RUN_ID}: rows=" . count($rows) . " -> {$outPath}\n";
        break;

    // -------- Calibration: positive reference (prereg §5b) --------
    case 'calibration':
        // probe_loop dot 8/8 precedent bar; DESIGN 1 (owner 03.11): single
        // ladder, STRATB instance. cert_v2 now gets FULL data (bug fix).
        $calSeeds = range(201, 208);
        $certs = 0;
        $rows = [];
        // (iii) hand-seed ladder test (owner 03.11): any B-failed task via env
        $calTaskId = (string) (getenv('STRAT_CAL_TASK') ?: 'dot');
        foreach ($calSeeds as $cs) {
            $task = materializeTask($calTaskId, 0);
            $csv = sys_get_temp_dir() . '/strategy_cal_' . $calTaskId . '.csv';
            if (isset($taskById[$calTaskId])) {
                gen_family($task, $csv);
                [$X, $y] = parse_csv($csv);
            } else {
                gen_benchmark($calTaskId, $task['seed'], $csv);
                [$X, $y] = parse_csv($csv);
            }
            @unlink($csv);
            mt_srand($cs);
            $plan = [];
            $nLadders = (int) (getenv('STRAT_CAL_LADDERS') ?: 1); // multi-ladder probe (road chain)
            foreach (range(0, $nLadders - 1) as $li) {
                $ladder = [
                    'id' => 'route_' . substr(sha1('probe_ladder_' . $li), 0, 8),
                    'parent_id' => '',
                    'condition' => ['kind' => 'ALWAYS'],
                    'budget_fraction' => 0.30,
                    'max_steps' => 5,
                    'actions' => [
                        ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                        ['op' => 'USE_MARKS', 'k' => 5],
                        ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                        ['op' => 'USE_MARKS', 'k' => 5],
                        ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                    ],
                ];
                $err = RouteGenome::validate($ladder);
                if ($err !== []) {
                    fwrite(STDERR, 'CAL LADDER INVALID: ' . json_encode($err) . PHP_EOL);
                    exit(3);
                }
                $plan[] = ['genome' => $ladder, 'forcedOn' => true];
            }
            [$Xall, $yall] = [$X, $y];
            [$X, $y, $Xe, $ye] = split_eval($Xall, $yall, $task['seed'] + $cs);
            $labels = array_map(fn (int $i): string => "c{$i}", range(0, count($X[0]) - 1));
            $db->exec('DELETE FROM trail_marks');
            $ledger = new RouteLedger();
            $sensor = new SensorState(STRAT_N_MIN);
            $integratorState = [];
            $certFingerprints = [];
            $task['_X'] = $X;
            $task['_y'] = $y;
            $plan = [['genome' => $ladder, 'forcedOn' => true]];
            $slot = runSlot('C', $task, 'SUM', $cs, 0, $ledger, $sensor, $integratorState, $certFingerprints, true, $TASK_BUDGET * (1.0 - STRAT_BASELINE_SHARE), $plan);
            $ok = (bool) $slot['novel_cert'];
            $certs += $ok ? 1 : 0;
            $rows[] = [
                'seed' => $cs,
                'novel_cert' => $ok,
                'route_wall' => $slot['route']['wall'] ?? 0.0,
                'unspent' => $slot['route']['unspent_total'] ?? null,
            ];
            fwrite(STDERR, "[cal] seed={$cs} cert=" . var_export($ok, true) . PHP_EOL);
        }
        // (a) read-channel sufficiency: mean unspent share > 50% = "H not tested"
        $meanUnspent = array_sum(array_map(fn (array $r): float => (float) ($r['unspent'] ?? 0.0), $rows)) / count($rows);
        $channel = $TASK_BUDGET * (1.0 - STRAT_BASELINE_SHARE);
        $out = [
            'run_id' => $RUN_ID,
            'phase' => 'calibration',
            'positive_reference' => 'probe_loop dot ladder (8/8 precedent)',
            'route_budget' => $channel,
            'certs' => "{$certs}/" . count($calSeeds),
            'pass' => $certs === count($calSeeds),
            'mean_unspent' => round($meanUnspent, 2),
            'unspent_share' => round($meanUnspent / max(0.001, $channel), 3),
            'h_not_tested_flag' => ($meanUnspent / max(0.001, $channel)) > 0.5,
            'rows' => $rows,
        ];
        $outPath = $outDir . '/calibration.json';
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE calibration: {$certs}/" . count($calSeeds) . " pass=" . var_export($out['pass'], true) . " -> {$outPath}\n";
        break;

    // -------- Phase 4: Comparison (verdicts §10) --------
    case 'compare':
        $armVectors = [];
        foreach (['A', 'B', 'C'] as $a) {
            $vec = [];
            foreach (STRAT_DONOR_SEEDS as $ds) {
                $p = $outDir . '/evolve_' . $a . '_' . $ds . '.json';
                if (! is_file($p)) {
                    fwrite(STDERR, "missing {$p}\n");
                    exit(2);
                }
                $e = json_decode(file_get_contents($p), true);
                foreach (($e['slots'] ?? []) as $s) {
                    $vec[] = ['task' => $s['task'], 'seed' => $s['seed'], 'novel' => (int) (bool) $s['novel_cert']];
                }
            }
            $armVectors[$a] = $vec;
        }
        $nSlots = count($armVectors['A']);
        $paired = function (array $v1, array $v2): array {
            $n10 = 0;
            $n01 = 0;
            foreach ($v1 as $i => $s1) {
                if ($s1['task'] !== $v2[$i]['task'] || $s1['seed'] !== $v2[$i]['seed']) {
                    return ['error' => 'slot misalignment'];
                }
                if ($s1['novel'] === 1 && $v2[$i]['novel'] === 0) {
                    $n10++;
                }
                if ($s1['novel'] === 0 && $v2[$i]['novel'] === 1) {
                    $n01++;
                }
            }

            return ['n10' => $n10, 'n01' => $n01, 'p_exact' => round(TransferStats::exactMcNemar($n01, $n10), 6)];
        };
        $wilson = fn (array $v): float => round(TransferStats::wilsonLower(count(array_filter($v, fn ($s) => $s['novel'] === 1)), count($v)), 4);
        $counts = [
            'A' => count(array_filter($armVectors['A'], fn ($s) => $s['novel'] === 1)),
            'B' => count(array_filter($armVectors['B'], fn ($s) => $s['novel'] === 1)),
            'C' => count(array_filter($armVectors['C'], fn ($s) => $s['novel'] === 1)),
        ];
        $mc = [
            'A_vs_B' => $paired($armVectors['A'], $armVectors['B']),
            'A_vs_C' => $paired($armVectors['A'], $armVectors['C']),
            'C_vs_B' => $paired($armVectors['C'], $armVectors['B']),
        ];
        // Budget floor (F5): all arms all slots = 0 at equal TOTAL -> INVALID
        $budgetFloor = ($counts['A'] + $counts['B'] + $counts['C']) === 0;
        // Manipulation check (§9): A/C action-trace divergence per slot
        $divergent = 0;
        $tracesA = [];
        $tracesC = [];
        foreach (STRAT_DONOR_SEEDS as $ds) {
            $ea = json_decode(file_get_contents($outDir . '/evolve_A_' . $ds . '.json'), true);
            $ec = json_decode(file_get_contents($outDir . '/evolve_C_' . $ds . '.json'), true);
            foreach (($ea['slots'] ?? []) as $i => $s) {
                $tracesA[] = json_encode($s['route']['trace'] ?? []);
            }
            foreach (($ec['slots'] ?? []) as $i => $s) {
                $tracesC[] = json_encode($s['route']['trace'] ?? []);
            }
        }
        foreach ($tracesA as $i => $ta) {
            if (($tracesC[$i] ?? null) !== null && $ta !== $tracesC[$i]) {
                $divergent++;
            }
        }
        $manipulationActivated = $divergent > 0;
        // ---- verdicts (§10 matrix) ----
        $sig = fn (array $m, string $dir): bool => ! isset($m['error'])
            && (($dir === '>' && $m['n10'] > $m['n01']) || ($dir === '<' && $m['n01'] > $m['n10']))
            && $m['p_exact'] < STRAT_MC_NEMAR_ALPHA;
        $verdicts = [
            'H1_PASS' => $sig($mc['A_vs_B'], '>'),
            'CONDITION_contribution_PASS' => $sig($mc['A_vs_C'], '>'),
            'EVOLUTION_contribution_PASS' => $sig($mc['C_vs_B'], '>'),
        ];
        // H2 candidate: cross-eval transfer map
        $transfer = [];
        $crossAFiles = glob($outDir . '/crosseval_A_*.json') ?: [];
        $crossBFiles = glob($outDir . '/crosseval_B_*.json') ?: [];
        $bByKey = [];
        foreach ($crossBFiles as $f) {
            $d = json_decode(file_get_contents($f), true);
            foreach (($d['rows'] ?? []) as $r) {
                if (! isset($r['recipient'])) {
                    continue;
                }
                $bByKey[$r['pair'] . '|' . $r['seed'] . '|' . $r['recipient']] = (bool) ($r['novel_cert'] ?? false);
            }
        }
        $byPair = [];
        foreach ($crossAFiles as $f) {
            $d = json_decode(file_get_contents($f), true);
            foreach (($d['rows'] ?? []) as $r) {
                if (! isset($r['recipient'])) {
                    continue;
                }
                $key = $r['pair'] . '|' . $r['seed'] . '|' . $r['recipient'];
                $bCert = $bByKey[$key] ?? null;
                $byPair[$r['pair']]['recipients'][$r['recipient']]['alwayson_seeds'][] = (bool) ($r['alwayson']['novel_cert'] ?? false);
                $byPair[$r['pair']]['recipients'][$r['recipient']]['b_seeds'][] = $bCert;
            }
        }
        foreach ($byPair as $pair => $info) {
            $recPos = 0;
            $recN = count($info['recipients']);
            foreach ($info['recipients'] as $rec) {
                // recipient-positive: >=2/3 donor seeds alwayson-cert AND > B rate
                $onRate = count(array_filter($rec['alwayson_seeds'])) / max(1, count($rec['alwayson_seeds']));
                $bRate = count(array_filter($rec['b_seeds'], fn ($v) => $v === true)) / max(1, count($rec['b_seeds']));
                if ($onRate >= 2 / 3 && $onRate > $bRate) {
                    $recPos++;
                }
            }
            $transfer[$pair] = [
                'recipients_positive' => "{$recPos}/{$recN}",
                'exploratory_transfer_positive' => $recPos === $recN && $recN >= 3,
                'wilson_lower' => $recN > 0 ? round(TransferStats::wilsonLower($recPos, $recN), 4) : null,
            ];
        }
        $anyPairPositive = (bool) array_filter($transfer, fn ($t) => $t['exploratory_transfer_positive']);
        $verdicts['H2_candidate_PASS'] = $anyPairPositive;
        // INVALID conditions
        $invalid = [];
        if ($budgetFloor) {
            $invalid[] = 'budget_floor: all arms 0 novel-cert at equal TOTAL (F5)';
        }
        if (! $manipulationActivated && $counts['A'] === $counts['C']) {
            $invalid[] = 'manipulation_check: A==C outcomes with zero trace divergence (§9)';
        }
        // classification per §10
        $anyPass = $verdicts['H1_PASS'] || $verdicts['CONDITION_contribution_PASS'] || $verdicts['EVOLUTION_contribution_PASS'] || $verdicts['H2_candidate_PASS'];
        $verdicts['overall'] = $invalid !== [] ? 'INVALID' : ($anyPass ? 'PASS_candidates_present' : 'BORDER_or_NULL');
        $out = [
            'run_id' => $RUN_ID,
            'phase' => 'compare',
            'n_slots_per_arm' => $nSlots,
            'novel_cert_counts' => $counts,
            'wilson_lower' => ['A' => $wilson($armVectors['A']), 'B' => $wilson($armVectors['B']), 'C' => $wilson($armVectors['C'])],
            'mcnemar' => $mc,
            'transfer_map' => $transfer,
            'manipulation_check' => ['divergent_slots' => $divergent, 'activated' => $manipulationActivated],
            'budget_floor' => $budgetFloor,
            'invalid_reasons' => $invalid,
            'verdicts' => $verdicts,
            'two_class_limitation_note' => 'H3/generic = descriptive only (prereg §7); RATIO = third class (F1)',
        ];
        $outPath = $outDir . '/campaign_summary.json';
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE compare: A={$counts['A']} B={$counts['B']} C={$counts['C']} verdict={$verdicts['overall']} -> {$outPath}\n";
        break;

    // -------- Phase 4b: STRATEGY-C2 comparison (A/I/B, owner 04.11) --------
    case 'compare2':
        $vecs = [];
        foreach (['A', 'I', 'B'] as $a) {
            $vecs[$a] = [];
            foreach (range(301, 310) as $s) {
                $p = $outDir . '/evolve_' . $a . '_' . $s . '.json';
                if (! is_file($p)) {
                    continue;
                }
                $e = json_decode(file_get_contents($p), true);
                foreach (($e['slots'] ?? []) as $s2) {
                    $vecs[$a][] = ['task' => $s2['task'], 'seed' => $s2['seed'], 'novel' => (int) (bool) $s2['novel_cert']];
                }
            }
        }
        $nPerArm = max(count($vecs['A']), count($vecs['I']), count($vecs['B']));
        $paired2 = function (array $v1, array $v2): array {
            $n10 = 0;
            $n01 = 0;
            foreach ($v1 as $i => $s1) {
                if (! isset($v2[$i]) || $s1['task'] !== $v2[$i]['task'] || $s1['seed'] !== $v2[$i]['seed']) {
                    continue;
                }
                if ($s1['novel'] === 1 && $v2[$i]['novel'] === 0) {
                    $n10++;
                }
                if ($s1['novel'] === 0 && $v2[$i]['novel'] === 1) {
                    $n01++;
                }
            }

            return ['n10' => $n10, 'n01' => $n01, 'p_exact' => round(TransferStats::exactMcNemar($n01, $n10), 6)];
        };
        $counts2 = [];
        foreach (['A', 'I', 'B'] as $a) {
            $counts2[$a] = count(array_filter($vecs[$a], fn ($s) => $s['novel'] === 1));
        }
        $wil2 = fn (array $v): float => round(TransferStats::wilsonLower(count(array_filter($v, fn ($s) => $s['novel'] === 1)), max(1, count($v))), 4);
        $mc2 = [
            'A_vs_I_theta_contribution' => $paired2($vecs['A'], $vecs['I']),
            'I_vs_B_condition_no_theta' => $paired2($vecs['I'], $vecs['B']),
            'A_vs_B_Q1' => $paired2($vecs['A'], $vecs['B']),
        ];
        $floor2 = ($counts2['A'] + $counts2['I'] + $counts2['B']) === 0;
        $verdicts2 = [
            'H1_Q1_PASS (A>B)' => $mc2['A_vs_B_Q1']['n10'] > $mc2['A_vs_B_Q1']['n01'] && $mc2['A_vs_B_Q1']['p_exact'] < STRAT_MC_NEMAR_ALPHA,
            'THETA_verdict (A vs I)' => $mc2['A_vs_I_theta_contribution']['n10'] === $mc2['A_vs_I_theta_contribution']['n01'] ? 'neutral' : ($mc2['A_vs_I_theta_contribution']['n10'] > $mc2['A_vs_I_theta_contribution']['n01'] ? 'theta_HELPS' : 'theta_COSTS'),
            'CONDITION_no_theta_PASS (I>B)' => $mc2['I_vs_B_condition_no_theta']['n10'] > $mc2['I_vs_B_condition_no_theta']['n01'] && $mc2['I_vs_B_condition_no_theta']['p_exact'] < STRAT_MC_NEMAR_ALPHA,
        ];
        $out = [
            'run_id' => $RUN_ID,
            'phase' => 'compare2',
            'n_slots_per_arm' => $nPerArm,
            'novel_cert_counts' => $counts2,
            'wilson_lower' => ['A' => $wil2($vecs['A']), 'I' => $wil2($vecs['I']), 'B' => $wil2($vecs['B'])],
            'mcnemar' => $mc2,
            'budget_floor' => $floor2,
            'verdicts' => $verdicts2,
            'design_note' => 'C2: hand-ladder seeded populations, 3 tasks (dot SUM / D1 RATIO / heat PRODUCT) x 10 seeds; A = CLASS_INTEGRATOR (theta 0.05), I = CLASS_MATCH (no theta); ladder=forced-on form-presence design, Q1 claim = evolution+gate WITH form present',
        ];
        $outPath = $outDir . '/campaign2_summary.json';
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE compare2: A={$counts2['A']} I={$counts2['I']} B={$counts2['B']} -> {$outPath}\n";
        break;



    // -------- BUFFER-1 PILOT (rev.2, owner handoff 05.11) --------
    // A = theta-loop control (C1 gate: state on yield, theta 0.05 fixed)
    // B = partial credit + adaptive theta + bootstrap-first.
    // heat only (N=3 known factors x0,x1,x2). 15 gens. Per-gen telemetry chain:
    // attempts -> gate_passes -> executor_calls -> completed_executions.
    case 'buffer1':
        if (! in_array($arm, ['A', 'B', 'D', 'W', 'L', 'P', 'Q', 'T'], true)) {
            // A = theta control; B = 100% retention; D = scout; W = wipe;
            // L = line selection; P = potential shaping; Q = 20% immigration;
            // T = resource-trigger (owner step-5): the channel grows when the
            // recent cert-rate is zero (32 -> 48). PREREG PREDICTION (E45
            // canon): byte-twin of the P arm — the budget is not converted
            // into search (space exhaustion); the trigger fires, nothing moves.
            fwrite(STDERR, "buffer1: arm must be A|B|D|W|L|P|Q|T\n");
            exit(2);
        }
        $isRetentionArm = ($arm === 'B' || $arm === 'D' || $arm === 'W' || $arm === 'L' || $arm === 'P' || $arm === 'Q' || $arm === 'T');
        $nGensB1 = (int) (getenv('STRAT_B1_GENS') ?: 15);
        $theta0 = 0.30; $alphaState = 0.70; $dTheta = 0.05; $thetaMin = 0.05; // frozen by handoff
        mt_srand($seed);
        $ledger = new RouteLedger();
        $sensor = new SensorState(STRAT_N_MIN);
        $integratorState = [];
        $certFingerprints = [];
        // C2 seed, ALL-PRODUCT conditions (single-task pilot: class-selectivity
        // would confound gate-liveness measurement)
        foreach (handLadders(array_map(
            fn (string $cls): string => RouteGenome::CONDITION_INTEGRATOR_PREFIX . $cls,
            ['PRODUCT', 'PRODUCT', 'PRODUCT', 'PRODUCT']
        )) as $lg) {
            $ledger->register($lg);
            $lineOf[$lg['id']] = $lg['id']; // each seed = its own line
        }
        foreach (seedStrategyPopulation(STRAT_POP_N - 4) as $g) {
            $g['condition'] = [
                'kind' => RouteGenome::CONDITION_INTEGRATOR_PREFIX . 'PRODUCT',
                'alpha' => STRAT_ALPHA,
                'theta' => STRAT_THETA,
                'n_min' => STRAT_N_MIN,
            ];
            $ledger->register($g);
            $lineOf[$g['id']] = $g['id'];
        }
        $task = materializeTask('heat', $seed);
        $taskClass = 'PRODUCT';
        $db->exec('DELETE FROM trail_marks');
        // per-id state maps (retention fix: genome id = stable key; the content
        // hash changed through recordOutcome's record updates)
        $bestCoverage = [];   // id => max structural coverage seen
        $thetaMap = [];       // id => current theta (B only)
        $stateMap = [];       // id => smoothed coverage state (B only)
        $topFormLog = [];     // gen => best form seen (observability)
        $formFps = [];        // form fingerprint => seen (diversity metric, all arms)
        $firstFormFp = null;  // the champion's fingerprint (gen 1)
        $firstNewFormGen = null; // first gen with a form != champion's
        // LINE-level maps (arm L): the line = genome + its road credit
        $lineOf = [];         // genome id => line id (inherited at mutation)
        $lineCoverage = [];   // line id => max structural coverage of the line
        $lineCerts = [];      // line id => certs produced by the line's road
        $genLog = [];
        $fullFormGen = null;
        $firstCertGen = null;
        for ($gen = 0; $gen < $nGensB1; $gen++) {
            $t0 = microtime(true);
            // OWNER SPEC FIX (05.11): «геном-0 КАЖДОЙ линии» = per-line
            // bootstrap: the FIRST execution of any genome is forced — not a
            // one-shot global gen-0 (that version dies: executed plateau
            // champions get revoked by the P-rule, and never-executed genomes
            // have state = 0 forever -> execution-extinction)
            $force = ($gen === 0);
            // W: road wipe before each generation (the anti-anchor probe)
            $roadBeforeWipe = null;
            if ($arm === 'W') {
                $roadBeforeWipe = (int) $db->query('SELECT COUNT(*) AS c FROM trail_marks')->fetch()['c'];
                $db->exec('DELETE FROM trail_marks');
            }
            // attempt plan: B = coverage-priority (retention as energy);
            // D = 95/5: two coverage lines + one neutral scout (no bonus);
            // L = line-credit priority (the line's road productivity);
            // A = natural order
            $planGenomes = $ledger->active();
            if ($isRetentionArm) {
                usort($planGenomes, function (array $a, array $b) use (&$bestCoverage, &$lineCoverage, &$lineOf, $arm): int {
                    if ($arm === 'L') {
                        $la = $lineCoverage[$lineOf[$a['id']] ?? $a['id']] ?? 0.0;
                        $lb = $lineCoverage[$lineOf[$b['id']] ?? $b['id']] ?? 0.0;
                        return $lb <=> $la;
                    }
                    $ca = $bestCoverage[$a['id']] ?? 0.0;
                    $cb = $bestCoverage[$b['id']] ?? 0.0;
                    return $cb <=> $ca;
                });
            }
            if ($arm === 'D' && count($planGenomes) > 3) {
                $head = array_slice($planGenomes, 0, 2);
                $rest = array_slice($planGenomes, 2);
                $scoutKey = array_rand($rest);
                $scout = $rest[$scoutKey];
                unset($rest[$scoutKey]);
                $planGenomes = array_merge($head, [$scout], array_values($rest));
            }
            $attempts = 0; $passes = 0; $calls = 0; $completed = 0;
            $denials = 0; $partialEvals = 0; $newFactors = 0;
            $thetaBefore = []; $thetaAfter = [];
            $routeWall = 0.0;
            // resource-trigger (arm T): the channel breathes with the recent
            // cert-rate; cert-rate 0 over the last 3 gens -> +50% channel
            $channel = 32.0;
            $triggerMult = 1.0;
            if ($arm === 'T') {
                $recent = array_slice(array_column($genLog, 'certs'), -3);
                if (count($recent) === 3 && array_sum($recent) === 0) {
                    $triggerMult = 1.5;
                    $channel = 48.0;
                }
                $genLogTrigger = $triggerMult;
            } else {
                $genLogTrigger = null;
            }
            $genCerts = 0;
            foreach ($planGenomes as $g) {
                if ($routeWall >= $channel - 1.0) {
                    break;
                }
                $attempts++;
                $gid = (string) $g['id'];
                $line = $lineOf[$gid] ?? $gid;
                // per-line bootstrap: each genome's FIRST execution = forced
                $forceThis = $force || ! isset($everExecuted[$gid]);
                $share = min((float) $g['budget_fraction'] * STRAT_TOTAL_TASK_BUDGET, $channel - $routeWall);
                if ($share < 1.0) {
                    continue;
                }
                // ---- gate decision ----
                if ($arm === 'A') {
                    $thB = null; // A: fixed C1 theta, not logged per-hash
                    $st = $sensor->stateWindow('FLIGHT', PHP_INT_MAX - 1, STRAT_W);
                    $dec = RouteExecutor::conditionDecision($g, [
                        'task_class' => $taskClass,
                        'yield_t' => ($st['status'] === 'OK') ? (float) $st['Y'] : 0.0,
                        'prev_state' => (float) ($integratorState[$g['id']] ?? 0.0),
                        'n_obs' => (int) $st['n'],
                    ]);
                    $integratorState[$g['id']] = (float) $dec['state'];
                    $open = (bool) $dec['activated'];
                } else {
                    $thB = (float) ($thetaMap[$gid] ?? $theta0);
                    $thetaBefore[] = $thB;
                    $state = (float) ($stateMap[$gid] ?? 0.0);
                    $covLast = (float) ($bestCoverage[$gid] ?? 0.0);
                    // state smooths over the genome's own best coverage
                    $state = $alphaState * $state + (1.0 - $alphaState) * $covLast;
                    $stateMap[$gid] = $state;
                    $open = ($forceThis || $state >= $thB);
                }
                if (! $open) {
                    $denials++;
                    continue;
                }
                $passes++;
                $res = RouteExecutor::run($g, $task['_X'], $task['_y'],
                    array_map(fn (int $i): string => "c{$i}", range(0, count($task['_X'][0]) - 1)),
                    $share, 0.10, [], 1);
                $calls++;
                $everExecuted[$gid] = true;
                $routeWall += $res['wall'];
                if (($res['stop'] ?? '') !== 'budget_exhausted') {
                    $completed++;
                }
                // sensor feed (yield observations, C1-style)
                $prevBest = null;
                foreach ($res['records'] as $rec) {
                    if (($rec['op'] ?? '') !== 'FLIGHT') {
                        continue;
                    }
                    $lv = $rec['l_valid'] ?? null;
                    if ($prevBest !== null) {
                        $rAfter = ($lv !== null && $lv < $prevBest) ? (float) $lv : $prevBest;
                        $sensor->addObservation('FLIGHT', (float) $prevBest, $rAfter, max(0.001, (float) $rec['wall_s']));
                    }
                    if ($lv !== null) {
                        $prevBest = min($prevBest ?? PHP_FLOAT_MAX, (float) $lv);
                    }
                }
                // ---- partial credit: STRUCTURAL coverage on the executed form ----
                $partialEvals++;
                $form = $res['found'] ? (string) $res['cert_formula'] : (string) ($res['partial_form'] ?? 'none');
                if ($form !== '' && $form !== 'none') {
                    $fx = RouteExecutor::expandLocalAtoms(labels_to_x($form), $res['local_atoms']);
                    $curFp = hash('sha1', $fx);
                    $formFps[$curFp] = 1; // diversity counter (all arms)
                    if ($firstFormFp === null) {
                        $firstFormFp = $curFp;
                    }
                    if ($curFp !== $firstFormFp && $firstNewFormGen === null) {
                        $firstNewFormGen = $gen + 1;
                    }
                    $cov = heatStructuralCoverage($fx);
                    if ($cov > 0.0) {
                        $topFormLog[$gen] = ['id' => $gid, 'cov' => round($cov, 3), 'form' => substr($fx, 0, 80)];
                    }
                    // potential shaping (arm P): track the coverage DELTA
                    $lastDelta[$gid] = $cov - (float) ($prevCov[$gid] ?? 0.0);
                    $prevCov[$gid] = max((float) ($prevCov[$gid] ?? 0.0), $cov);
                    $prevBest2 = (float) ($bestCoverage[$gid] ?? 0.0);
                    if ($cov > $prevBest2) {
                        $bestCoverage[$gid] = $cov;
                        $newFactors++;
                        if ($arm === 'L') {
                            // the line's credit = max over its members
                            $lineCoverage[$line] = max((float) ($lineCoverage[$line] ?? 0.0), $cov);
                        }
                        if ($arm === 'B' || $arm === 'D') {
                            $progress = 1;
                            $thetaMap[$gid] = max($thetaMin, $thB - $dTheta * $progress);
                            $thetaAfter[] = $thetaMap[$gid];
                        }
                        if ($cov >= 1.0 && $fullFormGen === null) {
                            $fullFormGen = $gen + 1;
                        }
                    } elseif ($arm === 'B' || $arm === 'D') {
                        $thetaAfter[] = $thB; // no progress: theta unchanged
                    }
                }
                // ---- certification (separate from coverage, canon) ----
                if ($res['found']) {
                    $fx2 = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                    $noEval = null;
                    $v2 = cert_v2($fx2, $task['_X'], $task['_y'], (int) $task['seed'], $noEval);
                    if ($v2 !== null && $v2['pass']) {
                        $fp = hash('sha256', $fx2);
                        if (! in_array($fp, $certFingerprints, true)) {
                            $certFingerprints[] = $fp;
                            $genCerts++;
                            if ($firstCertGen === null) {
                                $firstCertGen = $gen + 1;
                            }
                        }
                    }
                }
                $ledger->recordOutcome($g['id'], 'heat', 0.0, false, $res['wall'], $res['unspent'], 0.0);
                // RETENTION: partial credit survives revocation; arm P =
                // potential-based: only PROGRESS (delta > 0 at the last
                // execution) survives — plateau champions lose protection
                if ($isRetentionArm) {
                    $survive = ($arm === 'L')
                        ? (($lineCoverage[$line] ?? 0.0) > 0.0 || ($lineCerts[$line] ?? 0) > 0)
                        : (($arm === 'P') ? (($lastDelta[$gid] ?? 0.0) > 0.0) : (($bestCoverage[$gid] ?? 0.0) > 0.0));
                    if ($survive) {
                        $ledger->retain($g['id']);
                    }
                }
            }
            if ($isRetentionArm) {
                // reproduction: retention by credit (desc), then ledger score;
                // B/D = genome-level credit; L = LINE-level credit (the unit =
                // genome + its road; the genome dies, the line survives);
                // Q = 20% immigration: after reproduction, the worst 20%
                // non-retained members are evicted and replaced by fresh randoms
                $pop = $ledger->active();
                $creditOf = function (array $g) use (&$bestCoverage, &$lineCoverage, &$lineOf, $arm): float {
                    if ($arm === 'L') {
                        return (float) ($lineCoverage[$lineOf[$g['id']] ?? $g['id']] ?? 0.0);
                    }
                    return (float) ($bestCoverage[$g['id']] ?? 0.0);
                };
                usort($pop, function (array $a, array $b) use (&$lineCerts, &$lineOf, $creditOf, $ledger, $arm): int {
                    if ($arm === 'L') {
                        $la = ($lineCerts[$lineOf[$a['id']] ?? $a['id']] ?? 0) * 10 + ($lineCoverage[$lineOf[$a['id']] ?? $a['id']] ?? 0.0);
                        $lb = ($lineCerts[$lineOf[$b['id']] ?? $b['id']] ?? 0) * 10 + ($lineCoverage[$lineOf[$b['id']] ?? $b['id']] ?? 0.0);
                        return $lb <=> $la;
                    }
                    return ($creditOf($b) <=> $creditOf($a)) ?: ($ledger->score($b) <=> $ledger->score($a));
                });
                $parents = array_slice($pop, 0, $arm === 'D' ? 2 : 3);
                if ($arm === 'D' && count($pop) > 2) {
                    $rest = array_slice($pop, 2);
                    $parents[] = $rest[array_rand($rest)];
                }
                foreach ($parents as $p) {
                    if (count($ledger->active()) >= STRAT_POP_N + 4) {
                        break;
                    }
                    $child = RouteMutator::mutate($p, mt_rand(0, 8));
                    if ($child === null) {
                        continue;
                    }
                    $ck = (string) ($child['condition']['kind'] ?? '');
                    if (! str_starts_with($ck, RouteGenome::CONDITION_INTEGRATOR_PREFIX)) {
                        $child['condition'] = ['kind' => RouteGenome::CONDITION_INTEGRATOR_PREFIX . 'PRODUCT',
                            'alpha' => STRAT_ALPHA, 'theta' => STRAT_THETA, 'n_min' => STRAT_N_MIN];
                    }
                    if (RouteGenome::validate($child) === []) {
                        $ledger->register($child);
                        // LINE inheritance (arm L): the child joins the parent's
                        // line and inherits its road credit at birth
                        $pline = $lineOf[$p['id']] ?? $p['id'];
                        $lineOf[$child['id']] = $pline;
                        if ($arm === 'L') {
                            $bestCoverage[$child['id']] = (float) ($lineCoverage[$pline] ?? 0.0);
                        }
                    }
                }
                if ($arm === 'Q') {
                    // 20% immigration: evict the worst non-covered 20%,
                    // inject fresh random shapes (the constant inflow)
                    $pop2 = $ledger->active();
                    usort($pop2, fn (array $a, array $b): int => ($bestCoverage[$b['id']] ?? 0.0) <=> ($bestCoverage[$a['id']] ?? 0.0));
                    $nSwap = max(1, (int) ceil(0.2 * count($pop2)));
                    foreach (array_slice($pop2, -$nSwap) as $v) {
                        if (($bestCoverage[$v['id']] ?? 0.0) <= 0.0) {
                            $ledger->revoke($v['id']);
                        }
                    }
                    for ($k2 = 0; $k2 < $nSwap; $k2++) {
                        $ng = RouteMutator::randomGenome();
                        $ng['budget_fraction'] = RouteGenome::BUDGET_MAX;
                        $ng['max_steps'] = count($ng['actions']);
                        if (RouteGenome::validate($ng) === []) {
                            $ledger->register($ng);
                            $lineOf[$ng['id']] = $ng['id'];
                        }
                    }
                }
            } else {
                foreach ($ledger->topForReproduction(3) as $p) {
                    if (count($ledger->active()) >= STRAT_POP_N + 4) {
                        break;
                    }
                    $child = RouteMutator::mutate($p, mt_rand(0, 8));
                    if ($child === null) {
                        continue;
                    }
                    $ck = (string) ($child['condition']['kind'] ?? '');
                    if (! str_starts_with($ck, RouteGenome::CONDITION_INTEGRATOR_PREFIX)) {
                        $child['condition'] = ['kind' => RouteGenome::CONDITION_INTEGRATOR_PREFIX . 'PRODUCT',
                            'alpha' => STRAT_ALPHA, 'theta' => STRAT_THETA, 'n_min' => STRAT_N_MIN];
                    }
                    if (RouteGenome::validate($child) === []) {
                        $ledger->register($child);
                    }
                }
            }
            $genLog[] = [
                'gen' => $gen + 1,
                'forced' => $force,
                'attempts' => $attempts,
                'gate_passes' => $passes,
                'executor_calls' => $calls,
                'completed_executions' => $completed,
                'gate_denials' => $denials,
                'partial_eval_count' => $partialEvals,
                'new_factor_count' => $newFactors,
                'route_wall' => round($routeWall, 1),
                'certs' => $genCerts,
                'theta_before' => $isRetentionArm ? (count($thetaBefore) ? [round(min($thetaBefore), 3), round(array_sum($thetaBefore) / count($thetaBefore), 3), round(max($thetaBefore), 3)] : null) : null,
                'theta_after' => $isRetentionArm ? (count($thetaAfter) ? [round(min($thetaAfter), 3), round(array_sum($thetaAfter) / count($thetaAfter), 3), round(max($thetaAfter), 3)] : null) : null,
                'best_coverage_top' => $isRetentionArm ? array_sum($bestCoverage) > 0 ? round(max($bestCoverage), 3) : 0.0 : null,
                'n_hashes_with_coverage' => $isRetentionArm ? count(array_filter($bestCoverage, fn (float $c): bool => $c > 0.0)) : null,
                'top_form' => $topFormLog[$gen] ?? null,
                'road_before_wipe' => $roadBeforeWipe,
                'trigger_mult' => $genLogTrigger,
            ];
            fwrite(STDERR, "[{$RUN_ID}] gen=" . ($gen + 1) . " attempts={$attempts} passes={$passes} completed={$completed} wall=" . round($routeWall, 1) . " certs={$genCerts}" . PHP_EOL);
        }
        $out = [
            'run_id' => $RUN_ID,
            'phase' => 'buffer1',
            'arm' => $arm,
            'seed' => $seed,
            'task' => 'heat',
            'gens' => $nGensB1,
            'params' => ['theta0' => $theta0, 'alpha_state' => $alphaState, 'd_theta' => $dTheta, 'theta_min' => $thetaMin],
            'gen_log' => $genLog,
            'total_route_wall' => round(array_sum(array_map(fn (array $g): float => (float) $g['route_wall'], $genLog)), 1),
            'total_certs' => array_sum(array_map(fn (array $g): int => (int) $g['certs'], $genLog)),
            'distinct_forms' => count($formFps),
            'first_new_form_gen' => $firstNewFormGen,
            'full_form_reached_gen' => $fullFormGen,
            'first_cert_gen' => $firstCertGen,
        ];
        $outPath = $outDir . '/buffer1_' . $arm . '_' . $seed . '.json';
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE {$RUN_ID}: totalWall={$out['total_route_wall']} certs={$out['total_certs']} fullForm={$fullFormGen} -> {$outPath}\n";
        break;
    // -------- STRATEGY-COMPOUND (owner GO 04.11, analyst handoff) --------
    case 'compound':
        // arms: O (heat->dot->D1 curriculum), F (fresh: D1 only), R (random genomes
        // on D1), S (shuffled experience: DN4 null -> D1). Primary: D1
        // time-to-first-cert + cert-rate at fixed gens. Road: kept within a
        // stage (same task env), wiped between stages (transfer channel = genome).
        $nGens = (int) (getenv('STRAT_CG_GENS') ?: 20);
        $stageTasks = match ($arm) {
            'O' => ['heat', 'dot', 'D1'],
            'F' => ['D1'],
            'R' => ['D1'],
            'S' => ['DN4', 'D1'],
            default => null,
        };
        if ($stageTasks === null) {
            fwrite(STDERR, "compound: arm must be O|F|R|S\n");
            exit(2);
        }
        mt_srand($seed);
        // STRATEGY-C2 variant: classmatch = CLASS_MATCH gates (no theta); the
        // C2-passing gate for the compounding rerun (owner pilot 05.11)
        $cgVariant = (string) (getenv('STRAT_CG_VARIANT') ?: 'integrator');
        $cgCond = fn (): array => $cgVariant === 'classmatch'
            ? ['kind' => RouteGenome::CONDITION_CLASSMATCH_PREFIX . STRAT_CLASSES[array_rand(STRAT_CLASSES)], 'n_min' => STRAT_N_MIN]
            : integratorCondition();
        $ledger = new RouteLedger();
        $sensor = new SensorState(STRAT_N_MIN);
        $integratorState = [];
        $certFingerprints = [];
        $evolutionOverhead = 0.0;
        // standard C2 seed: 4 hand ladders (classes cover the pool) + 4 random
        foreach (handLadders(array_map(
            fn (string $cls): string => $cgVariant === 'classmatch'
                ? RouteGenome::CONDITION_CLASSMATCH_PREFIX . $cls
                : RouteGenome::CONDITION_INTEGRATOR_PREFIX . $cls,
            ['SUM', 'RATIO', 'PRODUCT', 'SUM']
        )) as $lg) {
            $ledger->register($lg);
        }
        foreach (seedStrategyPopulation(STRAT_POP_N - 4) as $g) {
            $g['condition'] = $cgCond();
            $ledger->register($g);
        }
        $stages = [];
        $firstCertTickD1 = null;
        $d1Certs = 0;
        $d1Wall = 0.0;
        $d1Budget = 0.0;
        foreach ($stageTasks as $si => $taskId) {
            if ($taskId === 'DN4') {
                // null control: dot4 params + permuted y (experience without structure)
                $task = ['id' => 'DN4', 'class' => 'dot4', 'seed' => (int) hexdec(substr(md5('EXP045DEV' . 'DN4'), 0, 7)),
                    'perm' => range(0, 5), 'scale' => array_fill(0, 6, 1), 'noise' => 0.0, 'null_shuffle' => true];
                $csv = sys_get_temp_dir() . '/compound_DN4.csv';
                gen_family($task, $csv);
                [$X, $y] = parse_csv($csv);
                @unlink($csv);
                $task['_X'] = $X;
                $task['_y'] = $y;
                $taskClass = 'SUM'; // dot4's mapped class (null task; gate semantics as SUM)
            } else {
                $task = materializeTask($taskId, $seed);
                $taskClass = (string) $classOfTask($taskId);
            }
            $db->exec('DELETE FROM trail_marks'); // new env between stages
            $sensor = new SensorState(STRAT_N_MIN); // sensor reset per stage
            $integratorState = [];
            $stageCerts = 0;
            $stageWall = 0.0;
            $stageBudget = 0.0;
            for ($gen = 0; $gen < $nGens; $gen++) {
                // bootstrap gen-0 forced per stage (C2 slot-0 precedent): real
                // ladder flights feed the sensor; without it depth-1 probes on
                // depth>=2 laws produce zero observations and eat the channel
                $forceGates = ($gen === 0);
                if ($arm === 'R') {
                    // random control: fresh random population each gen, no evolution
                    $ledger = new RouteLedger();
                    foreach (seedStrategyPopulation(STRAT_POP_N) as $g) {
                        $ledger->register($g);
                    }
                }
                $slot = runSlot('A', $task, $taskClass, $seed, $gen, $ledger, $sensor, $integratorState, $certFingerprints, $forceGates, 0.0);
                $stageWall += (float) ($slot['route']['wall'] ?? 0.0);
                $stageBudget += (float) ($slot['route']['budget'] ?? 0.0);
                if ((bool) $slot['novel_cert']) {
                    $stageCerts++;
                    if ($taskId === 'D1' && $firstCertTickD1 === null) {
                        $firstCertTickD1 = $gen + 1;
                    }
                    if ($taskId === 'D1') {
                        $d1Certs++;
                        $d1Wall += (float) ($slot['route']['wall'] ?? 0.0);
                        $d1Budget += (float) ($slot['route']['budget'] ?? 0.0);
                    }
                }
                if ($arm !== 'R') {
                    $t0 = microtime(true);
                    foreach ($ledger->topForReproduction(3) as $p) {
                        if (count($ledger->active()) >= STRAT_POP_N + 4) {
                            break;
                        }
                        $child = RouteMutator::mutate($p, mt_rand(0, 8));
                        if ($child === null) {
                            continue;
                        }
                        $childKind = (string) ($child['condition']['kind'] ?? '');
                        $childOk = ($cgVariant === 'classmatch')
                            ? str_starts_with($childKind, RouteGenome::CONDITION_CLASSMATCH_PREFIX)
                            : str_starts_with($childKind, RouteGenome::CONDITION_INTEGRATOR_PREFIX);
                        if (! $childOk) {
                            $child['condition'] = $cgCond();
                        }
                        if (RouteGenome::validate($child) === []) {
                            $ledger->register($child);
                        }
                    }
                    $evolutionOverhead += microtime(true) - $t0;
                }
                fwrite(STDERR, "[{$RUN_ID}] {$taskId} gen=" . ($gen + 1) . "/{$nGens} novel=" . var_export((bool) $slot['novel_cert'], true) . PHP_EOL);
            }
            $snapshot = array_map(
                fn (array $g): string => genomeHash($g),
                $ledger->active()
            );
            $stages[] = [
                'task' => $taskId,
                'class' => $taskClass,
                'gens' => $nGens,
                'certs' => $stageCerts,
                'cert_rate' => round($stageCerts / max(1, $nGens), 3),
                'wall' => round($stageWall, 1),
                'budget' => round($stageBudget, 1),
                'strategy_snapshot' => $snapshot,
            ];
        }
        $out = [
            'run_id' => $RUN_ID,
            'phase' => 'compound',
            'arm' => $arm,
            'seed' => $seed,
            'gens_per_stage' => $nGens,
            'first_cert_tick_D1' => $firstCertTickD1, // null = never in 20 gens
            'cert_rate_D1' => round($d1Certs / max(1, $nGens), 3),
            'd1_wall' => round($d1Wall, 1),
            'd1_budget' => round($d1Budget, 1),
            'stages' => $stages,
            'evolution_overhead_wall' => round($evolutionOverhead, 3),
            'budget_total_wall' => round(array_sum(array_map(fn (array $s): float => (float) $s['wall'], $stages)), 1),
        ];
        $outPath = $outDir . '/compound_' . $arm . '_' . $seed . '.json';
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "DONE {$RUN_ID}: firstTickD1=" . ($firstCertTickD1 ?? 'never') . " rateD1={$out['cert_rate_D1']} -> {$outPath}\n";
        break;
    default:
        fwrite(STDERR, "unknown phase {$phase} (evolve|replayC|freeze|crosseval|compare|calibration|compare2)\n");
        exit(2);
}
