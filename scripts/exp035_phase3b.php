<?php
declare(strict_types=1);

/**
 * EXP-035 фаза 3b v3 (27.08): bornBinary-путь (улья-семантика).
 *
 * Цель: y = (x0+x1)×x2×x3/x4 — сырая глубина 4 (baseline depth-3 FAIL).
 * Частичная (x0+x1) → partialBirth → grammar_ops → bornBinary (B-CAP 3)
 * → Search сам генерит (x0BPxx1)-L1, ((x0BPxx1)×x2)-L2 (L2L1-блок),
 * финал: ((x0BPxx1)×x2)×x3 / x4 через L2L1 + L3/фича (SEMANTIC GUARD v2).
 */
putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('BINARY_B_CAP=3');
ini_set('memory_limit', '2G');

require '/home/ninjacat/.bee_swarm/vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

Database::get();
$hive = new Hive(maxTicks: 0, logFile: '/tmp/e035b3.log');
$hive->run();

// ── 1. Данные: y=(x0+x1)×x2×x3/x4, 100 строк, seed 42 ──
mt_srand(42);
$X = []; $y = [];
for ($i = 0; $i < 100; $i++) {
    $x0 = mt_rand(1, 100) / 10;
    $x1 = mt_rand(1, 100) / 10;
    $x2 = mt_rand(1, 100) / 10;
    $x3 = mt_rand(1, 100) / 10;
    $x4 = mt_rand(1, 10) / 10 + 0.1;
    $X[] = [$x0, $x1, $x2, $x3, $x4];
    $y[] = ($x0 + $x1) * $x2 * $x3 / $x4;
}
echo "1. строк: ", count($y), "; цель y=(x0+x1)*x2*x3/x4 (сырая глубина 4)\n";

// ── 2. БАЗЛАЙН: depth-3 без B ──
$g0 = new Grammar();
$g0->restrictTo(['+', '×', '−', '/', 'sq']);
$r0 = Search::find($X, $y, $g0, 3, null, 0.0, 0.15, 90.0);
$baselineFound = $r0[0] && (float)$r0[1] <= 0.01;
echo "2. БАЗЛАЙН depth3 без B: found=", $r0[0] ? 'YES' : 'NO',
    " cv=", number_format((float)$r0[1], 4), " f=", $r0[2], "\n";

// ── 3. Голод линии + partialBirth ──
$hive->dormantPool()->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
$hive->materializeFromPool(1);
$hive->pruneLineages(50);
$hive->pruneLineages(50);
$born = $hive->partialBirth('(x0+x1)', 0.35, 'arithmetic', 1.0);
echo "3. partialBirth (x0+x1): ", $born ? 'BORN' : 'REJECTED', "\n";
if (!$born) { echo "FAIL: гейт не пропустил\n"; exit(1); }

$rows = Database::get()->query(
    "SELECT name FROM grammar_ops WHERE source='birth' AND definition='(x0+x1)' AND birth_domain='arithmetic'"
)->fetchAll();
$bName = $rows[0]['name'] ?? '';
echo "4. B-имя: ", $bName ?: '(нет!)', "\n";
if ($bName === '') { echo "FAIL: нет B-кандидата\n"; exit(1); }

// ── 4. Активация (reuse) ──
Grammar::registerReuse($bName, 'arithmetic');

// ── 5. Search: bornBinary-путь (как в улье) ──
$g1 = new Grammar();
$g1->restrictTo(['+', '×', '−', '/', 'sq', $bName]);
$r1 = Search::find($X, $y, $g1, 3, null, 0.0, 0.15, 240.0);
$withBFound = $r1[0] && (float)$r1[1] <= 0.01;
echo "5. С B (bornBinary) depth3: found=", $r1[0] ? 'YES' : 'NO',
    " cv=", number_format((float)$r1[1], 4), " f=", $r1[2], "\n";

// ── ВЕРДИКТ ──
if (!$baselineFound && $withBFound) {
    echo "\n=== EXP-035 ФАЗА 3b: PASS — B-атом делает depth-4 задачу решаемой на depth-3 ===\n";
    echo "Формула: ", $r1[2], "\n";
    exit(0);
}
if ($baselineFound) {
    echo "\n=== НЕ ДИСКРИМИНИРУЕТ: baseline нашёл ===\n";
} else {
    echo "\n=== FAIL: B-путь не находит (см. формулу/логи) ===\n";
}
exit(1);
