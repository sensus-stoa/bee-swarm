<?php
// ~/.bee_swarm/agenda.php v4-cloze
// ДЕМОН: AtomRegistry + cloze-задачи из корпуса

date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Grammar;
use BeeSwarm\Search;
use BeeSwarm\Database;
use BeeSwarm\AtomRegistry;
use BeeSwarm\PlateauDetector;
use BeeSwarm\Forager;

$log = []; $tick = 0; $lastDiscovery = time();
$knownLaws = [];
$plateauDetector = new PlateauDetector(50);
$forager = new Forager();
$foragerScanInterval = 100;
$foragerSources = getenv('FORAGER_SOURCES')
    ? array_fill_keys(explode(':', getenv('FORAGER_SOURCES')), 1)
    : [];

// Enable held-out validation (HONEST_CRITERIA §1.1)
AtomRegistry::setHeldoutEnabled(true);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/agenda.log';

function roeLog(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

echo "[AGI v4-cloze] Daemon. Log: $logFile\n";

// Preload known laws from DB (prevents re-discovery after restart)
$preloadRows = Database::get()->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC);
foreach ($preloadRows as $row) {
    $knownLaws[$row['name'] . '::' . $row['formula']] = true;
}
roeLog("Preloaded " . count($knownLaws) . " known laws from DB");

// CLOZE: словарь корпуса + реестр предложений
$lairDir = getenv('HOME') . '/Documents/the_lair';
$corpusVocab = null;
$sentenceRegistry = null;
if (is_dir($lairDir)) {
    $corpusVocab = new \BeeSwarm\CorpusVocabulary([$lairDir]);
    $sentenceRegistry = new \BeeSwarm\SentenceRegistry([$lairDir], $corpusVocab);
    roeLog("Corpus: {$corpusVocab->size()} words, {$sentenceRegistry->count()} sentences");
}

while (true) {
    $tick++;
    
    // Process CPU guard (simple)
    $load = sys_getloadavg();
    $cpu = $load[0] / max(1, (int)shell_exec('nproc 2>/dev/null') ?: 1);
    if ($cpu > 0.7) { usleep(2000000); continue; }
    
    // Forager: сканируем источники каждые N тиков или при входе в PLATEAU
    if (!empty($foragerSources) && ($tick % $foragerScanInterval === 0 || $plateauDetector->justEnteredPlateau())) {
        $foragedTasks = $forager->scan($foragerSources);
        if (!empty($foragedTasks)) {
            $foragedTasksGlobal = array_merge($foragedTasksGlobal ?? [], $foragedTasks);
            if ($forager->hasNewContent()) {
                roeLog("FORAGER_NEW_TASK: " . $forager->getNewTaskCount()
                    . " tasks, " . $forager->getNewDomainCount() . " domains");
                $plateauDetector->wakeup();
                $forager->markContentConsumed();
            }
        }
    }
    
    $tasks = getTasks();
    if (empty($tasks)) { usleep(1000000); continue; }

    // Wakeup: новые задачи → выход из PLATEAU
    static $lastTaskCount = 0;
    $currentTaskCount = count($tasks);
    if ($currentTaskCount !== $lastTaskCount) {
        $plateauDetector->wakeup();
        $lastTaskCount = $currentTaskCount;
    }
    
    $task = $tasks[array_rand($tasks)];
    $data = $task['data'];
    if (count($data) > 30) { $keys = array_rand($data, 30); $data = array_map(fn($k) => $data[$k], $keys); $data = array_values($data); }
    $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
    $y = array_column($data, count($data[0]) - 1);
    $domain = $task['domain'] ?? 'unknown';
    
    $foundAny = false;
    
    // ═══ CLOZE: error-rate CV с context/match атомами ═══
    if ($domain === 'cloze' && $sentenceRegistry) {
        $g = new Grammar(); $grammarOps = $g->all();
        $bestAtom = null; $bestError = 1.0;
        $opIndex = 0;
        foreach ($grammarOps as $op) {
            $errors = 0; $total = count($data);
            $radius = 1 + ($opIndex % 3); // радиус 1, 2, или 3
            foreach ($data as $row) {
                [$sId, $maskPos, $targetId, $expected] = $row;
                $sentence = $sentenceRegistry->get((int)$sId);
                if (!$sentence) { $errors++; continue; }
                $ids = $sentence['token_ids'];
                
                // context(maskPos, radius) → окно вокруг маски
                $window = [];
                for ($i = max(0, $maskPos - $radius); $i <= min(count($ids) - 1, $maskPos + $radius); $i++) {
                    if ($i !== $maskPos) $window[] = $ids[$i];
                }
                
                // match(window, targetId) → 1 если target в окне
                $pred = in_array((int)$targetId, $window) ? 1.0 : 0.0;
                if (abs($pred - $expected) > 0.01) $errors++;
            }
            $er = $errors / max(1, $total);
            if ($er < $bestError) { $bestError = $er; $bestAtom = $op; }
            $opIndex++;
        }
        if ($bestAtom && $bestError < 0.5) {
            $key = $task['name'] . '::' . $bestAtom;
            if (!isset($knownLaws[$key])) {
                $knownLaws[$key] = true; $foundAny = true;
                Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")->execute([$task['name'], $bestAtom, $bestError, $domain]);
                roeLog("📖 {$task['name']} -> {$bestAtom} (err=" . round($bestError, 3) . ")");
                $lastDiscovery = time(); $plateauDetector->tick(true);
            }
        }
    }
    
    // ═══ 1. DISCOVER ═══
    if (!$foundAny && $domain !== 'cloze') {
    $discoverFn = AtomRegistry::isHeldoutEnabled() ? 'discoverHeldout' : 'discover';
    foreach ([AtomRegistry::class, $discoverFn]($X, $y) as $d) {
        $key = $task['name'] . '::' . $d['atom'];
        if (!isset($knownLaws[$key])) {
            $knownLaws[$key] = true; $foundAny = true;
            $g = new Grammar();
            if (!in_array($d['atom'], $g->all())) { $g->add($d['atom'], 'auto-discover'); }
            Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")->execute([$task['name'], $d['atom'], $d['cv'], $domain]);
            roeLog("🔍 {$task['name']} -> {$d['atom']} (CV=0) [$domain]");
            $lastDiscovery = time(); $plateauDetector->tick(true);
        }
    }
    }
    
    // ═══ 2. COMPOSE ═══
    if ($plateauDetector->shouldRunCompose() && $foundAny && $domain !== 'cloze') {
        $g = new Grammar(); $grammarOps = $g->all();
        if (count($grammarOps) >= 2) {
            foreach (AtomRegistry::discoverCompose($X, $y, $grammarOps) as $c) {
                $key = $task['name'] . '::' . $c['atom'];
                if (!isset($knownLaws[$key])) {
                    $knownLaws[$key] = true; $foundAny = true;
                    if (!in_array($c['atom'], $grammarOps)) { $g->add($c['atom'], 'auto-compose'); }
                    Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")->execute([$task['name'], $c['atom'], $c['cv'], $domain]);
                    roeLog("🧬 {$task['name']} -> {$c['atom']} (COMPOSE) [$domain]");
                    $lastDiscovery = time();
                }
            }
        }
    }
    
    if (!$foundAny) {
        usleep(500000); // 500ms sleep when nothing found
    }

    if (count($log) > 200) $log = array_slice($log, -100);
    $plateauDetector->tick($foundAny);
    if ($plateauDetector->justEnteredPlateau()) roeLog("🏔️ PLATEAU");
    usleep($plateauDetector->getSleepUs());
}

function getTasks(): array {
    static $tasks = null;
    static $lastRegen = 0;
    global $tick, $corpusVocab, $sentenceRegistry, $foragedTasksGlobal;
    
    if ($tasks !== null && ($tick - $lastRegen) < 100) return array_merge($tasks, $foragedTasksGlobal ?? []);
    $lastRegen = $tick;
    
    $tasks = [];
    
    // Метрики
    $gen = new BeeSwarm\DataSelfGenerator();
    $tasks = array_merge($tasks, $gen->fromMetrics());
    
    // Базовые задачи
    $base = [
        ['name'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
        ['name'=>'ADD','domain'=>'arithmetic','data'=>[[1,2,3],[3,4,7],[5,6,11]]],
        ['name'=>'MUL','domain'=>'arithmetic','data'=>[[1,2,2],[2,3,6],[3,4,12]]],
        ['name'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
        ['name'=>'XOR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,0]]],
        ['name'=>'SQUARE','domain'=>'arithmetic','data'=>[[1,1],[2,4],[3,9],[4,16]]],
        ['name'=>'SQRT','domain'=>'arithmetic','data'=>[[0,0],[1,1],[4,2],[9,3],[16,4]]],
        ['name'=>'MAX','domain'=>'arithmetic','data'=>[[0,0,0],[2,3,3],[5,1,5],[4,4,4]]],
        ['name'=>'DIV','domain'=>'arithmetic','data'=>[[6,2,3],[12,3,4],[20,4,5],[10,2,5]]],
    ];
    $tasks = array_merge($tasks, $base);
    
    // Self-generating compose tasks
    $g = new Grammar(); $grammarOps = $g->all();
    if (count($grammarOps) >= 2) {
        $count = 0;
        foreach ($grammarOps as $outer) {
            foreach ($grammarOps as $inner) {
                if ($outer===$inner || $count>=10) break 2;
                if (!AtomRegistry::isUnary($outer)) continue;
                $data = [];
                for ($i=0;$i<6;$i++) {
                    $x=mt_rand(-10,10); $y=mt_rand(-10,10);
                    $v1 = AtomRegistry::isBinary($inner) ? AtomRegistry::apply($inner,(float)$x,(float)$y) : AtomRegistry::apply($inner,(float)$x);
                    if ($v1===null||is_nan($v1)||is_infinite($v1)) continue;
                    $v2 = AtomRegistry::apply($outer,$v1);
                    if ($v2===null||is_nan($v2)||is_infinite($v2)) continue;
                    $data[] = [(float)$x,(float)$y,$v2];
                }
                if (count($data)>=3) { $tasks[] = ['name'=>"GEN_{$outer}_{$inner}",'data'=>$data,'domain'=>'generated']; $count++; }
            }
        }
    }
    
    // CLOZE-задачи из корпуса
    if ($sentenceRegistry && $corpusVocab && count($tasks) < 40) {
        $n = min($sentenceRegistry->count(), 50);
        for ($i = 0; $i < $n; $i++) {
            $s = $sentenceRegistry->get($i);
            if (!$s || count($s['token_ids']) < 3) continue;
            foreach ($s['token_ids'] as $pos => $tid) {
                $w = $corpusVocab->word($tid);
                if (!$w || in_array($w, ['i','v','na','s','ne','ili','no','a'])) continue;
                $d = [[$i, $pos, $tid, 1.0]];
                for ($j = 0; $j < 3; $j++) {
                    $r = mt_rand(1, $corpusVocab->size());
                    if ($r !== $tid) $d[] = [$i, $pos, $r, 0.0];
                }
                $tasks[] = ['name'=>"cloze_{$i}_{$pos}", 'data'=>$d, 'domain'=>'cloze'];
                break;
            }
        }
    }
    
    return array_merge($tasks, $foragedTasksGlobal ?? []);
}
