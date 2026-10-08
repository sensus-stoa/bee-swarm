<?php

/**
 * VERIF-COLLABEL-PARITY WU-4: бэктест 270 refuted + 198 pending из sandbox.db.
 *
 * Работает на КОПИИ (sandbox_backtest.db). Шаги:
 *  1. Открыть копию через Database (migrate добавит law_formula_generic fail-loud).
 *  2. Для каждой задачи resample/inverted: перегенерить law_shape из law_formula
 *     через LawShape::toGeneric (labels из laws.col_labels по join
 *     (law_formula, domain)); ghost/без-labels — generic = канон (или honest
 *     'unrecoverable' для доменной формулы без labels).
 *  3. Сбросить status='pending', created_at=now (иначе abandonStaleTasks
 *     VVERIFY_ABANDON_HOURS=24h закроет всё как inconclusive до исполнения).
 *  4. Прогнать VerificationExecutor по доменам, отчёт по исходам.
 *
 * Запуск (на ноуте): php scripts/vclp_backtest.php <копия.db> <отчёт.log>
 * Env: VCLP_LIMIT — кап задач на домен (0 = без капа, default 0).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Hive\VerificationExecutor;
use BeeSwarm\Infra\Database;

$dbPath = $argv[1] ?? '';
$logPath = $argv[2] ?? 'php://stdout';
if ($dbPath === '') {
    fwrite(STDERR, "usage: php vclp_backtest.php <db-copy> [report.log]\n");
    exit(1);
}
$logFn = static function (string $line) use ($logPath): void {
    if ($logPath === 'php://stdout') {
        echo $line . "\n";
        return;
    }
    file_put_contents($logPath, '[' . date('H:i:s') . "] {$line}\n", FILE_APPEND);
};

Database::setPath($dbPath);
Database::get(); // migrate: добавит law_formula_generic (fail-loud, idempotent)
$logFn('BACKTEST_START db=' . basename($dbPath));

$limit = (int) (getenv('VCLP_LIMIT') ?: '0');

// --- Шаг 2-3: перегенерация law_shape + сброс статуса ---
$rows = Database::get()->query(
    "SELECT id, law_formula, domain FROM verification_tasks
     WHERE kind LIKE 'resample%' OR kind IN ('inverted', 'inverted_research')
     ORDER BY id ASC"
)->fetchAll(\PDO::FETCH_ASSOC);

$stats = [
    'regenerated' => 0,
    'generic_passthrough' => 0,
    'unrecoverable' => 0,
    'reset' => 0,
];
$upd = Database::get()->prepare(
    'UPDATE verification_tasks
     SET law_formula_generic = ?, law_shape = ?, status = ?, created_at = datetime(\'now\')
     WHERE id = ?'
);

// кэш col_labels по (law_formula, domain)
$labelCache = [];
$lawStmt = Database::get()->prepare(
    'SELECT col_labels FROM laws WHERE formula = ? AND domain = ? LIMIT 1'
);

foreach ($rows as $r) {
    $canon = ExpressionNormalizer::normalize((string) $r['law_formula']);
    $key = $canon . '|' . $r['domain'];
    if (! array_key_exists($key, $labelCache)) {
        $lawStmt->execute([$canon, $r['domain']]);
        $raw = $lawStmt->fetchColumn();
        $labels = $raw === false ? null : json_decode((string) $raw, true);
        $labelCache[$key] = is_array($labels) && $labels !== [] ? $labels : null;
    }
    $labels = $labelCache[$key];
    $hasDomainNames = (bool) preg_match('/(?<![xX\d\w])[A-Za-z_]{2,}/', str_replace(['Rmin', 'Rmax', 'Rnorm', 'Rrange', 'Rsum', 'Ravg', 'K'], '', $canon));

    if ($labels !== null) {
        $generic = LawShape::toGeneric($canon, $labels);
        $stats['regenerated']++;
    } elseif (! $hasDomainNames) {
        $generic = $canon; // атом уже generic (X без заголовков)
        $stats['generic_passthrough']++;
    } else {
        // доменная формула, labels не восстановимы — честный unrecoverable
        $logFn("UNRECOVERABLE id={$r['id']} law={$canon} domain={$r['domain']}");
        $stats['unrecoverable']++;
        continue;
    }
    $shape = LawShape::of($generic);
    $upd->execute([$generic, $shape, 'pending', (int) $r['id']]);
    $stats['reset']++;
}
$logFn(sprintf(
    'REGEN total=%d regenerated=%d generic_passthrough=%d unrecoverable=%d reset=%d',
    count($rows),
    $stats['regenerated'],
    $stats['generic_passthrough'],
    $stats['unrecoverable'],
    $stats['reset']
));

// --- Шаг 4: исполнение по доменам ---
$domains = Database::get()->query(
    "SELECT DISTINCT domain FROM verification_tasks WHERE status = 'pending'"
)->fetchAll(\PDO::FETCH_COLUMN);

$exec = new VerificationExecutor(static function (string $line) use ($logFn): void {
    $logFn('EXEC ' . $line);
});
$report = [];
$limitPerDomain = $limit > 0 ? $limit : PHP_INT_MAX;
foreach ($domains as $domain) {
    $executed = $exec->runPendingVerificationTasks((string) $domain, $limitPerDomain);
    $logFn("DOMAIN {$domain}: executed={$executed}");
}

// --- Отчёт ---
$agg = Database::get()->query(
    'SELECT status, COUNT(*) c FROM verification_tasks GROUP BY status ORDER BY c DESC'
)->fetchAll(\PDO::FETCH_ASSOC);
foreach ($agg as $a) {
    $logFn("OUTCOME {$a['status']}: {$a['c']}");
}
$byKind = Database::get()->query(
    'SELECT kind, status, COUNT(*) c FROM verification_tasks GROUP BY kind, status ORDER BY kind, c DESC'
)->fetchAll(\PDO::FETCH_ASSOC);
foreach ($byKind as $k) {
    $logFn("KIND {$k['kind']} {$k['status']}: {$k['c']}");
}
// подтверждённые формы — что именно подтвердилось
$conf = Database::get()->query(
    "SELECT law_formula, law_formula_generic, COUNT(*) c FROM verification_tasks
     WHERE status = 'confirmed' GROUP BY law_formula, law_formula_generic ORDER BY c DESC LIMIT 20"
)->fetchAll(\PDO::FETCH_ASSOC);
foreach ($conf as $c) {
    $logFn("CONFIRMED x{$c['c']}: law={$c['law_formula']} generic={$c['law_formula_generic']}");
}
$logFn('BACKTEST_DONE');
