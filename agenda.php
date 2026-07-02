<?php
// ~/.bee_swarm/agenda.php v3
// ДЕМОН AGI: AtomRegistry → discover → compose → grammar растёт
// Новые домены = выше награда. Старые законы не кормят.

date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Grammar;
use BeeSwarm\Search;
use BeeSwarm\Database;
use BeeSwarm\AtomRegistry;

$log = []; $tick = 0; $failures = 0; $lastDiscovery = time();
$knownLaws = []; // task_atom => true — чтобы не награждать повторно

// ЛОГГЕР
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/agenda.log';

function roeLog(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

echo "[AGI v3] AtomRegistry-driven daemon. Log: $logFile\n";

while (true) {
    $tick++;
    
    $tasks = getTasks();
    if (empty($tasks)) { usleep(1000000); continue; }
    
    $task = $tasks[array_rand($tasks)];
    $data = $task['data'];
    if (count($data) > 30) {
        $keys = array_rand($data, 30);
        $data = array_map(fn($k) => $data[$k], $keys);
        $data = array_values($data);
    }
    $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
    $y = array_column($data, count($data[0]) - 1);
    
    $domain = $task['domain'] ?? 'unknown';
    $novelty = ($domain === 'cross') ? 5.0 : (($domain === 'semantic') ? 2.0 : 1.0);
    
    $foundAny = false;
    
    // ═══ 1. DISCOVER: перебор всех атомов среды ═══
    $discovered = AtomRegistry::discover($X, $y);
    
    foreach ($discovered as $d) {
        $atomName = $d['atom'];
        $key = $task['name'] . '::' . $atomName;
        
        if (!isset($knownLaws[$key])) {
            $knownLaws[$key] = true;
            $foundAny = true;
            
            // Добавить атом в grammar
            $g = new Grammar();
            if (!in_array($atomName, $g->all())) {
                $g->add($atomName, 'auto-discover');
            }
            
            // Сохранить закон
            $formula = $atomName . (count($X[0]) >= 2 ? '(x0,x1)' : '(x0)');
            Database::get()->prepare(
                "INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)"
            )->execute([$task['name'], $formula, $d['cv'], $domain]);
            
            roeLog("🔍 {$task['name']} → $atomName (CV=0, +$novelty energy) [$domain]");
            $lastDiscovery = time();
            $failures = 0;
        }
    }
    
    // ═══ 2. COMPOSE: пары grammar-атомов ═══
    if (!$foundAny && $failures >= 2) {
        $g = new Grammar();
        $grammarOps = $g->all();
        
        if (count($grammarOps) >= 2) {
            $composed = AtomRegistry::discoverCompose($X, $y, $grammarOps);
            
            foreach ($composed as $c) {
                $key = $task['name'] . '::' . $c['atom'];
                
                if (!isset($knownLaws[$key])) {
                    $knownLaws[$key] = true;
                    $foundAny = true;
                    
                    if (!in_array($c['atom'], $grammarOps)) {
                        $g->add($c['atom'], 'auto-compose');
                    }
                    
                    Database::get()->prepare(
                        "INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)"
                    )->execute([$task['name'], $c['atom'], $c['cv'], $domain]);
                    
                    roeLog("🧬 {$task['name']} → {$c['atom']} (COMPOSE, +" . ($novelty * 1.5) . " energy) [$domain]");
                    $lastDiscovery = time();
                    $failures = 0;
                }
            }
        }
    }
    
    // ═══ 3. FALLBACK: Search::find (старый механизм) ═══
    if (!$foundAny) {
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);
        
        if ($ok) {
            $stmt = Database::get()->prepare(
                "SELECT COUNT(*) FROM laws WHERE formula = ? AND domain = ?"
            );
            $exists = false;
            if ($stmt) {
                $stmt->execute([$formula, $domain]);
                $exists = $stmt->fetchColumn() > 0;
            }
            if (!$exists) {
                roeLog("✅ {$task['name']}: $formula (Search::find)");
                Database::get()->prepare(
                    "INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)"
                )->execute([$task['name'], $formula, $cv, $domain]);
                $lastDiscovery = time();
                $foundAny = true;
            }
            $failures = 0;
        } else {
            $failures++;
        }
    }
    
    // ═══ 4. ГОЛОД: 10 минут без открытий → пополнить задачи ═══
    $starving = time() - $lastDiscovery;
    if ($starving > 600) {
        roeLog("💀 STARVATION: $starving сек без открытий.");
        // Сбросить кэш задач — может появиться новый домен
        $tasks = null;
        $lastDiscovery = time();
        roeLog("🔄 Task cache cleared. Seeking new domains.");
    }
    if ($tick % 100 === 0 && $starving > 300) {
        roeLog("⏳ Голод: $starving сек без новых законов... (" . count($knownLaws) . " known)");
    }
    
    if (count($log) > 200) $log = array_slice($log, -100);
    usleep(1000000); // 1 сек
}

function getTasks(): array {
    static $tasks = null;
    if ($tasks !== null) return $tasks;
    
    $tasks = [];
    
    // Метрики пользователя (если есть)
    $gen = new BeeSwarm\DataSelfGenerator();
    $metricTasks = $gen->fromMetrics();
    $tasks = array_merge($tasks, $metricTasks);
    
    // Базовые задачи
    $base = [
        ['name'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
        ['name'=>'ADD','domain'=>'arithmetic','data'=>[[1,2,3],[3,4,7],[5,6,11]]],
        ['name'=>'MUL','domain'=>'arithmetic','data'=>[[1,2,2],[2,3,6],[3,4,12]]],
        ['name'=>'MIN','domain'=>'arithmetic','data'=>[[0,0,0],[2,3,2],[5,1,1],[4,4,4]]],
        ['name'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
        ['name'=>'XOR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,0]]],
        ['name'=>'DIV','domain'=>'arithmetic','data'=>[[6,2,3],[12,3,4],[20,4,5],[10,2,5]]],
        ['name'=>'SQUARE','domain'=>'arithmetic','data'=>[[1,1],[2,4],[3,9],[4,16],[5,25]]],
        ['name'=>'SQRT','domain'=>'arithmetic','data'=>[[0,0],[1,1],[4,2],[9,3],[16,4]]],
        ['name'=>'MAX','domain'=>'arithmetic','data'=>[[0,0,0],[2,3,3],[5,1,5],[4,4,4]]],
        ['name'=>'POW2','domain'=>'arithmetic','data'=>[[0,1],[1,2],[2,4],[3,8],[4,16]]],
        // Кросс-домен: compose задачи (5x reward)
        ['name'=>'ABS_DIFF','domain'=>'cross','data'=>[[1,3,2],[5,1,4],[2,2,0],[0,5,5]]],
        ['name'=>'SQ_SUM','domain'=>'cross','data'=>[[1,2,9],[3,1,16],[0,0,0],[2,3,25]]],
        ['name'=>'MIN_MUL','domain'=>'cross','data'=>[[2,5,3,6],[3,1,2,2],[4,4,1,4]]],
    ];
    $tasks = array_merge($tasks, $base);
    
    // Семантические задачи из Obsidian (если доступны)
    $home = getenv('HOME');
    $insightsDir = $home . '/Documents/the_lair/ExoCortex/Journal/global/insights';
    if (is_dir($insightsDir)) {
        foreach (glob($insightsDir . '/*.md') as $f) {
            $content = file_get_contents($f);
            if (!$content) continue;
            
            // criticality: один субъект → одно значение (инвариант)
            if (preg_match('/criticality:\s*(\w)/', $content, $cm)) {
                $name = basename($f, '.md');
                $val = $cm[1] === 'A' ? 1.0 : ($cm[1] === 'B' ? 0.5 : 0.0);
                $tasks[] = [
                    'name' => "criticality($name)",
                    'domain' => 'semantic',
                    'data' => [[(float)abs(crc32($name) % 10), $val]],
                ];
            }
        }
    }
    
    return $tasks;
}
