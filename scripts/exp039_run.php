<?php

declare(strict_types=1);

/**
 * EXP-039: драйвер прогона одной конфигурации.
 *
 * PREREG.md §8 + PREREG_ADDENDUM_BUDGET.md (A2/A3/A6/A8).
 * Паттерн: scripts/v14_wu5_run.php (reflection-драйвер, живой doTick).
 *
 * Использование:
 *   php scripts/exp039_run.php <mode> <seed> <budget> <ticks> <out.json>
 *   mode: A|B|C|D
 *   budget: E_total (0 = без Governor, для A(777) прогон-эталона)
 *
 * Режимы: A = uniform (текущее поведение), B = 8 фаз, C = 8 фаз shuffled,
 * D = 4 квартала. Задачи: heat-синтетика (seeded) + CCPP (данные).
 * C4: порядок задач определяется ТОЛЬКО seed (одинаков для всех режимов).
 */

if ($argc < 6) {
    fwrite(STDERR, "usage: php exp039_run.php <mode> <seed> <budget> <ticks> <out.json>\n");
    exit(1);
}
$mode = strtoupper($argv[1]);
$seed = (int) $argv[2];
$budget = (float) $argv[3];
$maxTicks = (int) $argv[4];
$outPath = $argv[5];

if (! in_array($mode, ['A', 'B', 'C', 'D'], true)) {
    fwrite(STDERR, "mode must be A|B|C|D\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

// ── Env-шапка (после autoload, урок 13.09) ──
$workDb = sys_get_temp_dir() . "/exp039_{$mode}_s{$seed}.db";
@unlink($workDb);
putenv('SWARM_DB_PATH=' . $workDb);
putenv('NO_BASE_TASKS=1');
putenv('FORAGER_SOURCES=:');
putenv('ESCROW_GRACE_TASKS=0');
putenv('DISSIPATION_ACCEL_TICKS=0');
putenv('SPWN_POOL=0');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SEARCH_DEPTH=4');
putenv('SEARCH_DEPTH_MAX=4');
putenv('VVERIFY_CV_TRAIN_MAX=0.15');
// EXP-039: задачи = известные физические законы (heat: T_out=T_env+P/(mc);
// CCPP: Хамон-подобная зависимость). Ratio-CV pre-flight режет их по
// r2_floor (cv_y heat=0.30 → гейт-порог 0.5×0.30=0.15 отсекает слабые
// формы, но задача в принципе постижима — гейт ищет НЕвыразимость, а не
// сложность). Оставляем pre-flight включённым: он одинаков во всех
// конфигурациях и не искажает сравнение. Если обе задачи отсекаются
// (passed=0) — EXP-039_STRICT env у_driver'а выключает pre-flight.
putenv('PREFLIGHT_GATE_FACTOR=' . (getenv('EXP039_PREFLIGHT') ?: '0.5'));
// домены драйвера: heat (синтетика) + enb (UCI energy_efficiency, certify-able)
// EXP-039 фазовая механика
putenv('EXP039_MODE=' . $mode);
putenv('EXP039_SEED=' . $seed);
putenv('EXP039_BUDGET=' . $budget);
putenv('EXP039_TICKS=' . $maxTicks);
putenv('EXP039_COST=0.1');
putenv('EXP039_DOMAINS=heat,enb');

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

Database::setPath($workDb);
Database::get();

$t0 = microtime(true);

// ── 1. Пул задач: heat-синтетика + CCPP. RNG сеется ТОЛЬКО seed (C4) ──
mt_srand($seed);

$tasks = [];

// FIX 5 (09.10): consumeTask удаляет задачу из пула ПОСЛЕ первого выбора —
// без вариантов пул из 2 задач исчерпывается за 2 тика, остальные тики
// пусты (smoke B: 2 ROUTE за 2000 тиков). Делаем EXP039_POOL_TASKS вариантов
// каждой задачи с разными срезами строк (разные fingerprints).
$poolTasks = max(2, (int) (getenv('EXP039_POOL_TASKS') ?: '12'));

// heat: закон T_out = T_env + P_therm / (m·c) — синтетика, 4 фичи.
// Диапазон T_env 5-45 подобран так, чтобы cv_y ≈ 0.44 > 2×гейт (0.15):
// metric-preflight (§1.10) отсекает задачи с gate ≥ 0.5·cv_y — при
// T_env 10-30 закон PASSал на волоске (cv_y=0.2989, 0.15 ≥ 0.1494).
$heatRows = [];
for ($i = 0; $i < 400; $i++) {
    $x0 = mt_rand(20, 120) / 1.0;      // P_therm
    $x1 = mt_rand(5, 40) / 4.0;        // m
    $x2 = 4186.0;                      // c (константа среды)
    $x3 = mt_rand(5, 45);              // T_env
    $y = $x3 + $x0 / ($x1 * $x2);
    $heatRows[] = [$x0, $x1, $x2, $x3, round($y, 6)];
}
// (инжекция пула — в тик-цикле, makePool FIX 6)

// energy_efficiency (UCI ENB2012): heating/cooling load здания, 8 фич.
// CV(y) = 0.387 → preflight PASS (gate 0.15 < 0.5·0.387). CCPP отклонён:
// cv_y(PE)=0.038 → r2_floor < 0, задача объективно не certify-able по гейту.
$enbPath = __DIR__ . '/../data/energy_efficiency.csv';
$enbRows = [];
if (is_readable($enbPath)) {
    $lines = file($enbPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $first = str_getcsv($lines[0]);
    $isHeader = ! is_numeric(trim($first[0]));
    $labels = $isHeader ? $first : array_map(fn ($i) => "x{$i}", range(0, count($first) - 1));
    if ($isHeader) {
        array_shift($lines);
    }
    // FIX 7 (09.10): явная проекция X1..X5 (cols 0-4) + Y1 (col 8).
    // slice(0,7) давал y=X7 (среда) → METRIC_DOMAIN. 5 фич → tMin=25 < 30 cap.
    foreach ($lines as $ln) {
        $v = array_map('floatval', str_getcsv($ln));
        if (count($v) === count($labels)) {
            $enbRows[] = [$v[0], $v[1], $v[2], $v[3], $v[4], $v[8]];
        }
    }
    $labels = ['X1', 'X2', 'X3', 'X4', 'X5', 'Y1'];
}
if (count($enbRows) >= 100) {

} else {
    fwrite(STDERR, "WARN: energy_efficiency unavailable ({$enbPath}), heat-only\n");
}

// ── 2. Hive: bootstrap → инжекция пула → ручной тик-цикл (паттерн WU-5) ──
$logFile = sys_get_temp_dir() . "/exp039_{$mode}_s{$seed}.log";
file_put_contents($logFile, '[' . date('H:i:s') . "] EXP039: mode={$mode} seed={$seed} budget={$budget} ticks={$maxTicks}\n", FILE_APPEND);

$hive = new Hive(maxTicks: 0, logFile: $logFile);
$hive->run(); // bootstrap

$poolProp = new ReflectionProperty(Hive::class, 'foragedTasksGlobal');
$poolProp->setAccessible(true);
$poolProp->setValue($hive, $tasks);

$beesP = new ReflectionProperty(Hive::class, 'bees');
$beesP->setAccessible(true);
$bees = $beesP->getValue($hive);
$rbP = new ReflectionProperty(Hive::class, 'routedBee');
$rbP->setAccessible(true);
$rbP->setValue($hive, $bees[0] ?? null);
$trP = new ReflectionProperty(Hive::class, 'taskRouter');
$trP->setAccessible(true);
$trP->setValue($hive, new \BeeSwarm\Hive\TaskRouter($bees, 10));

$doTick = new ReflectionMethod(Hive::class, 'doTick');
$doTick->setAccessible(true);
$tickProp = new ReflectionProperty(Hive::class, 'tick');
$tickProp->setAccessible(true);

// VERIFY-каденс для A (аддендум A6): каждые 10 тиков — внешний ритм драйвера.
// В B/C/D verify концентрируется verify-фазами (внутри doTick-гейта).
$verifyEvery = 10;

$pathOpenings = [];
// FIX 6 (09.10): без forager пул исчерпывается consumeTask'ом за ~30 тиков.
// Refill каждые 50 тиков: свежие срезы строк, имена с номером раунда
// (novelty/fingerprint честные). Детерминировано seed'ом — C4 соблюдён.
$refillEvery = max(10, (int) (getenv('EXP039_REFILL_EVERY') ?: '50'));
$round = 0;
$makePool = function (int $round) use ($seed, $heatRows, $enbRows, $enbPath, $labels, $poolTasks): array {
    $out = [];
    for ($v = 0; $v < $poolTasks; $v++) {
        $hr = [];
        for ($i = 0; $i < 100; $i++) {
            $x0 = mt_rand(20, 120) / 1.0;
            $x1 = mt_rand(5, 40) / 4.0;
            $x2 = 4186.0;
            $x3 = mt_rand(5, 45);
            $hr[] = [$x0, $x1, $x2, $x3, round($x3 + $x0 / ($x1 * $x2), 6)];
        }
        $out[] = [
            'name' => "foraged_heat_s{$seed}_r{$round}_v{$v}",
            'data' => $hr,
            'domain' => 'heat',
            'content' => "EXP039 heat synthetic, seed {$seed} round {$round} variant {$v}",
            'source_path' => 'exp039_synthetic',
            'col_labels' => ['P_therm', 'm', 'c', 'T_env', 'T_out'],
        ];
        if (! empty($enbRows)) {
            $er = [];
            for ($i = 0; $i < 100; $i++) {
                $er[] = $enbRows[mt_rand(0, count($enbRows) - 1)];
            }
            $out[] = [
                'name' => "foraged_enb_s{$seed}_r{$round}_v{$v}",
                'data' => $er,
                'domain' => 'enb',
                'content' => "EXP039 energy_efficiency, seed {$seed} round {$round} variant {$v}",
                'source_path' => $enbPath,
                'col_labels' => $labels,
            ];
        }
    }

    return $out;
};

for ($t = 1; $t <= $maxTicks; $t++) {
    if ($t === 1 || $t % $refillEvery === 0) {
        $poolProp->setValue($hive, $makePool($round));
        $round++;
    }
    $tickProp->setValue($hive, $t);
    $doTick->invoke($hive);
    if ($mode === 'A' && $t % $verifyEvery === 0) {
        $hive->runPendingVerificationTasks('heat', 6);
        $hive->runPendingVerificationTasks('enb', 6);
    }
    if ($t % 5000 === 0) {
        file_put_contents($logFile, '[' . date('H:i:s') . "] EXP039: tick={$t}\n", FILE_APPEND);
    }
}
$hive->savePopulation();

// ── 3. Результат: метрики + леджер (анализ — в exp039_analyze.php) ──
$db = Database::get();

$certified = (int) $db->query("SELECT COUNT(*) FROM laws WHERE escrow_status = 'PAID'")->fetchColumn();
$confirmed = (int) $db->query('SELECT COUNT(*) FROM laws WHERE confirmed_count >= 1')->fetchColumn();
$totalLaws = (int) $db->query('SELECT COUNT(*) FROM laws')->fetchColumn();

// P1-бис: списки certified-законов (для гипергеометрии между прогонами)
$certList = [];
foreach ($db->query("SELECT formula, domain FROM laws WHERE escrow_status = 'PAID'") as $r) {
    $certList[] = $r['domain'] . '::' . $r['formula'];
}

// P3: halflife считает анализатор из ledger.alive; scheduler-строки берём
// напрямую из объекта (reflection), hive_state не используется.
$schedRows = [];

// энергетика из ledger (кумулятивные диссипации — последняя строка)
$ledgerRows = [];
$schedProp = new ReflectionProperty(Hive::class, 'seasonScheduler');
$schedProp->setAccessible(true);
$sched = $schedProp->getValue($hive);
if ($sched !== null) {
    $ledgerRows = $sched->ledgerRows();
}

$sumEnergyFinal = 0.0;
$aliveFinal = 0;
foreach ($beesP->getValue($hive) ?: [] as $bee) {
    if ($bee->isAlive()) {
        $aliveFinal++;
        $sumEnergyFinal += $bee->energy();
    }
}

// смерти по фазам (S2): точный подсчёт — в exp039_analyze.php по логу
$result = [
    'mode' => $mode,
    'seed' => $seed,
    'budget' => $budget,
    'ticks' => $maxTicks,
    'wall_seconds' => round(microtime(true) - $t0, 1),
    'metrics' => [
        'P1_certified_invariants' => $certified,
        'P1b_confirmed' => $confirmed,
        'P1c_total_laws' => $totalLaws,
        'P2_energy_per_cert' => $budget > 0 && $certified > 0 ? round($budget / $certified, 2) : null,
        'P3_halflife' => null, // анализатор: из ledgerRows alive-колонки
    ],
    'cert_list' => $certList,
    'ledger' => $ledgerRows,
    'final' => [
        'alive' => $aliveFinal,
        'sum_energy' => round($sumEnergyFinal, 4),
    ],
];

file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "EXP039 DONE mode={$mode} seed={$seed} cert={$certified} confirmed={$confirmed} laws={$totalLaws} alive={$aliveFinal}\n";
echo "OUT: {$outPath}\n";
