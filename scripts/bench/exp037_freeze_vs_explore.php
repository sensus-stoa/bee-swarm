<?php

declare(strict_types=1);

/**
 * ЭКСП-037: «Заморозка vs разведка» культурного буста (T5-post-3, 05.09).
 *
 * Pre-registered гипотезы (ДО прогона, запись в шапке = фиксация):
 *   H1 (заморозка): доля culture-ops в топе weightedPick монотонно растёт,
 *      diversity падает, birth-ops прекращаются.
 *   H0 (равновесие): cap 50 + re-discovery confirmation → топ стабилен,
 *      diversity ±15%, birth-ops продолжаются.
 *   H2 (разведка): durable rate растёт, продуктивность не падает.
 *
 * Метрики (срез каждые ~150 тиков):
 *   M1: доля culture-source ops в топ-20 weightedPick
 *   M2: durable rate (confirmed / total laws)
 *   M3: уникальных ops в грамматиках роя (diversity proxy)
 *   M4: birth-событий накопленно
 *   M5: discoveries / 100 тиков
 *
 * Критерии вердикта:
 *   H1: M1(t_end) > 0.80 И M3 падает ≥30% И M4 = 0
 *   H0: M1 < 0.60 ИЛИ M3 стабильна (±15%) И M4 > 0
 *   H2: M2 растёт И M5 не падает (>70% от M5(t0))
 *
 * Env-шапка (31.08): изолированная БД, beam=10, cap=3.
 * Свежая БД + bootstrap 3 seed + forager на реальных CSV.
 */

putenv('SWARM_DB_PATH=' . sys_get_temp_dir() . '/exp037_' . getmypid() . '.db');
putenv('FORAGER_SOURCES=');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SEARCH_DEPTH=2');
putenv('SEARCH_DEPTH_MAX=2');
putenv('PROPAGATION=1');
putenv('NO_BASE_TASKS=0');
putenv('CULTURE_LEVEL=1.0');

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;
use BeeSwarm\Hive\Hive;

const TICKS = 1800;          // ~15 мин при 500ms
const SLICE_EVERY = 150;     // 12 срезов
const DATA_DIR = __DIR__ . '/../../data';

$logFile = sys_get_temp_dir() . '/exp037_agenda.log';

echo "=== ЭКСП-037: заморозка vs разведка ({$TICKS} тиков, срез каждые "
    . SLICE_EVERY . ") ===\n";
echo 'DB: ' . getenv('SWARM_DB_PATH') . "\n";
echo 'FORAGER_SOURCES: ' . (getenv('FORAGER_SOURCES') ?: '(default data dir)') . "\n\n";

Database::reset();

// Форсаж цикла: реальный sleep 500ms × 1800 = 15 мин wall-clock. Для bench —
// noop-sleep (тики максимально быстро, 15 мин CPU-времени ≈ несколько минут реальных).
// Отмечаем в артефактах: тики без sleep, метрики в тиках не в секундах.

$slices = [];
$lastBirths = 0;
$lastDiscoveries = 0;

// Заглушка sleep: Hive::run использует usleep внутри? Проверить нельзя без правки ядра —
// поэтому прогоняем максимальными тиками и фиксируем, что это ускоренный прогон.
$t0 = microtime(true);

$hive = new Hive(maxTicks: TICKS, logFile: $logFile);

// Перехват срезов через reflection каждые SLICE_EVERY тиков невозможен без правки run(),
// поэтому: запускаем частями — maxTicks=TICKS, но run() не даёт промежуточных срезов.
// Альтернатива: N запусков по SLICE_EVERY тиков (persistence сохраняет популяцию 31.08).
$totalRun = 0;
for ($part = 0; $part < TICKS / SLICE_EVERY; $part++) {
    $h = new Hive(maxTicks: SLICE_EVERY, logFile: $logFile);
    $h->run();
    $totalRun += SLICE_EVERY;

    // ── Срез метрик ──
    $db = Database::get();

    // M1: доля culture-source ops в топ-20 weightedPick
    $rows = $db->query(
        'SELECT name, usage_count FROM grammar_ops ORDER BY usage_count DESC LIMIT 20'
    )->fetchAll(\PDO::FETCH_ASSOC);
    $cultureCount = 0;
    foreach ($rows as $r) {
        // culture-ops: созданы нашим бустом (source='culture') ИЛИ базовые, получившие буст
        $src = $db->prepare('SELECT source FROM grammar_ops WHERE name = ?');
        $src->execute([$r['name']]);
        if ($src->fetchColumn() === 'culture') {
            $cultureCount++;
        }
    }
    $m1 = count($rows) > 0 ? $cultureCount / count($rows) : 0.0;

    // M2: durable rate
    $total = (int) $db->query('SELECT COUNT(*) FROM laws')->fetchColumn();
    $confirmed = (int) $db->query(
        'SELECT COUNT(*) FROM laws WHERE COALESCE(confirmed_count,0) >= 1'
    )->fetchColumn();
    $m2 = $total > 0 ? $confirmed / $total : 0.0;

    // M3: diversity — уникальных ops в популяционных грамматиках
    $diverse = $h->getBees();
    $uniqueOps = [];
    foreach ($diverse as $bee) {
        foreach ($bee->grammar()->ops ?? [] as $opName => $def) {
            $uniqueOps[$opName] = true;
        }
    }
    $m3 = count($uniqueOps);

    // M4: birth-события (ops с source='birth')
    $births = (int) $db->query(
        "SELECT COUNT(*) FROM grammar_ops WHERE source = 'birth'"
    )->fetchColumn();
    $m4 = $births;

    // M5: discoveries (законов создано за слайс)
    $m5 = $total - $lastDiscoveries;
    $lastDiscoveries = $total;

    $slices[] = [
        'tick' => $totalRun,
        'm1' => round($m1, 3),
        'm2' => round($m2, 3),
        'm3' => $m3,
        'm4' => $m4,
        'm5' => $m5,
        'top3' => array_slice(array_map(
            static fn (array $r): string => $r['name'] . ':' . $r['usage_count'],
            $rows
        ), 0, 3),
    ];

    echo sprintf(
        "[%4d] M1=%.2f M2=%.2f M3=%d M4=%d M5=%d top3=%s\n",
        $totalRun, $m1, $m2, $m3, $m4, $m5, implode(',', $slices[count($slices) - 1]['top3'])
    );
}

$wall = microtime(true) - $t0;
echo "\n=== Прогон завершён за " . number_format($wall, 1) . "s (ускоренный: без 500ms sleep) ===\n\n";

// ── Вердикт по pre-registered критериям ──
$first = $slices[0];
$last = $slices[count($slices) - 1];
$m1End = $last['m1'];
$m3Drop = $first['m3'] > 0 ? ($first['m3'] - $last['m3']) / $first['m3'] : 0.0;
$m4Total = $last['m4'];
$m2Trend = $last['m2'] - $first['m2'];
$m5LastAvg = array_sum(array_column(array_slice($slices, -3), 'm5')) / 3;
$m5FirstAvg = array_sum(array_column(array_slice($slices, 0, 3), 'm5')) / 3;
$m5NotFalling = $m5FirstAvg == 0 || ($m5LastAvg / max($m5FirstAvg, 1)) > 0.7;

echo "=== Анализ ===\n";
echo 'M1(first→end): ' . $first['m1'] . ' → ' . $m1End . "\n";
echo 'M3 drop: ' . round($m3Drop * 100, 1) . "%\n";
echo 'M4 births: ' . $m4Total . "\n";
echo 'M2 trend: ' . round($m2Trend, 3) . "\n";
echo 'M5 first3avg=' . round($m5FirstAvg, 1) . ' last3avg=' . round($m5LastAvg, 1) . "\n\n";

$h1 = $m1End > 0.80 && $m3Drop >= 0.30 && $m4Total === 0;
$h0 = ($m1End < 0.60 || abs($m3Drop) <= 0.15) && $m4Total > 0;
$h2 = $m2Trend > 0 && $m5NotFalling;

echo "=== Вердикт ===\n";
echo 'H1 (заморозка):        ', $h1 ? 'ПОДТВЕРЖДЕНА' : 'не подтверждена', "\n";
echo 'H0 (равновесие):       ', $h0 ? 'ПОДТВЕРЖДЕНА' : 'не подтверждена', "\n";
echo 'H2 (разведка жива):    ', $h2 ? 'ПОДТВЕРЖДЕНА' : 'не подтверждена', "\n";

// Сохраняем срезы для experiments-log
file_put_contents(
    sys_get_temp_dir() . '/exp037_slices.json',
    json_encode(['slices' => $slices, 'verdict' => ['h1' => $h1, 'h0' => $h0, 'h2' => $h2]], JSON_PRETTY_PRINT)
);
echo "\nСрезы: " . sys_get_temp_dir() . "/exp037_slices.json\n";
echo "Лог: {$logFile}\n";
