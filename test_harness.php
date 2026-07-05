<?php
// ~/.bee_swarm/test_harness.php
// Быстрая проверка гипотез без правки демона.
// Использование: php test_harness.php [--depth=3] [--domain=all|semantic|arithmetic]

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Grammar;
use BeeSwarm\Search;
use BeeSwarm\Infra\Database;
use BeeSwarm\Bee\SelfLearningBee;

date_default_timezone_set('Europe/Moscow');

$opts = getopt('', ['depth:', 'domain:', 'runs:', 'verbose']);
$depth = (int)($opts['depth'] ?? 2);
$domain = $opts['domain'] ?? 'all';
$runs = (int)($opts['runs'] ?? 1);
$verbose = isset($opts['verbose']);

// ═══ ЗАДАЧИ ═══

function getArithmeticTasks(): array {
    return [
        ['name' => 'ADD',   'data' => [[1,2,3],[3,4,7],[5,6,11],[10,20,30]]],
        ['name' => 'MUL',   'data' => [[1,2,2],[2,3,6],[3,4,12],[5,6,30]]],
        ['name' => 'MIN',   'data' => [[0,0,0],[2,3,2],[5,1,1],[4,4,4],[10,3,3]]],
        ['name' => 'MAX',   'data' => [[0,0,0],[2,3,3],[5,1,5],[4,4,4]]],
        ['name' => 'DIV',   'data' => [[6,2,3],[12,3,4],[20,4,5],[10,2,5]]],
        ['name' => 'SQUARE', 'data' => [[1,1],[2,4],[3,9],[4,16],[5,25]]],
        ['name' => 'SQRT',  'data' => [[0,0],[1,1],[4,2],[9,3],[16,4]]],
        ['name' => 'POW2',  'data' => [[0,1],[1,2],[2,4],[3,8],[4,16]]],
        ['name' => 'AND',   'data' => [[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
        ['name' => 'OR',    'data' => [[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
        ['name' => 'XOR',   'data' => [[0,0,0],[0,1,1],[1,0,1],[1,1,0]]],
    ];
}

// Числовые задачи: отношения между метриками
function getMetricsTasks(): array {
    $home = getenv('HOME');
    $metricsFile = $home . '/.bee_swarm/data/metrics.jsonl';
    // fallback: ExoCortex metrics
    if (!file_exists($metricsFile)) {
        $metricsFile = $home . '/Documents/the_lair/ExoCortex/global/metrics.jsonl';
    }
    if (!file_exists($metricsFile)) {
        return [];
    }
    $lines = file($metricsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $records = [];
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if ($r) $records[] = $r;
    }
    if (count($records) < 3) return [];
    
    $keys = array_keys($records[0]);
    $numericKeys = [];
    foreach ($keys as $k) {
        $vals = array_column($records, $k);
        $allNumeric = true;
        foreach ($vals as $v) if (!is_numeric($v)) { $allNumeric = false; break; }
        if ($allNumeric && count(array_unique($vals)) > 1) $numericKeys[] = $k;
    }
    
    $tasks = [];
    foreach ($numericKeys as $i => $xk) {
        foreach ($numericKeys as $yk) {
            if ($xk === $yk) continue;
            $name = "{$xk}→{$yk}";
            $data = [];
            foreach ($records as $r) {
                $data[] = [(float)$r[$xk], (float)$r[$yk]];
            }
            // Skip if too many points or not enough
            if (count($data) >= 3 && count($data) <= 30) {
                $tasks[] = ['name' => $name, 'data' => $data];
            }
        }
    }
    return array_slice($tasks, 0, 20); // limit
}

// Семантические задачи из knowledge_graph
function getSemanticTasks(): array {
    $db = Database::get();
    $tasks = [];
    
    // Задача 1: транзитивность предикатов
    $predicates = $db->query("SELECT DISTINCT predicate FROM knowledge_graph")->fetchAll(\PDO::FETCH_COLUMN);
    if (!$predicates) return [];
    
    foreach ($predicates as $pred) {
        // Найти все цепочки A→B→C
        $chains = $db->prepare("
            SELECT k1.subject as A, k1.object as B, k2.object as C
            FROM knowledge_graph k1
            JOIN knowledge_graph k2 ON k1.object = k2.subject
            WHERE k1.predicate = ? AND k2.predicate = ?
        ");
        $chains->execute([$pred, $pred]);
        $rows = $chains->fetchAll(\PDO::FETCH_ASSOC);
        
        if (count($rows) < 3) continue;
        
        $data = [];
        foreach ($rows as $r) {
            // Проверить: существует ли A→C?
            $hasAC = $db->prepare("SELECT COUNT(*) FROM knowledge_graph WHERE subject = ? AND predicate = ? AND object = ?");
            $hasAC->execute([$r['A'], $pred, $r['C']]);
            $exists = $hasAC->fetchColumn() > 0 ? 1.0 : 0.0;
            
            // Feature: normalised string lengths (proxy for concept similarity)
            $data[] = [
                strlen($r['A']) / 50.0,
                strlen($r['B']) / 50.0,
                strlen($r['C']) / 50.0,
                $exists
            ];
        }
        
        if (count($data) >= 3) {
            $tasks[] = ['name' => "transitivity({$pred})", 'data' => $data, 'domain' => 'semantic'];
        }
    }
    
    return $tasks;
}

// ═══ ТЕСТ ═══

echo "══════════════════════════════════════\n";
echo "  TEST HARNESS v1 — depth=$depth domain=$domain\n";
echo "══════════════════════════════════════\n\n";

// Сбор задач
$allTasks = [];
if ($domain === 'all' || $domain === 'arithmetic') {
    $allTasks = array_merge($allTasks, getArithmeticTasks(), getMetricsTasks());
}
if ($domain === 'all' || $domain === 'semantic') {
    $allTasks = array_merge($allTasks, getSemanticTasks());
}

echo "Tasks loaded: " . count($allTasks) . "\n\n";

if (empty($allTasks)) {
    echo "NO TASKS. Check data sources.\n";
    exit(1);
}

// Grammar snapshot
$grammar = new Grammar();
echo "Grammar ops: " . implode(', ', $grammar->all()) . " (" . $grammar->count() . ")\n\n";

// ═══ ПРОГОН ═══
$results = ['solved' => 0, 'failed' => 0, 'details' => []];

for ($run = 1; $run <= $runs; $run++) {
    if ($runs > 1) echo "─── Run $run/$runs ───\n";
    
    foreach ($allTasks as $task) {
        $data = $task['data'];
        if (count($data) > 30) {
            $keys = array_rand($data, 30);
            $data = array_map(fn($k) => $data[$k], $keys);
        }
        
        $X = array_map(fn($r) => array_slice($r, 0, -1), array_values($data));
        $y = array_column($data, count($data[0]) - 1);
        
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, $depth);
        
        $status = $ok ? '✅' : '❌';
        $domainTag = $task['domain'] ?? 'arithmetic';
        
        if ($verbose || !$ok) {
            printf("  %s %-30s CV=%.4f  f=%-30s [%s]\n", 
                $status, $task['name'], $cv, $formula ?: 'none', $domainTag);
        }
        
        if ($ok) {
            $results['solved']++;
        } else {
            $results['failed']++;
        }
        
        $results['details'][] = [
            'task' => $task['name'],
            'domain' => $domainTag,
            'ok' => $ok,
            'cv' => $cv,
            'formula' => $formula,
            'depth' => $depth,
            'points' => count($data),
            'features' => count($X[0] ?? []),
        ];
    }
}

// ═══ ИТОГИ ═══
$total = $results['solved'] + $results['failed'];
$rate = $total > 0 ? round($results['solved'] / $total * 100, 1) : 0;

echo "\n══════════════════════════════════════\n";
echo "  RESULTS\n";
echo "══════════════════════════════════════\n";
printf("  Depth:     %d\n", $depth);
printf("  Solved:    %d/%d (%.1f%%)\n", $results['solved'], $total, $rate);
printf("  Failed:    %d\n", $results['failed']);

// По доменам
$byDomain = [];
foreach ($results['details'] as $d) {
    $dom = $d['domain'];
    if (!isset($byDomain[$dom])) $byDomain[$dom] = ['solved' => 0, 'total' => 0];
    $byDomain[$dom]['total']++;
    if ($d['ok']) $byDomain[$dom]['solved']++;
}
echo "  By domain:\n";
foreach ($byDomain as $dom => $stat) {
    $pct = round($stat['solved'] / $stat['total'] * 100, 1);
    printf("    %-15s %d/%d (%.1f%%)\n", $dom, $stat['solved'], $stat['total'], $pct);
}

// CV distribution
$cvs = array_column($results['details'], 'cv');
sort($cvs);
if ($cvs) {
    $min = round($cvs[0], 4);
    $max = round($cvs[count($cvs)-1], 4);
    $med = round($cvs[floor(count($cvs)/2)], 4);
    printf("  CV range:  %.4f — %.4f (median: %.4f)\n", $min, $max, $med);
}

// Operations actually useful
$opsUsed = [];
foreach ($results['details'] as $d) {
    if ($d['ok'] && $d['formula']) {
        foreach (['+','−','×','/','abs','sq','sqrt','pow','MIN','MAX','parity','log2','K'] as $op) {
            if (str_contains($d['formula'], $op)) {
                $opsUsed[$op] = ($opsUsed[$op] ?? 0) + 1;
            }
        }
    }
}
if ($opsUsed) {
    arsort($opsUsed);
    echo "  Useful ops:\n";
    foreach ($opsUsed as $op => $cnt) {
        printf("    %-10s x%d\n", $op, $cnt);
    }
}

echo "\nDone.\n";
