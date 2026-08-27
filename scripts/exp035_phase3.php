<?php
declare(strict_types=1);

/**
 * EXP-035 Фаза 3 unit: b_quad-задача (y=(x0+x1)×x2).
 * Частичная гипотеза (x0+x1) слабой пчелы → B-кандидат → активация
 * (reuse) → потомок наследует → Search::find с B-грамматикой находит
 * (B×x2) CV=0. Это модель heat-победы: chunk + depth-3.
 */
require '/home/ninjacat/.bee_swarm/vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

putenv('FORAGER_SOURCES=:');
putenv('SWARM_DB_PATH=:memory:');

echo "=== EXP-035 фаза 3: partial → B → потомок → CV=0 ===\n";

Database::get();
$hive = new Hive(maxTicks: 0, logFile: '/tmp/e035.log');
$hive->run();

// 1. Частичная гипотеза слабой пчелы
$hive->dormantPool()->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
$hive->materializeFromPool(1);
$hive->pruneLineages(50); // голод без прогресса
$hive->pruneLineages(50);

$born = $hive->partialBirth('(x0+x1)', 0.42, 'arithmetic', 1.0);
echo "1. partialBirth (x0+x1): ", $born ? 'BORN' : 'REJECTED', "\n";
if (!$born) { exit(1); }

// 2. Ищем имя B-кандидата
$rows = Database::get()->query(
    "SELECT name FROM grammar_ops WHERE source='birth' AND definition='(x0+x1)' AND birth_domain='arithmetic'"
)->fetchAll();
$bName = $rows[0]['name'] ?? null;
echo "2. B-имя: $bName\n";

// 3. Активация (reuse ≥ 1 — внешнее использование)
Grammar::registerReuse($bName, 'arithmetic');
$status = Database::get()->prepare("SELECT status FROM grammar_ops WHERE name=?");
$status->execute([$bName]);
echo "3. статус после reuse: ", $status->fetchColumn(), "\n";

// 4. Потомок наследует
$hive->dormantPool()->deposit(['op' => '×', 'operand' => 'x2'], 'PRODUCT', 0.8);
$hive->materializeFromPool(1);
$bees = $hive->getBees();
$child = $bees[count($bees) - 1];
$childGrammar = $child->grammar();
echo "4. потомок грамматика содержит $bName: ",
    in_array($bName, $childGrammar, true) ? 'YES' : 'NO', "\n";

// 5. Search::find с B-грамматикой на b_quad данных
// b_quad.csv: y=(x0+x1)×x2, 4 колонки
$csv = fopen('/home/ninjacat/.bee_swarm/tests/fixtures/forager/b_quad.csv', 'r');
fgetcsv($csv); // header
$X = []; $y = [];
while (($r = fgetcsv($csv)) !== false) {
    if (count($r) < 4) continue;
    $X[] = [(float)$r[0], (float)$r[1], (float)$r[2]];
    $y[] = (float)$r[3];
}
fclose($csv);
echo "5. данных строк: ", count($y), "\n";

$grammar = new Grammar();
$grammar->restrictTo(['+', '×', '−', '/', 'sq', $bName]);
$result = Search::find($X, $y, $grammar, 3, null, 0.0, 0.15, 30.0);
[$found, $cv, $formula] = $result;
echo "6. Search::find: found=", $found ? 'YES' : 'NO',
    " CV=", number_format((float)$cv, 4),
    " formula=$formula\n";

// ВЕРДИКТ
if ($found && (float)$cv <= 0.01 && str_contains($formula, $bName)) {
    echo "\n=== EXP-035 ФАЗА 3: PASS — chunk+depth-3 находит полную цепочку ===\n";
    exit(0);
}
echo "\n=== EXP-035 ФАЗА 3: FAIL — см. выше ===\n";
exit(1);
