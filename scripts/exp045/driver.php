<?php

// EXP-045 ROUTE HEREDITY — DEV-драйвер (fork exp044/driver.php v4).
//
// Руки (спека §Руки DEV):
//   baseline  — обычный поиск, канал маршрутов выключен (80% бюджета)
//   random    — случайные маршруты при том же бюджете (контроль «просто больше кандидатов»)
//   evolved   — маршруты после отбора и межзадачного наследования
//   shuffled  — condition↔actions рассинхрон (нарушенное соответствие)
//   noinherit — механика evolved ВНУТРИ задачи, но без межзадачного накопления
//               (та же стартовая популяция каждый таск, record сброшен)
//
// Каналы: baseline 80% / маршруты 20% (калибровочная пропорция).
// Награда маршрута: delta = L_valid(baseline best partial) − L_valid(route best);
// win: delta > 0.05 ИЛИ независимая сертификация. revoke при 2 fail.
// Сертификация СТРОГАЯ и не менялась: движок + независимый withheld-срез (cv_eval ≤ 0.10).
//
// Usage: php exp045_driver.php <arm> <seed> <familiesJson> <outDir>
// Env: EXP045_TASK_BUDGET (default 40s), EXP045_POP_N (default 8)

$arm = $argv[1] ?? '';
$seed = (int) ($argv[2] ?? 0);
$famJson = $argv[3] ?? '';
$outDir = $argv[4] ?? '/tmp/exp045_out';
if (! in_array($arm, ['baseline', 'random', 'evolved', 'shuffled', 'noinherit'], true) || ! is_file($famJson)) {
    fwrite(STDERR, "usage: exp045_driver.php <arm> <seed> <familiesJson> <outDir>\n");
    exit(2);
}
@mkdir($outDir, 0777, true);

$TASK_BUDGET = (float) (getenv('EXP045_TASK_BUDGET') !== false ? getenv('EXP045_TASK_BUDGET') : '40.0');
$POP_N = (int) (getenv('EXP045_POP_N') !== false ? getenv('EXP045_POP_N') : '8');
$BASELINE_SHARE = 0.8; // 80/20 калибровка, спека §5
$RUN_ID = 'exp045_' . $arm . '_' . $seed;

$params = json_decode(file_get_contents($famJson), true);
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1'); // маршруты не пишут в глобальный словарь; birth выключен глобально
mt_srand($seed);
require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteLedger;
use BeeSwarm\Hive\RouteMutator;

$db = BeeSwarm\Infra\Database::get();
$labels = ['c0', 'c1', 'c2', 'c3', 'c4'];
$ledger = new RouteLedger();

// ---- генерация данных (классы = D-классы EXP-044) ----
function gen_family(array $p, string $path): void
{
    $s = $p['seed'];
    $r = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7fffffff;

        return $s / 0x7fffffff;
    };
    $u = function (float $a, float $b) use ($r): float {
        return $a + $r() * ($b - $a);
    };
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
        switch ($p['class']) {
            case 'flux': $y = 2.0 * ($c[2] - $c[1]) * $c[0] / $c[3]; break;
            case 'diffprod': $y = 1.5 * ($c[1] - $c[0]) * $c[0]; break;
            case 'ratio': $y = ($c[0] * $c[1]) / $c[3]; break;
            case 'composition': $y = ($c[0] - $c[1]) * ($c[0] - $c[1]) + $c[2] * $c[3]; break;
            case 'power': $y = $c[0] * $c[0] + $c[1] * $c[2]; break;
            case 'pureprod': $y = $c[0] * $c[3]; break;
            default: $y = 0.0;
        }
        if (! empty($p['noise'])) { $y *= 1 + ($r() - 0.5) * $p['noise']; }
        $row = [];
        foreach ($p['perm'] as $src) { $row[] = $c[$src]; }
        $row[] = $y;
        $rows[] = $row;
    }
    if (! empty($p['null_shuffle'])) {
        mt_srand($p['seed']);
        $ys = array_column($rows, 5);
        for ($i = count($ys) - 1; $i > 0; $i--) {
            $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
            [$ys[$i], $ys[$j]] = [$ys[$j], $ys[$i]];
        }
        foreach ($rows as $i => $row) { $rows[$i][5] = $ys[$i]; }
    }
    $f = fopen($path, 'w');
    foreach ($rows as $r2) { fputcsv($f, $r2); }
    fclose($f);
}

function parse_csv(string $path): array
{
    $X = []; $y = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $v = str_getcsv($line);
        if (count($v) < 6 || ! is_numeric($v[0])) { continue; }
        $X[] = array_map('floatval', array_slice($v, 0, 5));
        $y[] = (float) $v[5];
    }

    return [$X, $y];
}

/** Независимый 60/40 сплит по сиду (withheld 40% engine не видит). */
function split_eval(array $X, array $y, int $seed): array
{
    $n = count($y); $idx = range(0, $n - 1);
    mt_srand($seed);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $k = (int) floor($n * 0.6);
    $Xt = []; $yt = []; $Xe = []; $ye = [];
    foreach (array_slice($idx, 0, $k) as $i) { $Xt[] = $X[$i]; $yt[] = $y[$i]; }
    foreach (array_slice($idx, $k) as $i) { $Xe[] = $X[$i]; $ye[] = $y[$i]; }

    return [$Xt, $yt, $Xe, $ye];
}

function labels_to_x(string $formula): string
{
    $out = $formula;
    // RL-имена прячутся (sha1-гекс содержит 'c0'-подстроки), метки заменяются
    // ПРОСТОЙ заменой (склеенные 'c0minc2' конвертируются), имена возвращаются.
    $kept = [];
    $out = (string) preg_replace_callback('/RL[0-9a-f]{8}/', function ($m) use (&$kept) {
        $key = "\x00" . count($kept) . "\x00";
        $kept[$key] = $m[0];

        return $key;
    }, $out);
    $pairs = [];
    foreach (range(0, 4) as $i) { $pairs[] = ['c' . $i, 'x' . $i]; }
    usort($pairs, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));
    foreach ($pairs as [$c, $x]) { $out = str_replace($c, $x, $out); }

    return strtr($out, $kept);
}

/** Независимая сертификация на withheld-срезе, x-пространство (строгий критерий, = exp044). */
function cert_x(string $formulaX, array $Xe, array $ye): ?float
{
    $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($formulaX, $Xe);
    if (! is_array($vals) || count($vals) !== count($ye)) { return null; }
    $ratios = [];
    foreach ($vals as $i => $p) {
        if ($ye[$i] == 0.0 || ! is_numeric($p) || ! is_finite((float) $p)) { return null; }
        $ratios[] = (float) $p / $ye[$i];
    }
    $mean = array_sum($ratios) / count($ratios);
    $var = 0.0;
    foreach ($ratios as $rv) { $var += ($rv - $mean) ** 2; }
    $std = sqrt($var / count($ratios));

    return $mean != 0.0 ? $std / abs($mean) : null;
}

function cv_eval(string $formulaC, array $Xe, array $ye): ?float
{
    return cert_x(labels_to_x($formulaC), $Xe, $ye);
}

/** Базовый канал: вылеты с выключенными метками (гигиена baseline). */
function baseline_flights(array $X, array $y, float $budget): array
{
    $t0 = microtime(true); $flights = []; $found = false; $tFound = null; $formula = ''; $bestCv = 9.99;
    $g = BeeSwarm\Core\Grammar::fromOps(array_keys(BeeSwarm\Core\Grammar::BASE_OPS));
    for ($f = 1; $f <= 3; $f++) {
        $remain = $budget - (microtime(true) - $t0);
        if ($remain < 2.0) { break; }
        putenv('TRAIL_MARKS');
        $r = BeeSwarm\Core\Search::find($X, $y, $g, min(2 + $f - 1, 3), ['c0', 'c1', 'c2', 'c3', 'c4'], 0.2, 0.10, $remain);
        $flights[] = ['f' => $f, 'found' => (bool) $r[0],
            'cv' => is_numeric($r[1]) ? (float) $r[1] : 9.99,
            'formula' => is_string($r[2] ?? null) ? (string) $r[2] : ''];
        if ($r[0]) { $found = true; $tFound = round(microtime(true) - $t0, 1); $formula = (string) $r[2]; $bestCv = 0.0; break; }
        if (is_numeric($r[1]) && $r[1] < $bestCv) { $bestCv = (float) $r[1]; }
    }
    putenv('TRAIL_MARKS');

    return ['flights' => $flights, 'found' => $found, 't_first_found' => $tFound, 'formula' => $formula,
        'best_cv' => $bestCv, 'wall_total' => round(microtime(true) - $t0, 1)];
}

/** Лучшая ЧАСТИЧНАЯ гипотеза базового канала. Failure-возврат Search несёт
 * лучшую plausible-форму (r[2]) при принудительном cv=9.99 — экспозиция =
 * факт реального имени, качество судит computeL (блокер-2 ревью). */
function baseline_partial_L(array $bl, array $Xt, array $yt): ?float
{
    if ($bl['found']) { return null; } // сертификация — не «частичная гипотеза»
    foreach (array_reverse($bl['flights']) as $fl) {
        $fC = (string) $fl['formula'];
        if ($fC === '' || $fC === 'none') { continue; }
        return RouteExecutor::computeL(labels_to_x($fC), $Xt, $yt);
    }

    return null;
}

/** Персистенция генома и истории вылетов (блокер-4 ревью: таблицы были мёртвыми). */
function persist_genome(PDO $db, array $g): void
{
    static $st = null;
    $st ??= $db->prepare('INSERT OR REPLACE INTO route_genome (id, parent_id, generation, genome_json, created_tick) VALUES (?,?,?,?,?)');
    $st->execute([$g['id'], $g['parent_id'], $g['generation'], json_encode($g), (int) ($g['lineage']['born_tick'] ?? 0)]);
}

function persist_history(PDO $db, string $runId, string $taskFp, string $routeId, array $records): void
{
    static $st = null;
    $st ??= $db->prepare('INSERT INTO route_history (run_id, task_fp, route_id, seq, op, knobs, found, cv_exposed, l_train, l_valid, wall_s, created_tick) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($records as $rec) {
        $st->execute([$runId, $taskFp, $routeId, (int) $rec['seq'], (string) $rec['op'],
            (string) $rec['knobs'], (int) $rec['found'], $rec['cv_exposed'],
            $rec['l_train'], $rec['l_valid'], (float) $rec['wall_s'], 0]);
    }
}

// ---- популяция маршрутов по рукам ----
function seedPopulation(int $n): array
{
    $pop = [];
    for ($i = 0; $i < $n; $i++) {
        $g = RouteMutator::randomGenome();
        //DEV-посев: NO_CERT_AFTER-условия дают маршрутам шанс сработать на плато baseline
        if ($g['condition']['kind'] === 'ALWAYS' && mt_rand(0, 2) === 0) {
            $g['condition'] = ['kind' => 'NO_CERT_AFTER', 'flights' => mt_rand(1, 2)];
        }
        $pop[] = $g;
    }

    return $pop;
}

function shuffledGenome(): array
{
    // condition от одного генома, actions от другого — нарушенное соответствие (рука D)
    $a = RouteMutator::randomGenome();
    $b = RouteMutator::randomGenome();
    $a['condition'] = $b['condition'];
    $a['id'] = RouteMutator::newId();
    $a['parent_id'] = '';

    return $a;
}

$isRouteArm = $arm !== 'baseline';

// Стартовая популяция (noinherit/evolved/shuffled фиксируются на старте процесса — детерминизм)
$frozenSeed = null;
if ($isRouteArm && in_array($arm, ['evolved', 'noinherit', 'shuffled'], true)) {
    $frozenSeed = [];
    for ($i = 0; $i < $POP_N; $i++) {
        $frozenSeed[] = $arm === 'shuffled' ? shuffledGenome() : seedPopulation(1)[0];
    }
}

$taskResults = [];
$nullResults = [];
foreach ($params['tasks'] as $tIdx => $t) {
    $isNull = ! empty($t['null_shuffle']);
    $csv = sys_get_temp_dir() . '/exp045_' . $t['id'] . '_' . $seed . '.csv';
    gen_family($t, $csv);
    [$Xall, $yall] = parse_csv($csv);
    [$X, $y, $Xe, $ye] = split_eval($Xall, $yall, $t['seed'] + $seed);
    $taskFp = 'sha1:' . sha1(json_encode(['labels' => $labels, 'id' => $t['id']]));

    // Trail-гигиена задачи (ревью deleg_1cae7b13): метки живут в :memory:
    // весь процесс, read-scope = labels-only (одинаков для всех задач) —
    // без очистки USE_MARKS читает дороги ПРЕДЫДУЩИХ задач, ломая контраст
    // noinherit (популяция сброшена, дорожная память — нет).
    $db->exec('DELETE FROM trail_marks');

    // ---- канал baseline (80%) ----
    $blBudget = $TASK_BUDGET * $BASELINE_SHARE;
    $bl = baseline_flights($X, $y, $blBudget);
    $cvE = $bl['found'] ? cv_eval($bl['formula'], $Xe, $ye) : null;
    $blCertified = $bl['found'] && $cvE !== null && $cvE <= 0.10;
    $baselineState = ['flights_done' => count($bl['flights']), 'certified' => $blCertified];
    $Lbase = baseline_partial_L($bl, array_slice($X, (int) floor(count($y) * 0.8)), array_slice($y, (int) floor(count($y) * 0.8)));

    // ---- канал маршрутов (20%) ----
    $routeWall = 0.0;
    $routeRows = [];
    $routeCertifiedAny = false;
    if ($isRouteArm) {
        // Политика популяции этой задачи
        if ($arm === 'random') {
            $population = seedPopulation($POP_N);
        } elseif ($arm === 'noinherit') {
            $population = [];
            foreach ($frozenSeed as $g0) {
                $g = $g0;
                $g['id'] = RouteMutator::newId(); // новое «тело», та же структура
                $g['parent_id'] = '';
                $g['record'] = ['tasks_seen' => 0, 'delta_L_valid_sum' => 0.0, 'wins' => 0, 'fails' => 0, 'wall_sum' => 0.0, 'unspent_quota' => 0.0];
                $population[] = $g;
            }
        } elseif ($arm === 'shuffled') {
            $population = [];
            foreach ($frozenSeed as $g0) {
                $g = $g0;
                $g['id'] = RouteMutator::newId();
                $g['record'] = ['tasks_seen' => 0, 'delta_L_valid_sum' => 0.0, 'wins' => 0, 'fails' => 0, 'wall_sum' => 0.0, 'unspent_quota' => 0.0];
                $population[] = $g;
            }
        } else { // evolved: живая популяция ledger'а
            $population = $ledger->active();
            if (count($population) < $POP_N) {
                foreach (seedPopulation($POP_N - count($population)) as $gNew) {
                    $population[] = $gNew;
                    $ledger->register($gNew);
                }
            }
        }

        $channelBudget = $TASK_BUDGET * (1.0 - $BASELINE_SHARE);
        foreach ($population as $g) {
            if ($routeWall >= $channelBudget - 0.5) { break; }
            if (! $ledger->get($g['id'])) { $ledger->register($g); }
            persist_genome($db, $g);
            if ($ledger->isRevoked($g['id'])) { continue; }
            if (! RouteExecutor::shouldActivate($g, $baselineState)) {
                $routeRows[] = ['id' => $g['id'], 'activated' => false];
                continue;
            }
            // ЕДИНИЦЫ ОДНИ (блокер-1 ревью): доля × бюджет задачи vs остаток канала в секундах.
            $share = min((float) $g['budget_fraction'] * $TASK_BUDGET, $channelBudget - $routeWall);
            if ($share < 1.0) { continue; } // слишком малая доля — пропустить, не убивать популяцию
            $res = RouteExecutor::run($g, $X, $y, $labels, $share, 0.10);
            $routeWall += $res['wall'];
            persist_history($db, $RUN_ID, $taskFp, $g['id'], $res['records']);
            // Независимая сертификация с раскрытием route-local атомов (блокер-3):
            // evaluator не знает RL-имена — расширяем до инфикса до оценки.
            $rcv = null;
            if ($res['found']) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $rcv = cert_x($fx, $Xe, $ye);
            }
            $rCert = $res['found'] && $rcv !== null && $rcv <= 0.10;
            $routeCertifiedAny = $routeCertifiedAny || $rCert;
            // Win-семантика спеки: сертификация, КОТОРОЙ НЕТ у baseline (ревью).
            $routeWinCert = $rCert && ! $blCertified;
            $Lroute = $res['best_L_valid'];
            $delta = ($Lbase !== null && $Lroute !== null) ? $Lbase - $Lroute : 0.0;
            $ledger->recordOutcome($g['id'], $taskFp, $delta, $routeWinCert, $res['wall'], $res['unspent']);
            $routeRows[] = ['id' => $g['id'], 'parent' => $g['parent_id'], 'generation' => $g['generation'],
                'activated' => true, 'delta' => round($delta, 4), 'certified' => $rCert,
                'best_L_valid' => $Lroute !== null ? round($Lroute, 4) : null,
                'wall' => round($res['wall'], 2), 'unspent' => round($res['unspent'], 2),
                'stop' => $res['stop'], 'actions' => count($res['records'])];
        }

        // Межзадачное наследование: размножение только у evolved (shuffled/noinherit —
        // фикс-популяции; random — пересев) — различие рук по спеке §7
        if ($arm === 'evolved') {
            $parents = $ledger->topForReproduction(3);
            foreach ($parents as $p) {
                if (count($ledger->active()) >= $POP_N + 4) { break; }
                $type = mt_rand(0, 4);
                $child = RouteMutator::mutate($p, $type);
                if ($child !== null) { $ledger->register($child); }
            }
        }
    }

    $row = ['id' => $t['id'], 'class' => $t['class'], 'null' => $isNull,
        'baseline' => ['found' => $bl['found'], 'certified' => $blCertified, 'cv_eval' => $cvE,
            'formula' => $bl['formula'], 'best_cv' => $bl['best_cv'], 'L_partial' => $Lbase,
            'wall' => $bl['wall_total'], 'flights' => count($bl['flights'])],
        'routes' => $routeRows, 'route_certified' => $routeCertifiedAny,
        'route_wall_share' => round($routeWall / max(0.001, $TASK_BUDGET), 3),
        'certified_any' => $blCertified || $routeCertifiedAny];
    if ($isNull) { $nullResults[] = $row; } else { $taskResults[] = $row; }
    fwrite(STDERR, "[{$RUN_ID}] {$t['id']} blFound=" . var_export($bl['found'], true)
        . ' blCert=' . var_export($blCertified, true)
        . ' routeCert=' . var_export($routeCertifiedAny, true)
        . ' routeShare=' . $row['route_wall_share'] . PHP_EOL);
    @unlink($csv);
}

// ---- итоги ----
$taskN = count($taskResults);
$blCertN = count(array_filter($taskResults, fn ($r) => $r['baseline']['certified']));
$routeCertN = count(array_filter($taskResults, fn ($r) => $r['route_certified']));
$fpr = count(array_filter($nullResults, fn ($r) => $r['certified_any']));
$out = [
    'run_id' => $RUN_ID, 'arm' => $arm, 'seed' => $seed,
    'task_budget' => $TASK_BUDGET, 'pop_n' => $POP_N,
    'tasks' => $taskResults, 'nulls' => $nullResults,
    'summary' => ['tasks' => $taskN, 'baseline_certified' => $blCertN,
        'route_certified' => $routeCertN, 'any_certified' => count(array_filter($taskResults, fn ($r) => $r['certified_any'])),
        'fpr_nulls' => $fpr . '/' . count($nullResults)],
    'population_export' => $isRouteArm ? $ledger->export() : null,
];
$outPath = $outDir . '/results_' . $arm . '_' . $seed . '.json';
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "DONE {$RUN_ID}: blCert={$blCertN}/{$taskN} routeCert={$routeCertN}/{$taskN} FPR={$fpr}/" . count($nullResults) . " → {$outPath}\n";
