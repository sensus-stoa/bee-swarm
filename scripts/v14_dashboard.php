<?php

declare(strict_types=1);

/**
 * V0.14 WU-6: дашборд наблюдаемости verification-economy.
 *
 * Query поверх существующих таблиц (никаких записей, только SELECT):
 *   laws            — escrow_status, confirmation_rate (confirmed_count/usage_count)
 *   law_escrow      — flows: holding/paid/burned суммы и счётчики
 *   verification_tasks — исходы по kind, возраст pending-бэклога (контракт WU-2)
 *   atom_penalties  — штрафы операторам (диссипация + штрафы-наследие)
 *   generation_snapshots — популяция/разнообразие (контекст, не экономика)
 *
 * Env-шапка (бенч-правило 31.08): SWARM_DB_PATH обязательна — без неё скрипт
 * молча открывает прод-БД data/swarm.db. Здесь: без явного пути — EXIT 1,
 * прод-БД открываем ТОЛЬКО через DASHBOARD_DB=<path>.
 *
 * Использование:
 *   DASHBOARD_DB=data/swarm.db php scripts/v14_dashboard.php           # прод
 *   SWARM_DB_PATH=:memory: DASHBOARD_DB=/tmp/t.db php scripts/v14_dashboard.php
 *   DASHBOARD_LIMIT=20 — строк на секцию (default 15)
 */

if (getenv('DASHBOARD_DB') === false || getenv('DASHBOARD_DB') === '' || getenv('DASHBOARD_DB') === '0') {
    fwrite(STDERR, "Отказ: задай DASHBOARD_DB=<path> (прод-БД не открывается молча).\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = 'dashboard';
putenv('SWARM_DB_PATH=' . getenv('DASHBOARD_DB'));

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Infra\Database;

Database::setPath(getenv('DASHBOARD_DB'));
Database::get(); // инициализация + DDL

$limit = max(1, (int) (getenv('DASHBOARD_LIMIT') ?: '15'));
$db = Database::get();

$hr = str_repeat('=', 72);
echo $hr . "\n";
echo "V0.14 VERIFICATION-ECONOMY DASHBOARD  " . date('Y-m-d H:i:s') . "\n";
echo "DB: " . getenv('DASHBOARD_DB') . "\n" . $hr . "\n\n";

// ── 1. Законы: escrow-статусы + confirmation rate ─────────────────────────
echo "── 1. LAWS: escrow-статусы / confirmation rate ──\n";
$rows = $db->query(
    "SELECT escrow_status, COUNT(*) AS n, AVG(usage_count) AS avg_usage,
            AVG(confirmed_count) AS avg_conf
     FROM laws GROUP BY escrow_status ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);
echo sprintf("%-12s %6s %10s %10s\n", 'status', 'laws', 'avg_usage', 'avg_conf');
foreach ($rows as $r) {
    echo sprintf("%-12s %6d %10.2f %10.2f\n",
        $r['escrow_status'] === '' ? '(none)' : $r['escrow_status'],
        (int) $r['n'], (float) $r['avg_usage'], (float) $r['avg_conf']);
}
$top = $db->query(
    "SELECT name, formula, domain, usage_count, confirmed_count, escrow_status,
            ROUND(1.0 * confirmed_count / MAX(usage_count, 1), 2) AS conf_rate
     FROM laws ORDER BY usage_count DESC LIMIT " . $limit
)->fetchAll(PDO::FETCH_ASSOC);
echo "\nТоп по usage (confirmation_rate = confirmed/usage):\n";
echo sprintf("%-22s %-26s %-12s %5s %5s %5s %8s\n",
    'name', 'formula', 'esc', 'usage', 'conf', 'rate', '');
foreach ($top as $r) {
    echo sprintf("%-22.22s %-26.26s %-12.12s %5d %5d %8.2f\n",
        (string) $r['name'], (string) $r['formula'],
        $r['escrow_status'] === '' ? '-' : (string) $r['escrow_status'],
        (int) $r['usage_count'], (int) $r['confirmed_count'], (float) $r['conf_rate']);
}

// ── 2. Escrow flows ────────────────────────────────────────────────────────
echo "\n── 2. ESCROW FLOWS ──\n";
$rows = $db->query(
    "SELECT status, COUNT(*) AS n, SUM(amount) AS total
     FROM law_escrow GROUP BY status"
)->fetchAll(PDO::FETCH_ASSOC);
$flows = [];
foreach ($rows as $r) {
    $flows[$r['status']] = ['n' => (int) $r['n'], 'total' => (float) $r['total']];
}
echo sprintf("%-10s %6s %12s\n", 'status', 'count', 'sum');
foreach (['holding', 'paid', 'burned'] as $st) {
    echo sprintf("%-10s %6d %12.2f\n", $st, $flows[$st]['n'] ?? 0, $flows[$st]['total'] ?? 0.0);
}

// ── 3. V-задачи: исходы по kind + возраст pending-бэклога ─────────────────
echo "\n── 3. VERIFICATION TASKS ──\n";
$rows = $db->query(
    "SELECT kind, status, COUNT(*) AS n FROM verification_tasks
     GROUP BY kind, status ORDER BY kind, status"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo sprintf("  %-18s %-14s %6d\n", $r['kind'], $r['status'], (int) $r['n']);
}
$stale = $db->query(
    "SELECT COUNT(*) AS n,
            COALESCE(MAX(CAST((julianday('now') - julianday(created_at)) * 24 AS INTEGER)), 0) AS max_h,
            COALESCE(AVG(CAST((julianday('now') - julianday(created_at)) * 24 AS INTEGER)), 0) AS avg_h
     FROM verification_tasks WHERE status = 'pending'"
)->fetch(PDO::FETCH_ASSOC);
echo sprintf("\nPending-бэклог: %d шт, возраст max=%.1fh avg=%.1fh (контракт WU-2; VVERIFY_ABANDON_HOURS=24)\n",
    (int) $stale['n'], (float) $stale['max_h'], (float) $stale['avg_h']);

// ── 4. Диссипация / штрафы ────────────────────────────────────────────────
echo "\n── 4. ATOM PENALTIES (диссипация) ──\n";
$rows = $db->query(
    'SELECT atom, penalty_count FROM atom_penalties ORDER BY penalty_count DESC LIMIT ' . $limit
)->fetchAll(PDO::FETCH_ASSOC);
if ($rows === []) {
    echo "  (пусто)\n";
}
foreach ($rows as $r) {
    echo sprintf("  %-8s %d\n", $r['atom'], (int) $r['penalty_count']);
}

// ── 5. UNSTABLE deaths (законы, сгоревшие в верификации) ──────────────────
echo "\n── 5. UNSTABLE / PAID законы ──\n";
$rows = $db->query(
    "SELECT name, formula, domain, escrow_status FROM laws
     WHERE escrow_status IN ('UNSTABLE', 'PAID')
     ORDER BY escrow_status, name LIMIT " . $limit
)->fetchAll(PDO::FETCH_ASSOC);
if ($rows === []) {
    echo "  (пусто)\n";
}
foreach ($rows as $r) {
    echo sprintf("  %-9s %-20.20s %-24.24s [%s]\n",
        $r['escrow_status'], (string) $r['name'], (string) $r['formula'], $r['domain']);
}

echo "\n" . $hr . "\nКонец. Никаких записей не выполнено (read-only).\n";
