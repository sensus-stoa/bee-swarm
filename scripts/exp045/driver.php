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

// WU DRIVER-CERT-V2 (01.11): cert-функции вынесены в cert_utils.php
// (единый источник; driver был без main-guard — require из тестов
// небезопасен). Здесь: require + гейт v2 (:~640). Сертификация теперь
// ТРОЙНОЙ сплит (60/40, taskSeed+sp*104729, sp=0,1,2), все три ≤ 0.10.
require_once __DIR__ . '/cert_utils.php';

$arm = $argv[1] ?? '';
$seed = (int) ($argv[2] ?? 0);
$famJson = $argv[3] ?? '';
$outDir = $argv[4] ?? '/tmp/exp045_out';
if (! in_array($arm, ['baseline', 'random', 'evolved', 'shuffled', 'noinherit', 'ordershuf', 'evolvednm'], true) || ! is_file($famJson)) {
    fwrite(STDERR, "usage: exp045_driver.php <arm> <seed> <familiesJson> <outDir>\n");
    exit(2);
}
@mkdir($outDir, 0777, true);

$TASK_BUDGET = (float) (getenv('EXP045_TASK_BUDGET') !== false ? getenv('EXP045_TASK_BUDGET') : '40.0');
$POP_N = (int) (getenv('EXP045_POP_N') !== false ? getenv('EXP045_POP_N') : '8');
$BASELINE_SHARE = 0.2; // AMENDMENT_STAGE_A (owner, 20.10): 0.8→0.2 одной строкой —
// route-канал 8s→32s/задача (road-механизм требует ~45s, AUDIT/pilot: 8s = 87% unspent).
// Сравнимость с dev_run2 потеряна by design; сравнение — только внутри кампании.
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
// BUGFIX 31.10: labels пересчитываются per-task по ширине X (канон
// probe_loop:336) — dot4 несёт 6 фич, жёсткая пятёрка делала 6-ю колонку
// невидимой поиску (закон был невыразим). Выполняется после загрузки X.
$ledger = new RouteLedger();

// ---- генерация данных (классы = D-классы EXP-044) ----
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
        // PREREG_STAGE_A (флаг owner): dot4 — 7-я задача кампании (headroom:
        // baseline 8s не сертифицирует, дорога 22/24). 6-я фича для 4-продуктного
        // закона (тот же генератор, что probe_loop dot4_task).
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
    if (! empty($p['null_shuffle'])) {
        mt_srand($p['seed']);
        $yIdx = count($rows[0]) - 1; // y — последняя колонка (dot4: 6 фич → index 6)
        $ys = array_column($rows, $yIdx);
        for ($i = count($ys) - 1; $i > 0; $i--) {
            $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
            [$ys[$i], $ys[$j]] = [$ys[$j], $ys[$i]];
        }
        foreach ($rows as $i => $row) {
            $rows[$i][$yIdx] = $ys[$i];
        }
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
        if (count($v) < 6 || ! is_numeric($v[0])) {
            continue;
        }
        // BUGFIX 31.10 (0h-check owner: «для 5-10 из 44 — почему eval null»):
        // жёсткий slice(0,5)+y=v[5] — под 5-фичевые классы. dot4 = 7 колонок:
        // X терял 6-ю фичу, y становился генераторной фичей c[perm[5]]
        // (независимый шум) — вся e2-кампания предсказывала не задачу.
        // Канон probe_loop:336 — ширина по данным, y = последняя колонка.
        $nFeat = count($v) - 1;
        $X[] = array_map('floatval', array_slice($v, 0, $nFeat));
        $y[] = (float) $v[$nFeat];
    }

    return [$X, $y];
}

// split_eval, labels_to_x, cert_x, cv_eval — вынесены в cert_utils.php
// (WU DRIVER-CERT-V2, 01.11). Вызовы без изменений.

/**
 * Базовый канал: вылеты с выключенными метками (гигиена baseline).
 */
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
        $r = BeeSwarm\Core\Search::find($X, $y, $g, min(2 + $f - 1, 3), ['c0', 'c1', 'c2', 'c3', 'c4'], 0.2, 0.10, $remain);
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

/** Лучшая ЧАСТИЧНАЯ гипотеза базового канала. Failure-возврат Search несёт
 * лучшую plausible-форму (r[2]) при принудительном cv=9.99 — экспозиция =
 * факт реального имени, качество судит computeL (блокер-2 ревью). */
function baseline_partial_L(array $bl, array $Xt, array $yt): ?float
{
    // Stage A fitness fix (PREREG_STAGE_A_EVOLUTION §2.2-в): СТАРАЯ семантика
    // «found → null» делала дельту структурно мёртвой на baseline-сертифицированных
    // задачах (5/6) — score ≡ −complexity, отбора не было (AUDIT: delta≡0 на 54/54).
    // Новая: сертифицированный baseline отдаёт L СВОЕГО закона — маршрут должен
    // ПРЕВЗОЙТИ базовый закон, иначе дельты нет.
    if ($bl['found']) {
        $fC = (string) ($bl['formula'] ?? '');
        if ($fC === '' || $fC === 'none') {
            return null; // found без формулы — сравнивать не с чем
        }

        return RouteExecutor::computeL(labels_to_x($fC), $Xt, $yt);
    }
    foreach (array_reverse($bl['flights']) as $fl) {
        $fC = (string) $fl['formula'];
        if ($fC === '' || $fC === 'none') {
            continue;
        }
        return RouteExecutor::computeL(labels_to_x($fC), $Xt, $yt);
    }

    return null;
}

/**
 * Персистенция генома и истории вылетов (блокер-4 ревью: таблицы были мёртвыми).
 */
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
    // AMENDMENT_STAGE_A Stage 2 (флаг owner): HAND_GENOME_JSON — hand-crafted
    // популяция для контрфактического теста функциональности (Stage 2):
    // фиксированные геномы, ноль эволюции/наследования, дорога переключается
    // env (TRAIL_MARKS/TRAIL_FIX). Вопрос: road даёт cert, которого нет без
    // road, на ИДЕНТИЧНОМ геноме?
    $handPath = getenv('HAND_GENOME_JSON');
    if ($handPath !== false && $handPath !== '' && is_file($handPath)) {
        $hand = json_decode(file_get_contents($handPath), true);
        if (! is_array($hand)) {
            fwrite(STDERR, "HAND_GENOME_JSON: невалидный JSON\n");
            exit(3);
        }
        $pop = [];
        foreach ($hand as $g) {
            // id из hand-JSON СОХРАНЯЕТСЯ (атрибуция routeRows ↔ геном напрямую);
            // валидные hex-id: route_[0-9a-f]{4,}.
            if (($g['id'] ?? '') === '' || preg_match('/^route_[0-9a-f]{4,}$/', (string) $g['id']) !== 1) {
                $g['id'] = RouteMutator::newId();
            }
            $err = RouteGenome::validate($g);
            if ($err !== []) {
                fwrite(STDERR, 'HAND GENOME INVALID: ' . json_encode($err) . PHP_EOL);
                exit(3);
            }
            $pop[] = $g;
        }
        return $pop;
    }
    // PREREG_STAGE_A §2.2 (лестница пилота): SEED_MIN_ACTIONS/SEED_BF —
    // env-кнопки шага 1 (борьба с unspent 50-60% dev_run2). Дефолт = 1/нет
    // (повторяет dev_run2 для честного пилот-1). SEED_BF: '1' = BUDGET_MAX,
    // число (напр. '0.10') = фикс bf (E1 road-room: share 45s/маршрут).
    $minA = max(1, (int) (getenv('SEED_MIN_ACTIONS') ?: 1));
    $seedBfRaw = getenv('SEED_BF');
    $forceMaxBf = $seedBfRaw === '1';
    $fixedBf = ($seedBfRaw !== false && $seedBfRaw !== '' && $seedBfRaw !== '1' && is_numeric($seedBfRaw))
        ? (float) $seedBfRaw : null;
    $pop = [];
    for ($i = 0; $i < $n; $i++) {
        $g = RouteMutator::randomGenome();
        // DEV-посев: NO_CERT_AFTER-условия дают маршрутам шанс сработать на плато baseline
        if ($g['condition']['kind'] === 'ALWAYS' && mt_rand(0, 2) === 0) {
            $g['condition'] = [
                'kind' => 'NO_CERT_AFTER',
                'flights' => mt_rand(1, 2),
            ];
        }
        while (count($g['actions']) < $minA) {
            $g['actions'][] = RouteMutator::randomAction();
        }
        $g['max_steps'] = count($g['actions']);
        if ($forceMaxBf) {
            $g['budget_fraction'] = RouteGenome::BUDGET_MAX;
        }
        if ($fixedBf !== null) {
            $g['budget_fraction'] = round(max(RouteGenome::BUDGET_MIN, min(RouteGenome::BUDGET_MAX, $fixedBf)), 4);
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

// PREREG_STAGE_A §3 (флаг owner): order-shuffle рука — rotate-left-1 действий
// frozen seed'а (та же трансформация, что shuffled-контроль transfer-пробы):
// состав и бюджет идентичны, порядок нарушен (первое действие — чтение
// пустой дороги для read-after-write геномов). Закрывает order sensitivity
// для mixed-исхода §5.0.
function orderShufGenome(array $g): array
{
    $acts = $g['actions'];
    if (count($acts) > 1) {
        $first = array_shift($acts);
        $acts[] = $first;
    }
    $g['actions'] = array_values($acts);
    $g['max_steps'] = count($g['actions']);
    $g['id'] = RouteMutator::newId();
    $g['parent_id'] = '';
    $g['record'] = [
        'tasks_seen' => 0,
        'delta_L_valid_sum' => 0.0,
        'wins' => 0,
        'fails' => 0,
        'wall_sum' => 0.0,
        'unspent_quota' => 0.0,
    ];

    return $g;
}

$isRouteArm = $arm !== 'baseline';

// Стартовая популяция (noinherit/evolved/shuffled фиксируются на старте процесса — детерминизм)
$frozenSeed = null;
if ($isRouteArm && in_array($arm, ['evolved', 'noinherit', 'shuffled', 'ordershuf'], true)) {
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
    // BUGFIX 31.10: labels per-task по ширине X (dot4 = c0..c5). Жёсткая
    // пятёрка делала 6-ю колонку невидимой — полный закон был невыразим.
    $labels = array_map(fn (int $i): string => "c{$i}", range(0, count($X[0]) - 1));
    $taskFp = 'sha1:' . sha1(json_encode([
        'labels' => $labels,
        'id' => $t['id'],
    ]));

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
    $baselineState = [
        'flights_done' => count($bl['flights']),
        'certified' => $blCertified,
    ];
    $Lbase = baseline_partial_L($bl, array_slice($X, (int) floor(count($y) * 0.8)), array_slice($y, (int) floor(count($y) * 0.8)));

    // ---- канал маршрутов (20%) ----
    $routeWall = 0.0;
    $routeRows = [];
    // AMENDMENT_STAGE_A re-pilot телеметрия (§2.2 гейт: unspent<30%,
    // written>0, m3>0): written/injected/m3 на уровне маршрута и задачи.
    $writtenBy = []; // formula => seq первого записавшего маршрута
    $routeSeq = 0;
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
                $g['record'] = [
                    'tasks_seen' => 0,
                    'delta_L_valid_sum' => 0.0,
                    'wins' => 0,
                    'fails' => 0,
                    'wall_sum' => 0.0,
                    'unspent_quota' => 0.0,
                ];
                $population[] = $g;
            }
        } elseif ($arm === 'shuffled') {
            $population = [];
            foreach ($frozenSeed as $g0) {
                $g = $g0;
                $g['id'] = RouteMutator::newId();
                $g['record'] = [
                    'tasks_seen' => 0,
                    'delta_L_valid_sum' => 0.0,
                    'wins' => 0,
                    'fails' => 0,
                    'wall_sum' => 0.0,
                    'unspent_quota' => 0.0,
                ];
                $population[] = $g;
            }
        } elseif ($arm === 'ordershuf') {
            // PREREG_STAGE_A §3: order-shuffle frozen seed'а (rotate-left-1).
            $population = [];
            foreach ($frozenSeed as $g0) {
                $population[] = orderShufGenome($g0);
            }
        } elseif ($arm === 'evolvednm') {
            // PREREG_STAGE_A §5.1 (флаг owner): evolved-nomarks — ТА ЖЕ динамика
            // эволюции (ledger, наследование, репродукция), но исполнение маршрутов
            // с marksMode=0: ни записи, ни чтения дороги (вся стигмергическая
            // прослойка выключена; off-семантика P1). Контроль plain-vs-stigmergy:
            // evolvednm ≈ evolved → plain (config); evolvednm ≪ evolved → stigmergic.
            // Отклонение от формулировки owner «k=0»: k=0 схемой запрещён
            // (RouteGenome::validate k 1..10); marksMode=0 — поддерживаемый
            // honest-ноль (TRAIL_MARKS=0 → нет записи И чтения).
            $population = $ledger->active();
            if (count($population) < $POP_N) {
                foreach (seedPopulation($POP_N - count($population)) as $gNew) {
                    $population[] = $gNew;
                    $ledger->register($gNew);
                }
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
            if ($routeWall >= $channelBudget - 0.5) {
                break;
            }
            if (! $ledger->get($g['id'])) {
                $ledger->register($g);
            }
            persist_genome($db, $g);
            if ($ledger->isRevoked($g['id'])) {
                continue;
            }
            if (! RouteExecutor::shouldActivate($g, $baselineState)) {
                $routeRows[] = [
                    'id' => $g['id'],
                    'activated' => false,
                ];
                continue;
            }
            // ЕДИНИЦЫ ОДНИ (блокер-1 ревью): доля × бюджет задачи vs остаток канала в секундах.
            $share = min((float) $g['budget_fraction'] * $TASK_BUDGET, $channelBudget - $routeWall);
            if ($share < 1.0) {
                continue;
            } // слишком малая доля — пропустить, не убивать популяцию
            $res = RouteExecutor::run($g, $X, $y, $labels, $share, 0.10, [], ($arm === 'evolvednm' || getenv('ROAD_MARKS_OFF') === '1') ? 0 : 1);
            $routeWall += $res['wall'];
            // AMENDMENT_STAGE_A: written/injected/m3 этого маршрута (гейт re-pilot)
            $routeSeq++;
            $tw = [];
            $ti = [];
            $junkCount = 0; // MATERIAL QUALITY: junk метки (min/max композиции)
            foreach ($res['records'] as $rec) {
                if (($rec['op'] ?? '') !== 'FLIGHT') {
                    continue;
                }
                foreach (($rec['trail']['written'] ?? []) as $wf) {
                    $tw[] = $wf;
                    // MATERIAL QUALITY: junk = min/max композиция (не 2-term sum)
                    $is2TermSum = substr_count($wf, '+') === 1 && str_contains($wf, '×') && ! str_contains($wf, 'min') && ! str_contains($wf, 'max');
                    if (! $is2TermSum) {
                        $junkCount++;
                    }
                }
                foreach (($rec['trail']['read_injected'] ?? []) as $rf) {
                    $ti[] = $rf;
                }
            }
            // MATERIAL QUALITY: junk_fraction = доля junk меток среди записанных
            $junkFraction = count($tw) > 0 ? $junkCount / count($tw) : 0.0;
            $m3Here = 0;
            foreach ($ti as $rf) {
                if (isset($writtenBy[$rf]) && $writtenBy[$rf] < $routeSeq) {
                    $m3Here++;
                }
            }
            foreach ($tw as $wf) {
                if (! isset($writtenBy[$wf])) {
                    $writtenBy[$wf] = $routeSeq;
                }
            }
            persist_history($db, $RUN_ID, $taskFp, $g['id'], $res['records']);
            // Независимая сертификация с раскрытием route-local атомов (блокер-3):
            // evaluator не знает RL-имена — расширяем до инфикса до оценки.
            $rcv = null;
            // WU PARTIAL-EVAL: не-found маршруты получают eval-CV своей
            // лучшей partial-формы (train-eval gap телеметрии; рецензент
            // п.3). found-маршруты — cert-канал, partial не нужен.
            $pcv = null;
            $noEvalReason = null;
            $fxExpanded = null;
            $certV2 = null; // CANON V2: тройной сплит-гейт (found-ветка)
            if ($res['found']) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $fxExpanded = $fx; // 0h no_eval: форма после раскрытия — видна в JSON
                $rcv = cert_x($fx, $Xe, $ye, $noEvalReason); // телеметрия: кампанийный слот
                // CANON V2 (owner strict 01.11): сертификация = ТРОЙНОЙ
                // независимый 60/40-сплит (taskSeed+sp*104729), все три
                // ≤ 0.10. Демонстрация one-split уязвимости: E3 1/5,
                // E2 2/3 — §7.x paper. rcv выше — телеметрия, не гейт.
                $certV2 = cert_v2($fx, $Xall, $yall, (int) $t['seed'], $noEvalReason);
            } elseif (($res['partial_form'] ?? 'none') !== 'none') {
                $pfx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['partial_form']), $res['local_atoms']);
                $pcv = cert_x($pfx, $Xe, $ye);
            }
            $rCert = $res['found'] && $certV2 !== null && $certV2['pass'];
            $routeCertifiedAny = $routeCertifiedAny || $rCert;
            // WU SELECTION-CEIL логгер (reviewer 31.10 п.4): cert_bucket +
            // is_rk для КАЖДОЙ activated-строки — R/K-доля в found-bucket
            // (P0-флаг: >20% = bucket отравлен), бакет сертификации.
            // found-формы: rcv; не-found: pcv (partial-канал).
            $evalCv = $res['found'] ? $rcv : $pcv;
            if ($evalCv === null) {
                $certBucket = 'no_eval';
            } elseif ($evalCv <= 0.10) {
                $certBucket = 'cert_zone';
            } elseif ($evalCv <= 0.15) {
                $certBucket = 'near_10_15';
            } else {
                $certBucket = 'far_gt15';
            }
            $effForm = $res['found'] ? (string) $res['cert_formula'] : (string) ($res['partial_form'] ?? 'none');
            $isRK = $effForm !== 'none' && preg_match('/R(min|max|range|mean|sum|cnt)[0-9a-z]*/i', $effForm) === 1;
            // AMENDMENT_STAGE_A §2.3: cert_use bump для меток, участвовавших
            // в сертифицированном законе (EXP-037 guard: только cert-использование).
            if ($rCert) {
                \BeeSwarm\Core\Search::bumpCertUse($labels, (string) $res['cert_formula']);
            }
            // Win-семантика спеки: сертификация, КОТОРОЙ НЕТ у baseline (ревью).
            $routeWinCert = $rCert && ! $blCertified;
            $Lroute = $res['best_L_valid'];
            $delta = ($Lbase !== null && $Lroute !== null) ? $Lbase - $Lroute : 0.0;
            $ledger->recordOutcome($g['id'], $taskFp, $delta, $routeWinCert, $res['wall'], $res['unspent'], (float) \BeeSwarm\Core\Search::$lastTrailTopKUseful);
            $routeRows[] = [
                'id' => $g['id'],
                'parent' => $g['parent_id'],
                'generation' => $g['generation'],
                'activated' => true,
                'delta' => round($delta, 4),
                'certified' => $rCert,
                'best_L_valid' => $Lroute !== null ? round($Lroute, 4) : null,
                'best_cv_seen' => $res['best_cv_seen'] ?? null, // WU TELEMETRY-CV-SEEN
                'partial_cv_eval' => $pcv !== null ? round($pcv, 4) : null, // WU PARTIAL-EVAL
                'partial_form' => $res['found'] ? null : ($res['partial_form'] ?? null), // WU SELECTION-CEIL: fingerprint форм (E3-в-траектории?)
                'found' => $res['found'],
                'cv_eval_found' => $res['found'] && $rcv !== null ? round($rcv, 4) : null,
                // 0h no_eval (25 found без измерения): причина null + формы
                // (raw cert_formula и после expandLocalAtoms) — late-wiring
                // vs structural различимо по JSON без ре-прогона.
                'cert_formula_raw' => $res['found'] ? (string) $res['cert_formula'] : null,
                'cert_formula_x' => $res['found'] ? $fxExpanded : null,
                'no_eval_reason' => $res['found'] && $rcv === null ? $noEvalReason : null,
                'cert_bucket' => $certBucket, // WU SELECTION-CEIL (reviewer п.4)
                'cert_v2_cvs' => $certV2 !== null ? array_map(fn ($v) => $v === null ? null : round($v, 4), $certV2['cvs']) : null, // CANON V2
                'cert_v2_pass' => $certV2 !== null ? $certV2['pass'] : null, // CANON V2 (strict 3/3)
                'is_rk' => $isRK,
                'wall' => round($res['wall'], 2),
                'unspent' => round($res['unspent'], 2),
                'stop' => $res['stop'],
                'actions' => count($res['records']),
                'written' => count($tw),
                'injected' => count($ti),
                'm3' => $m3Here,
                'junk_fraction' => round($junkFraction, 3), // MATERIAL QUALITY: доля junk меток
                'confirmed_seen' => \BeeSwarm\Core\Search::$lastTrailConfirmedSeen,
                'road_share' => round(\BeeSwarm\Core\Search::$lastTrailRoadShare, 3),
                'topk_useful' => \BeeSwarm\Core\Search::$lastTrailTopKUseful,
                'topk_junk' => \BeeSwarm\Core\Search::$lastTrailTopKJunk,
                'road_size' => \BeeSwarm\Core\Search::$lastTrailRoadSize,
                'read_k' => \BeeSwarm\Core\Search::$lastTrailReadK,
                'cross_fp_reads' => \BeeSwarm\Core\Search::$lastTrailCrossFpReads,
                'similarity_filtered' => \BeeSwarm\Core\Search::$lastTrailSimilarityFiltered,
                'material_filtered' => \BeeSwarm\Core\Search::$lastTrailMaterialFiltered,
            ];
        }

        // Межзадачное наследование: размножение только у evolved/evolvednm (shuffled/noinherit —
        // фикс-популяции; random — пересев) — различие рук по спеке §7
        if ($arm === 'evolved' || $arm === 'evolvednm') {
            $parents = $ledger->topForReproduction(3);
            foreach ($parents as $p) {
                if (count($ledger->active()) >= $POP_N + 4) {
                    break;
                }
                $type = mt_rand(0, 5); // WU GROW: 6-й тип — рост длины генома (прекондиция B++)
                $child = RouteMutator::mutate($p, $type);
                if ($child !== null) {
                    $ledger->register($child);
                }
            }
        }
    }

    $row = [
        'id' => $t['id'],
        'class' => $t['class'],
        'null' => $isNull,
        'baseline' => [
            'found' => $bl['found'],
            'certified' => $blCertified,
            'cv_eval' => $cvE,
            'formula' => $bl['formula'],
            'best_cv' => $bl['best_cv'],
            'L_partial' => $Lbase,
            'wall' => $bl['wall_total'],
            'flights' => count($bl['flights']),
        ],
        'routes' => $routeRows,
        'route_certified' => $routeCertifiedAny,
        'route_wall_share' => round($routeWall / max(0.001, $TASK_BUDGET), 3),
        'certified_any' => $blCertified || $routeCertifiedAny,
    ];
    if ($isNull) {
        $nullResults[] = $row;
    } else {
        $taskResults[] = $row;
    }
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
    'run_id' => $RUN_ID,
    'arm' => $arm,
    'seed' => $seed,
    'task_budget' => $TASK_BUDGET,
    'pop_n' => $POP_N,
    'tasks' => $taskResults,
    'nulls' => $nullResults,
    'summary' => [
        'tasks' => $taskN,
        'baseline_certified' => $blCertN,
        'route_certified' => $routeCertN,
        'any_certified' => count(array_filter($taskResults, fn ($r) => $r['certified_any'])),
        'fpr_nulls' => $fpr . '/' . count($nullResults),
    ],
    'population_export' => $isRouteArm ? $ledger->export() : null,
];
$outPath = $outDir . '/results_' . $arm . '_' . $seed . '.json';
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "DONE {$RUN_ID}: blCert={$blCertN}/{$taskN} routeCert={$routeCertN}/{$taskN} FPR={$fpr}/" . count($nullResults) . " → {$outPath}\n";
