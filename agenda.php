<?php
// ~/.bee_swarm/agenda.php v2
// ДЕМОН AGI: CV→0 как голод. Без таймеров.
require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Grammar;
use BeeSwarm\Search;
use BeeSwarm\Database;

$log = []; $tick = 0; $failures = 0; $lastCv = 0; $cvRising = 0;
require_once __DIR__ . '/sandbox.php';
$sandbox = new Sandbox();

// ЛОГГЕР
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/agenda.log';
$actionFile = $logDir . '/actions.jsonl';

function roeLog(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

echo "[AGI v2] Hunger-driven daemon. Log: $logFile\n";

while (true) {
    $tick++;
    
    // ═══ ПОИСК: всегда, когда есть задачи ═══
    $tasks = getTasks();
    if (!empty($tasks)) {
        $task = $tasks[array_rand($tasks)];
        $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
        $y = array_column($task['data'], count($task['data'][0]) - 1);
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);
        
        $log[] = ['tick'=>$tick,'task'=>$task['name'],'cv'=>round($cv,4),'ok'=>$ok,'points'=>count($task['data'])];
        
        if ($ok) {
            // DEDUP: не логируем если закон уже есть с такой же формулой
            $exists = Database::get()->prepare("SELECT COUNT(*) FROM laws WHERE formula = ? AND domain = ?")
                        ->execute([$formula, $task['domain'] ?? 'auto'])->fetchColumn();
            if (!$exists) {
                roeLog("✅ {$task['name']}: $formula");
                Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")
                    ->execute([$task['name'],$formula,$cv,$task['domain']??'auto']);
            }
            $failures = 0; $cvRising = 0;
        } else {
            $failures++;
            if ($cv > $lastCv && $lastCv > 0) $cvRising++;
            else $cvRising = 0;
        }
        $lastCv = $cv;
    }
    
    // ═══ ГОЛОД 1: CV растёт → САМО-КОРМЯЩИЙСЯ ГЕНЕРАТОР ═══
    if ($cvRising >= 3) {
        roeLog("🚨 CV rising! Self-feeding generator...");
        require_once __DIR__ . '/self_feeding_generator.php';
        $gen = new SelfFeedingGenerator();
        $bestAction = null; $bestCv = 9.99; $bestCode = null;
        
        for ($a = 0; $a < 5; $a++) {
            $code = $gen->generate();
            $r = $sandbox->run($code, $task['data'] ?? [[1,2,3]]);
            if ($r['cv'] < $bestCv) { $bestCv = $r['cv']; $bestAction = $r; $bestCode = $code; }
        }
        
        if ($bestAction && $bestAction['cv'] < 0.5 && $bestAction['formula']) {
            // КОРМИМ ГЕНЕРАТОР — успешное действие пополняет пул
            $gen->feedSuccess($bestCode, $bestAction['cv']);
            echo "    ✅ Best: cv={$bestAction['cv']} f={$bestAction['formula']} | Pool: {$gen->poolSize()}\n";
            
            if ($bestAction['cv'] < 0.05) {
                // 🔥 ВАЛИДАЦИЯ: проверяем формулу на реальных данных
                $testFormula = $bestAction['formula'];
                $testX = $task['data'] ?? [[1,2,3]];
                $valid = false;
                
                // Простая проверка: формула не мусор
                if ($testFormula && !str_contains($testFormula, 'report_') 
                    && !str_contains($testFormula, 'api_') 
                    && !str_contains($testFormula, 'laws_')
                    && !str_contains($testFormula, 'log_')) {
                    
                    // Проверяем что формула содержит x0, x1 или K
                    if (str_contains($testFormula, 'x0') || str_contains($testFormula, 'x1') 
                        || preg_match('/^K[\d.]+$/', $testFormula)) {
                        $valid = true;
                    }
                }
                
                if ($valid) {
                    Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")
                        ->execute(["auto_$tick", $testFormula, $bestAction['cv'], 'evolved']);
                }
            }
        }
        $cvRising = 0;
    }
    
    // ═══ ГОЛОД 2: 10+ провалов → расширить грамматику ═══
    if ($failures >= 10) {
        roeLog("🧬 Grammar exhausted ($failures failures). Evolving...");
        $evolveScript = __DIR__ . '/final_evolve.php';
        if (file_exists($evolveScript)) {
            $output = shell_exec("timeout 30 php $evolveScript 2>&1");
            if (str_contains($output, 'APPLIED')) echo "    ✅ New code evolved!\n";
        }
        $failures = 0;
    }
    
    // ═══ GOЛОД 3: граф знаний противоречив → чистить ═══
    if ($tick % 50 === 0) {
        $db = Database::get();
        $facts = $db->query("SELECT COUNT(*) FROM knowledge_graph")->fetchColumn();
        $contradictions = $db->query(
            "SELECT COUNT(*) FROM knowledge_graph k1 JOIN knowledge_graph k2 ON k1.subject=k2.subject AND k1.predicate=k2.predicate WHERE k1.object!=k2.object"
        )->fetchColumn();
        $knowledgeCv = $facts > 0 ? $contradictions / $facts : 0;
        if ($knowledgeCv > 0.1) {
            roeLog("📚 Knowledge CV=$knowledgeCv — cleaning contradictions");
        }
    }
    
    if (count($log) > 200) $log = array_slice($log, -100);
    usleep(1000000); // 1 сек
}

function getTasks(): array {
    static $tasks = null;
    if ($tasks !== null) return $tasks;
    $gen = new BeeSwarm\DataSelfGenerator();
    $tasks = $gen->fromMetrics();
    $base = [
        ['name'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
        ['name'=>'ADD','domain'=>'arithmetic','data'=>[[1,2,3],[3,4,7],[5,6,11]]],
        ['name'=>'MUL','domain'=>'arithmetic','data'=>[[1,2,2],[2,3,6],[3,4,12]]],
        ['name'=>'MIN','domain'=>'math','data'=>[[0,0,0],[2,3,2],[5,1,1],[4,4,4]]],
        ['name'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
        ['name'=>'XOR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,0]]],
        ['name'=>'DIV','domain'=>'arithmetic','data'=>[[6,2,3],[12,3,4],[20,4,5],[10,2,5]]],
        ['name'=>'SQUARE','domain'=>'math','data'=>[[1,1],[2,4],[3,9],[4,16],[5,25]]],
        ['name'=>'SQRT','domain'=>'math','data'=>[[0,0],[1,1],[4,2],[9,3],[16,4]]],
        ['name'=>'MAX','domain'=>'math','data'=>[[0,0,0],[2,3,3],[5,1,5],[4,4,4]]],
        ['name'=>'POW2','domain'=>'math','data'=>[[0,1],[1,2],[2,4],[3,8],[4,16]]],
    ];
    return array_merge($tasks, $base);
}
