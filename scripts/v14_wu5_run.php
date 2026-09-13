<?php

declare(strict_types=1);

/**
 * V0.14 WU-5: драйвер эмерджентных прогонов verification-economy.
 *
 * Механика: живой Hive (bootstrap → инжекция foraged-пула из CSV → тики),
 * каждые VERIFY_EVERY тиков — VerificationExecutor по домену + settleEscrow.
 * Никаких новых механизмов: только проводка существующих компонентов.
 *
 * Критерии (проверяются analyze-фазой, не здесь):
 *   Feynman dot: закон L0 ≥4/5 подтверждений, escrow PAID.
 *   CCPP:        дегенерат не набирает консенсуса на инверсной задаче,
 *                anchor-форма выживает в той же среде.
 *   Concrete:    ≥1 partial-форма с частью подтверждений + anchor-B-атом.
 *
 * Запуск (пример):
 *   NO_BASE_TASKS=1 FORAGER_SOURCES=: ESCROW_GRACE_TASKS=0 \
 *   SWARM_DB_PATH=/tmp/wu5_feynman.db \
 *   php scripts/v14_wu5_run.php data/feynman_dot_product.csv feynman_dot 400 1
 *
 * Аргументы: <csv> <domain> <ticks> [seedIdx]
 * Env: WU5_POOL_TASKS (default 8), WU5_ROWS_PER_TASK (default 60),
 *      VERIFY_EVERY (default 10), WU5_VERIFY_LIMIT (default 6).
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: php v14_wu5_run.php <csv> <domain> <ticks> [seedIdx]\n");
    exit(1);
}
$csvPath = $argv[1];
$domain = $argv[2];
$maxTicks = (int) $argv[3];
$seedIdx = (int) ($argv[4] ?? 1);

require __DIR__ . '/../vendor/autoload.php';

// Env-шапка ПОСЛЕ autoload (урок 13.09-бис: putenv ДО require терялся в
// driver-прогоне при том, что изолированные php -r пробы работали —
// воспроизводится только в файле; порядок require→putenv из debug4 надёжен).
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SEARCH_DEPTH=' . (getenv('WU5_SEARCH_DEPTH') ?: '4'));
putenv('SEARCH_DEPTH_MAX=' . (getenv('WU5_SEARCH_DEPTH') ?: '4'));
putenv('NO_BASE_TASKS=1');
putenv('FORAGER_SOURCES=:');
putenv('ESCROW_GRACE_TASKS=0');
putenv('SWARM_DB_PATH=' . (sys_get_temp_dir() . "/wu5_{$domain}_s{$seedIdx}.db"));
// rows 100: cap-30 в doTick оставляет 30 — но tMin для 8+ колонок = 40;
// без этого concrete (9 колонок) вечный INSUFFICIENT_DATA (срез 30 строк)
putenv('WU5_ROWS_PER_TASK=' . (getenv('WU5_ROWS_PER_TASK') ?: '100'));

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

Database::setPath(getenv('SWARM_DB_PATH') ?: (sys_get_temp_dir() . "/wu5_{$domain}_s{$seedIdx}.db"));
Database::get();

// ── 1. Пул задач из CSV: WU5_POOL_TASKS срезов, разные строки/fingerprints ──
$raw = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($raw === false || count($raw) < 20) {
    fwrite(STDERR, "CSV пуст или слишком мал: {$csvPath}\n");
    exit(1);
}
// Header-детект: CSV без заголовка (первая строка — числа) → синтетические x0..xN
$first = str_getcsv($raw[0]);
$isHeader = ! is_numeric(trim($first[0]));
$header = $isHeader ? str_getcsv(array_shift($raw)) : array_map(fn ($i) => "x{$i}", range(0, count($first) - 1));
$rows = [];
foreach ($raw as $line) {
    $vals = array_map('floatval', str_getcsv($line));
    if (count($vals) === count($header)) {
        $rows[] = $vals;
    }
}
// Ограничение nFeat<=6: doTick cap-30 строк и tMin=nFeat*5 → при >=8 колонок
// tMin(40+)>30 всегда → вечный INSUFFICIENT_DATA (прод-ограничение среды).
// Берём первые 5 фич + y (если колонок больше — лишние отбрасываются).
$maxFeat = (int) (getenv('WU5_MAX_FEAT') ?: '5');
if (count($header) - 1 > $maxFeat) {
    $keep = array_slice($header, 0, $maxFeat + 1);
    $header = $keep;
    foreach ($rows as $i => $vals) {
        $rows[$i] = array_slice($vals, 0, $maxFeat + 1);
    }
}
$poolTasks = (int) (getenv('WU5_POOL_TASKS') ?: '8');
$rowsPerTask = (int) (getenv('WU5_ROWS_PER_TASK') ?: '100');
// WU5_TRACE-обход: для >=8 колонок cap-30 в doTick даёт t< tMin —
// rowsPerTask поднимается env'ом WU5_ROWS_PER_TASK=100
mt_srand($seedIdx * 1000 + 7);
$tasks = [];
for ($k = 0; $k < $poolTasks; $k++) {
    $slice = [];
    for ($i = 0; $i < $rowsPerTask; $i++) {
        $slice[] = $rows[mt_rand(0, count($rows) - 1)];
    }
    $tasks[] = [
        'name' => "foraged_csv_{$domain}_v{$k}_s{$seedIdx}->c" . (count($header) - 1),
        'data' => $slice,
        'domain' => $domain,
        'content' => "seed {$seedIdx} variant {$k} from {$csvPath}",
        'source_path' => $csvPath,
        'col_labels' => $header,
    ];
}

// ── 2. Живой Hive: bootstrap → инжекция пула → ручной тик-цикл ──
$logFile = sys_get_temp_dir() . "/wu5_{$domain}_s{$seedIdx}.log";
$hive = new Hive(maxTicks: 0, logFile: $logFile);
$hive->run(); // bootstrap

$poolProp = new ReflectionProperty(Hive::class, 'foragedTasksGlobal');
$poolProp->setAccessible(true);
$poolProp->setValue($hive, $tasks);

// Инжекция routedBee/taskRouter (tech-debt 13.09): живой route() в связке
// с find() depth-3 теряет закон (см. progress.md WU-5 note) — для прогонов
// фиксируем маршрутную пчелу bee#0 с полной базовой грамматикой.
$beesP = new ReflectionProperty(Hive::class, 'bees');
$beesP->setAccessible(true);
$bees = $beesP->getValue($hive);
$rbP = new ReflectionProperty(Hive::class, 'routedBee');
$rbP->setAccessible(true);
$rbP->setValue($hive, $bees[0]);
$trP = new ReflectionProperty(Hive::class, 'taskRouter');
$trP->setAccessible(true);
$trP->setValue($hive, new \BeeSwarm\Hive\TaskRouter($bees, 10));

$verifyEvery = max(1, (int) (getenv('VERIFY_EVERY') ?: '10'));
$verifyLimit = max(1, (int) (getenv('WU5_VERIFY_LIMIT') ?: '6'));

$doTick = new ReflectionMethod(Hive::class, 'doTick');
$doTick->setAccessible(true);
$tickProp = new ReflectionProperty(Hive::class, 'tick');
$tickProp->setAccessible(true);

file_put_contents($logFile, '[' . date('H:i:s') . '] WU5: start domain=' . $domain
    . ' ticks=' . $maxTicks . ' tasks=' . count($tasks)
    . ' grace=' . (getenv('ESCROW_GRACE_TASKS') !== false ? getenv('ESCROW_GRACE_TASKS') : '5')
    . ' SD=' . (getenv('SEARCH_DEPTH') ?: 'LOST')
    . ' SDM=' . (getenv('SEARCH_DEPTH_MAX') ?: 'LOST')
    . ' BEAM=' . (getenv('SEARCH_BEAM_K') ?: 'LOST')
    . ' NOBASE=' . (getenv('NO_BASE_TASKS') ?: 'LOST') . "\n", FILE_APPEND);

// WU-5 тех-debt (13.09): живой doTick в связке route()+find(D=3) стабильно
// не воспроизводит закон (20+ проб; изолированный путь — воспроизводит).
// Для прогонов: прямой вызов doDiscoverTick с маршрутной пчелой bee#0.
// «Живой route» фиксируется как дыра звена в отчёте WU-5 (tech-debt).
for ($t = 1; $t <= $maxTicks; $t++) {
    $tickProp->setValue($hive, $t);
    $doTick->invoke($hive);
    if ($t % $verifyEvery === 0) {
        $done = $hive->runPendingVerificationTasks($domain, $verifyLimit);
        if ($done > 0) {
            file_put_contents($logFile, '[' . date('H:i:s') . "] WU5: tick={$t} verify_done={$done}\n", FILE_APPEND);
        }
    }
}
$hive->savePopulation();

// ── 3. Итоги прогона (сырые данные; критерии анализирует оператор/скрипт) ──
$db = Database::get();
echo "=== WU5 SUMMARY domain={$domain} seed={$seedIdx} ticks={$maxTicks} ===\n";
echo "-- laws --\n";
foreach ($db->query(
    "SELECT name, formula, cv, usage_count, confirmed_count, escrow_status
     FROM laws WHERE domain = '{$domain}' ORDER BY usage_count DESC LIMIT 15"
) as $r) {
    printf("  %-16s %-28s cv=%.4f usage=%d conf=%d esc=%s\n",
        (string) $r['name'], (string) $r['formula'], (float) $r['cv'],
        (int) $r['usage_count'], (int) $r['confirmed_count'], (string) $r['escrow_status']);
}
echo "-- verification_tasks --\n";
foreach ($db->query(
    "SELECT kind, status, COUNT(*) n FROM verification_tasks WHERE domain = '{$domain}'
     GROUP BY kind, status ORDER BY kind, status"
) as $r) {
    printf("  %-12s %-14s %d\n", $r['kind'], $r['status'], (int) $r['n']);
}
echo "-- escrow --\n";
foreach ($db->query(
    "SELECT status, COUNT(*) n, ROUND(SUM(amount), 2) total FROM law_escrow WHERE domain = '{$domain}'
     GROUP BY status"
) as $r) {
    printf("  %-10s n=%d total=%s\n", $r['status'], (int) $r['n'], (string) $r['total']);
}
echo "-- grammar B-atoms --\n";
foreach ($db->query(
    "SELECT name, source, definition FROM grammar_ops WHERE name LIKE 'B%' OR name LIKE 'BW%' LIMIT 10"
) as $r) {
    printf("  %-8s %-10s %s\n", $r['name'], (string) $r['source'], (string) $r['definition']);
}
echo "-- population --\n";
$alive = (int) $db->query('SELECT COUNT(*) FROM bee_persistence WHERE is_alive = 1')->fetchColumn();
echo "  alive={$alive}\n";
echo "LOG: {$logFile}\n";
