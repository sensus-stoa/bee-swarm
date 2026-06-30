<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Grammar;
use BeeSwarm\Database;
use BeeSwarm\Search;
use BeeSwarm\MetaInventor;
use BeeSwarm\ConsciousBee;
use BeeSwarm\SelfLearningBee;
use BeeSwarm\NestedLevel5;

use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\Goridge\Relay;
use Spiral\RoadRunner\Environment;

$env = Environment::fromGlobals();
$relay = Relay::create($env->getRelayAddress());
$worker = new HttpWorker(new \Spiral\RoadRunner\Worker($relay));

$rp = ['is_a' => '— это', 'can' => 'может', 'has' => 'имеет'];

while (true) {
    $req = $worker->waitRequest();
    if ($req === null) break;
    
    try {
        $path = parse_url($req->uri, PHP_URL_PATH);
        $method = $req->method;
        $body = json_decode($req->body ?? '{}', true) ?? [];
        $query = [];
        parse_str(parse_url($req->uri, PHP_URL_QUERY) ?? '', $query);
        
        $code = 200;
        $data = [];
        
        // ═══════════ ROUTES ═══════════
        
        if ($path === '/status') {
            $g = new Grammar(); $db = Database::get();
            $data = ['grammar' => ['ops' => $g->all(), 'count' => $g->count()], 'laws' => $db->query("SELECT COUNT(*) FROM laws")->fetchColumn()];
        }
        elseif ($method === 'POST' && $path === '/solve') {
            $d = $body['data'] ?? [];
            if (!$d) { $code = 400; $data = ['error' => 'no data']; }
            else {
                $task = $body['task'] ?? '?'; $domain = $body['domain'] ?? '?';
                $X = array_map(fn($r) => array_slice($r, 0, -1), $d);
                $y = array_map(fn($r) => end($r), $d);
                
                // 🐝🐝🐝 ПАРАЛЛЕЛЬНЫЙ РОЙ: 4 пчелы в 4 процессах
                $nBees = 4;
                $children = [];
                $results = [];
                
                for ($i = 0; $i < $nBees; $i++) {
                    // Сериализуем данные во временный JSON-файл
                    $dataFile = "/tmp/bee_data_" . getmypid() . ".json";
                    file_put_contents($dataFile, json_encode(['X' => $X, 'y' => $y]));
                    
                    $script = <<<'PHP'
                    <?php
                    require_once '~/.bee_swarm/vendor/autoload.php';
                    $data = json_decode(file_get_contents('DATA_FILE_PLACEHOLDER'), true);
                    $g = new BeeSwarm\Grammar();
                    [$ok, $cv, $formula] = BeeSwarm\Search::find($data['X'], $data['y'], $g);
                    echo json_encode(['bee' => 'BEE_ID_PLACEHOLDER', 'ok' => $ok, 'cv' => $cv, 'formula' => $formula]);
                    PHP;
                    
                    $script = str_replace('DATA_FILE_PLACEHOLDER', $dataFile, $script);
                    $script = str_replace('BEE_ID_PLACEHOLDER', "bee_{$i}", $script);
                    
                    $scriptFile = "/tmp/bee_worker_{$i}_" . getmypid() . ".php";
                    file_put_contents($scriptFile, $script);
                    $children[] = ['file' => $scriptFile, 'dataFile' => $dataFile, 'proc' => proc_open(
                        "php $scriptFile",
                        [1 => ['pipe', 'w']],
                        $pipes
                    ), 'pipes' => $pipes ?? null];
                }
                
                // Собираем результаты
                foreach ($children as $child) {
                    if (is_resource($child['proc'])) {
                        $output = stream_get_contents($child['pipes'][1]);
                        fclose($child['pipes'][1]);
                        proc_close($child['proc']);
                        $r = json_decode($output, true);
                        if ($r) $results[] = $r;
                    }
                    @unlink($child['file']);
                    @unlink($child['dataFile']);
                }
                
                // Агрегация
                $solved = false;
                foreach ($results as $r) if ($r['ok']) $solved = true;
                
                if (!$ok) {
                    $meta = new MetaInventor();
                    $g = new Grammar();
                    $inv = $meta->invent([[$X, $y, $tname]], $g);
                    if ($inv) { $g->add($inv, $tname); [$ok, $cv, $f] = Search::find($X, $y, $g, 3); if ($ok) $solved = true; }
                }
                
                $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0] ?? ['cv'=>9]);
                if ($solved) {
                    Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")->execute([$task, $best['formula']??null, $best['cv']??9, $domain]);
                }
                
                $data = ['task' => $task, 'solved' => $solved, 'best_cv' => $best['cv'] ?? 9, 'best_formula' => $best['formula'] ?? null, 'parallel_bees' => $nBees, 'results' => $results];
            }
        }
        elseif ($path === '/conscious') {
            if ($method === 'POST') {
                $cb = new ConsciousBee();
                $cb->experience($body['event'] ?? '?', $body['effects'] ?? []);
                $data = ['state' => $cb->state(), 'response' => $cb->respond('')];
            } else {
                $cb = new ConsciousBee();
                $data = ['state' => $cb->state(), 'response' => $cb->respond('статус')];
            }
        }
        elseif ($path === '/cross-domain') {
            $db = Database::get();
            $laws = $db->query("SELECT name,formula,cv,domain FROM laws ORDER BY domain,name")->fetchAll();
            $ops = ['×'=>[],'+'=>[],'−'=>[],'/'=>[],'²'=>[],'pow'=>[],'K'=>[],'parity'=>[]];
            $domains = [];
            foreach ($laws as $l) {
                $domains[$l['domain']] = ($domains[$l['domain']]??0)+1;
                foreach ($ops as $op => &$list) if (str_contains($l['formula'], $op) && !in_array($l['name'], $list)) $list[] = $l['name'];
            }
            $data = ['total_laws' => count($laws), 'domains' => $domains, 'operations' => array_map('count', $ops)];
        }
        elseif ($path === '/talk') {
            $q = $query['q'] ?? ($body['q'] ?? 'привет');
            $learner = new SelfLearningBee();
            $onto = $learner->getOntology();
            $words = preg_split('/\s+/u', mb_strtolower($q));
            $cs = [];
            foreach ($words as $w) {
                $c = $onto->resolve($w);
                if (isset($onto->concepts[$c])) $cs[] = $c;
                $inf = $learner->query($c);
                if ($inf['facts_known'] || $inf['facts_inferred']) $cs[] = $c;
            }
            if (!$cs) { $data = ['answer' => 'Не знаю. Научи: «X — это Y».', 'cv' => 1.0]; }
            else {
                $cs = array_unique($cs); $lines = []; $cov = 0;
                foreach ($cs as $c) {
                    $inf = $learner->query($c); $has = false;
                    foreach ($inf['facts_known'] as $f) { $lines[] = $f['s'].' '.($rp[$f['p']]??$f['p']).' '.$f['o']; $has = true; }
                    foreach ($inf['facts_inferred'] as $f) { $lines[] = '💡 '.$f['s'].' '.($rp[$f['p']]??$f['p']).' '.$f['o']; $has = true; }
                    if ($has) $cov++;
                }
                $cv = 1 - ($cov / count($cs));
                $data = ['answer' => $cv == 0 ? 'Точно: '.implode('; ',$lines) : 'Знаю: '.implode('. ',$lines), 'cv' => round($cv,3), 'covered' => $cov, 'total' => count($cs)];
            }
        }
        elseif ($method === 'POST' && $path === '/learn') {
            $learner = new SelfLearningBee();
            $data = $learner->learnFromRussian($body['sentence'] ?? '');
        }
        elseif ($path === '/introspect') {
            $cb = new ConsciousBee();
            $data = ['who' => 'рой, ищу CV→0', 'state' => $cb->state(), 'reflection' => $cb->respond('')];
        }
        elseif ($path === '/desire') {
            $cb = new ConsciousBee();
            $optimizer = new \BeeSwarm\SelfOptimizer();
            $optimal = $optimizer->optimalAction($cb);
            $data = [
                'desire' => $optimal['desire'],
                'optimal' => $optimal['optimal_action'],
                'reliability' => $optimal['reliability'],
                'cv' => $optimal['cv'],
                'data_driven' => true,
                'breakdown' => $optimal['all_categories'] ?? [],
            ];
        }
        elseif ($path === '/hypotheses') {
            $generator = new \BeeSwarm\HypothesisGenerator();
            $data = $generator->generate();
        }
        elseif ($path === '/test-hypotheses') {
            $tester = new \BeeSwarm\HypothesisTester();
            $data = $tester->testAll();
        }
        elseif ($path === '/request-data') {
            $requestor = new \BeeSwarm\DataRequestor();
            $data = $requestor->request();
        }
        elseif ($path === '/evolve') {
            $spawner = new \BeeSwarm\SwarmSpawner();
            $testTasks = [
                ['task' => 'AND', 'domain' => 'logic', 'data' => [[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
                ['task' => 'OR',  'domain' => 'logic', 'data' => [[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
                ['task' => 'Add', 'domain' => 'arithmetic', 'data' => [[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
            ];
            $data = $spawner->evolve(3, $testTasks);
        }
        elseif ($path === '/rewrite') {
            $rewriter = new \BeeSwarm\SelfRewriter();
            $data = $rewriter->optimizeSearch();
        }
        elseif ($path === '/optimize') {
            $optimizer = new \BeeSwarm\AutonomousOptimizer();
            $data = $optimizer->step();
        }
        elseif ($path === '/paradigm') {
            $gen = new \BeeSwarm\ParadigmHypothesis();
            $data = $gen->generate();
        }
        elseif ($path === '/validate') {
            $validator = new \BeeSwarm\ParadigmValidator();
            $data = $validator->validate();
        }
        elseif ($path === '/watchdog') {
            $wd = new \BeeSwarm\LawWatchdog();
            $data = $wd->check(
                $body['law'] ?? 'StressEnergy',
                $body['new_data'] ?? []
            );
        }
        elseif ($path === '/generation') {
            $darwin = new \BeeSwarm\DarwinLoop();
            $data = $darwin->generation();
        }
        elseif ($method === 'POST' && $path === '/domain') {
            $domain = $body['domain'] ?? 'unknown';
            $tasks = $body['tasks'] ?? [];
            $found = [];
            $g = new Grammar(); $meta = new MetaInventor();
            foreach ($tasks as $tname => $d) {
                $X = array_map(fn($r) => array_slice($r,0,-1), $d);
                $y = array_map(fn($r) => end($r), $d);
                [$ok, $cv, $f] = Search::find($X, $y, $g, 3);
                if (!$ok) {
                    $inv = $meta->invent([[$X, $y, $tname]], $g);
                    if ($inv) { $g->add($inv, $tname); [$ok, $cv, $f] = Search::find($X, $y, $g, 3); }
                }
                if ($ok) {
                    Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")->execute([$tname, $f, $cv, $domain]);
                    $found[] = ['task' => $tname, 'formula' => $f, 'cv' => $cv];
                }
            }
            $data = ['domain' => $domain, 'tasks' => count($tasks), 'laws_found' => count($found), 'discoveries' => $found];
        }
        elseif ($path === '/insight') {
            $db = Database::get();
            $domains = $db->query('SELECT domain, COUNT(*) as cnt FROM laws GROUP BY domain')->fetchAll();
            $total = $db->query('SELECT COUNT(*) FROM laws')->fetchColumn();
            $g = new Grammar(); $ops = $g->all();
            $cross = [];
            foreach ($domains as $d1) foreach ($domains as $d2) {
                if ($d1['domain'] >= $d2['domain']) continue;
                $common = [];
                foreach ($ops as $op) {
                    $cnt = $db->query("SELECT COUNT(*) FROM laws WHERE domain IN ('{$d1['domain']}','{$d2['domain']}') AND formula LIKE '%{$op}%'")->fetchColumn();
                    if ($cnt >= 2) $common[] = $op;
                }
                if ($common) $cross[] = ['domains' => [$d1['domain'], $d2['domain']], 'shared_ops' => $common];
            }
            $data = ['total_laws' => $total, 'domains' => $domains, 'cross_domain_bridges' => $cross];
        }
        else {
            $code = 404; $data = ['error' => 'not found'];
        }
        
        $worker->respond($code, json_encode($data, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        $worker->respond(500, json_encode(['error' => $e->getMessage()]));
    }
}
