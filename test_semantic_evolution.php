<?php
// ~/.bee_swarm/test_semantic_evolution.php
// ТЕСТ: граф знаний растёт сам, порождает семантические законы

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\AtomRegistry;
use BeeSwarm\Grammar;
use BeeSwarm\Infra\Database;

echo "══════════════════════════════════════\n";
echo "  SEMANTIC EVOLUTION\n";
echo "══════════════════════════════════════\n\n";

$db = Database::get();
$db->exec("DELETE FROM knowledge_graph WHERE inferred = 1");

// ═══ ФАЗА 1: арифметика → семантические факты ═══
$arithmeticAtoms = ['add','sub','mul','div','min','max','abs','sqrt','sq','neg','inv'];
$arithmeticLaws  = [
    ['atom'=>'add', 'task'=>'ADD', 'domain'=>'arithmetic'],
    ['atom'=>'mul', 'task'=>'MUL', 'domain'=>'arithmetic'],
    ['atom'=>'sqrt','task'=>'SQRT','domain'=>'arithmetic'],
    ['atom'=>'min', 'task'=>'MIN', 'domain'=>'arithmetic'],
    ['atom'=>'abs', 'task'=>'ABS', 'domain'=>'arithmetic'],
];

// Каждый открытый закон → семантический факт
foreach ($arithmeticLaws as $law) {
    $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
       ->execute([$law['atom'], 'solves', $law['task'], 1.0]);
    $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
       ->execute([$law['atom'], 'is_a', 'arithmetic_operation', 1.0]);
}
echo "Phase 1: " . count($arithmeticLaws) . " arithmetic facts → knowledge_graph\n";

// ═══ ФАЗА 2: COMPOSE → семантические факты ═══
$composeDiscoveries = [
    ['outer'=>'abs', 'inner'=>'sub', 'name'=>'abs(sub)'],
    ['outer'=>'sq',  'inner'=>'add', 'name'=>'sq(add)'],
    ['outer'=>'sqrt','inner'=>'abs', 'name'=>'sqrt(abs)'],
    ['outer'=>'abs', 'inner'=>'mul', 'name'=>'abs(mul)'],
];

foreach ($composeDiscoveries as $c) {
    $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
       ->execute([$c['name'], 'is_a', 'compose_operation', 1.0]);
    $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
       ->execute([$c['outer'], 'composes_with', $c['inner'], 1.0]);
}
echo "Phase 2: " . count($composeDiscoveries) . " compose facts → knowledge_graph\n";

// ═══ ФАЗА 3: СЕМАНТИЧЕСКИЕ ЗАДАЧИ ИЗ ГРАФА ═══
$facts = $db->query("SELECT COUNT(*) FROM knowledge_graph")->fetchColumn();
echo "Phase 3: $facts total facts in graph\n\n";

// Задача 1: транзитивность is_a (уже была)
echo "─── Semantic law: is_a transitivity ───\n";
$chains = $db->query("
    SELECT COUNT(*) FROM knowledge_graph k1
    JOIN knowledge_graph k2 ON k1.object = k2.subject
    WHERE k1.predicate = 'is_a' AND k2.predicate = 'is_a'
")->fetchColumn();

$direct = $db->query("
    SELECT COUNT(*) FROM knowledge_graph k1
    JOIN knowledge_graph k2 ON k1.object = k2.subject
    JOIN knowledge_graph k3 ON k1.subject = k3.subject AND k2.object = k3.object
    WHERE k1.predicate = 'is_a' AND k2.predicate = 'is_a' AND k3.predicate = 'is_a'
")->fetchColumn();

if ($chains > 0) {
    $rate = round($direct/$chains*100);
    echo "  Chains: $chains, Direct: $direct ($rate%)\n";
    echo $rate >= 90 ? "  ✅ is_a ТРАНЗИТИВНО (CV≈0)\n" : "  ❌ CV>0\n";
}

// Задача 2: composes_with → produces compose?
echo "\n─── Semantic law: composes_with → is_a compose ───\n";
$composing = $db->query("SELECT DISTINCT k1.subject as outer_op FROM knowledge_graph k1 WHERE k1.predicate = 'composes_with'")->fetchAll(PDO::FETCH_COLUMN);
$composeCount = 0;
$bothCount = 0;
foreach ($composing as $op) {
    $hasCompose = $db->prepare("SELECT COUNT(*) FROM knowledge_graph WHERE subject LIKE ? AND predicate = 'is_a' AND object = 'compose_operation'")->execute([$op.'(%)']); 
    // Actually check differently
    $patterns = $db->query("SELECT subject FROM knowledge_graph WHERE predicate = 'is_a' AND object = 'compose_operation'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($patterns as $pat) {
        if (str_starts_with($pat, $op . '(')) $bothCount++;
    }
    $composeCount++;
}
echo "  Operators that compose: $composeCount\n";
echo "  Operators with compose children: $bothCount\n";

// Задача 3: solves + is_a → кросс-домен
echo "\n─── Cross-domain: solves × is_a ───\n";
$ops = $db->query("SELECT subject FROM knowledge_graph WHERE predicate = 'solves'")->fetchAll(PDO::FETCH_COLUMN);
$classes = $db->query("SELECT subject FROM knowledge_graph WHERE predicate = 'is_a'")->fetchAll(PDO::FETCH_COLUMN);
$intersect = array_intersect($ops, $classes);
echo "  Atoms that both solve AND are classified: " . count($intersect) . "\n";
if ($intersect) echo "  → " . implode(', ', $intersect) . "\n";

// ═══ ФАЗА 4: САМОГЕНЕРАЦИЯ СЕМАНТИКИ ═══
echo "\n─── Self-generating semantic tasks ───\n";

// Для каждого предиката проверить: симметричен? транзитивен?
$predicates = $db->query("SELECT DISTINCT predicate FROM knowledge_graph")->fetchAll(PDO::FETCH_COLUMN);
foreach ($predicates as $pred) {
    // Симметрия
    $pairs = $db->prepare("SELECT subject, object FROM knowledge_graph WHERE predicate = ?")->execute([$pred]);
    $pairs = $db->prepare("SELECT subject, object FROM knowledge_graph WHERE predicate = ?");
    $pairs->execute([$pred]);
    $rows = $pairs->fetchAll(PDO::FETCH_ASSOC);
    
    $sym = 0; $asym = 0;
    $pairSet = [];
    foreach ($rows as $r) $pairSet[$r['subject'].'|'.$r['object']] = true;
    foreach ($pairSet as $key => $_) {
        [$a,$b] = explode('|', $key);
        if (isset($pairSet[$b.'|'.$a])) $sym++; else $asym++;
    }
    $total = $sym + $asym;
    if ($total >= 3) {
        $status = $sym === $total ? '✅ SYMMETRIC' : ($asym === $total ? '✅ ASYMMETRIC' : '❌ MIXED');
        echo "  $pred: $sym sym / $asym asym = $status\n";
    }
}

echo "\nDone. Semantic graph of " . $facts . " facts.\n";
