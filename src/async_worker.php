<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Grammar;
use BeeSwarm\Search;
use BeeSwarm\MetaInventor;
use BeeSwarm\NestedLevel5;
use BeeSwarm\Database;

$config = new \TrueAsync\HttpServerConfig(host: '0.0.0.0', port: 8765);
$config->setWorkers(2);

$server = new \TrueAsync\HttpServer($config);

$server->addHttpHandler(function(\TrueAsync\HttpRequest $req, \TrueAsync\HttpResponse $res) {
    static $nested5 = null;
    static $learner = null;  // lazy внутри static
    require_once '/swarm/vendor/autoload.php';  // per-worker autoloader
    
    $res->setHeader('Content-Type', 'application/json; charset=utf-8');
    $res->setHeader('Access-Control-Allow-Origin', '*');
    
    // Fresh instances per request
    $grammar = new Grammar();
    $meta = new MetaInventor();
    
    $path = $req->getPath();
    $method = $req->getMethod();
    
    try {
        if ($method === 'GET' && $path === '/status') {
            $res->end(json_encode([
                'grammar' => ['ops' => $grammar->all(), 'count' => $grammar->count()],
                'mode' => 'TrueAsync + ThreadPool 🚀',
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        if ($method === 'POST' && $path === '/solve') {
            $body = json_decode($req->getBody(), true) ?? [];
            $data = $body['data'] ?? [];
            if (empty($data)) { $res->setStatusCode(400); $res->end('{"error":"no data"}'); return; }
            
            $taskName = $body['task'] ?? 'unknown';
            $domain = $body['domain'] ?? 'unknown';  // domain tagging!
            $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
            $y = array_map(fn($r) => end($r), $data);
            
            // ПАРАЛЛЕЛЬНЫЙ поиск в ThreadPool
            $nBees = min(4, Async\available_parallelism());
            $pool = new Async\ThreadPool($nBees);
            $futures = [];
            for ($i = 0; $i < $nBees; $i++) {
                $futures[] = $pool->submit(function() use ($X, $y, $i) {
                    require_once '/swarm/vendor/autoload.php';
                    $g = new BeeSwarm\Grammar();
                    $g->reloadFromDb();
                    [$ok, $cv, $formula] = BeeSwarm\Search::find($X, $y, $g);
                    return ['bee' => "bee_$i", 'ok' => $ok, 'cv' => $cv, 'formula' => $formula];
                });
            }
            
            $results = [];
            foreach ($futures as $f) { $results[] = $f->await(); }
            $pool->close();
            
            $solved = false;
            foreach ($results as $r) { if ($r['ok']) $solved = true; }
            
            if (!$solved) {
                $inv = $meta->invent([[$X, $y, $taskName]], $grammar);
                if ($inv) {
                    $grammar->add($inv, $taskName);
                    // Повторный параллельный поиск
                    $pool2 = new Async\ThreadPool(2);
                    $f2 = [];
                    for ($i = 0; $i < 4; $i++) {
                        $f2[] = $pool2->submit(function() use ($X, $y, $i) {
                            require_once '/swarm/vendor/autoload.php';
                            $g = new BeeSwarm\Grammar();
                            $g->reloadFromDb();
                            [$ok2, $cv2, $f2] = BeeSwarm\Search::find($X, $y, $g);
                            return ['bee' => "bee_$i", 'ok' => $ok2, 'cv' => $cv2, 'formula' => $f2];
                        });
                    }
                    foreach ($f2 as $f) { $r = $f->await(); $results[] = $r; if (($r['ok']??false)) $solved = true; }
                    $pool2->close();
                }
                
                // NESTED Level 5: meta-learning fallback (sequential — no ThreadPool for stability)
                if (!$solved) {
                    if ($nested5 === null) $nested5 = new NestedLevel5();  // lazy init
                    $inv5 = $nested5->invent([[$X, $y, $taskName]], $grammar);
                    if ($inv5) {
                        [$opName, $opFn] = $inv5;
                        $grammar->add($opName, "auto-l5");
                        // Sequential retry (avoids zend_mm_heap corruption in threads)
                        $g2 = new Grammar();
                        $g2->reloadFromDb();
                        for ($i = 0; $i < 4; $i++) {
                            [$ok3, $cv3, $f3] = Search::find($X, $y, $g2);
                            $results[] = ['bee' => "bee_$i", 'ok' => $ok3, 'cv' => $cv3, 'formula' => $f3, 'l5' => true];
                            if ($ok3) $solved = true;
                        }
                    }
                }
            }
            
            if ($solved) {
                $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0]);
                Database::get()->prepare("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
                    ->execute([$taskName, $best['formula']??null, $best['cv']??9, $domain]);
            }
            
            $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0]);
            $res->end(json_encode([
                'task' => $taskName, 'solved' => $solved,
                'best_cv' => $best['cv'] ?? 9, 'best_formula' => $best['formula'] ?? null,
                'mode' => 'parallel',
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        if ($method === 'GET' && $path === '/cross-domain') {
            // Анализ кросс-доменных инвариантов
            $db = Database::get();
            $laws = $db->query("SELECT name, formula, cv, domain FROM laws ORDER BY domain, name")->fetchAll();
            
            // Группируем по типу операций
            $ops = ['×' => [], '+' => [], '−' => [], '/' => [], '²' => [], 'pow' => [], 'K' => [], 'parity' => []];
            $domains = [];
            
            foreach ($laws as $law) {
                $f = $law['formula'];
                $d = $law['domain'];
                $domains[$d] = ($domains[$d] ?? 0) + 1;
                
                foreach ($ops as $op => &$list) {
                    if (str_contains($f, $op)) {
                        if (!in_array($law['name'], $list)) {
                            $list[] = $law['name'];
                        }
                    }
                }
            }
            unset($list);
            
            // Находим универсальные операции (используются в ≥2 разных законах)
            $universal = [];
            $patternCounts = [];
            foreach ($laws as $law) {
                $f = $law['formula'];
                // Нормализуем: убираем индексы переменных
                $normalized = preg_replace('/x\d+/', 'x', $f);
                $patternCounts[$normalized] = ($patternCounts[$normalized] ?? 0) + 1;
            }
            
            // Паттерны которые встречаются в ≥2 законах
            $crossDomain = [];
            foreach ($patternCounts as $pattern => $count) {
                if ($count >= 2) {
                    // Найти какие законы используют этот паттерн
                    $users = [];
                    foreach ($laws as $law) {
                        $nl = preg_replace('/x\d+/', 'x', $law['formula']);
                        if ($nl === $pattern) {
                            $users[] = $law['name'];
                        }
                    }
                    $crossDomain[] = ['pattern' => $pattern, 'count' => $count, 'laws' => $users];
                }
            }
            
            $res->end(json_encode([
                'total_laws' => count($laws),
                'domains' => $domains,
                'operations' => array_map('count', $ops),
                'universal_patterns' => $crossDomain,
                'top_universal' => array_slice(
                    array_filter($ops, fn($l) => count($l) >= 2), 0, 5
                ),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }
        
        // GET /talk?q=... — общение с роем
        if ($method === 'GET' && $path === '/talk') {
            $query = $req->getQuery();
            $question = $query['q'] ?? 'привет';
            
            // Собираем консенсус-состояние роя
            $db = Database::get();
            $laws = $db->query("SELECT name, formula, cv, domain FROM laws ORDER BY cv ASC")->fetchAll();
            $domains = $db->query("SELECT domain, COUNT(*) as cnt FROM laws GROUP BY domain")->fetchAll();
            $domainsMap = [];
            foreach ($domains as $d) $domainsMap[$d['domain']] = $d['cnt'];
            
            $swarmState = [
                'bees' => 4,
                'energy' => 0.8,  // из последних успехов
                'curiosity' => 0.6,
                'laws_count' => count($laws),
                'laws_list' => $laws,
                'domains' => $domainsMap,
                'domains_count' => count($domainsMap),
                'grammar_ops' => $grammar->all(),
                'grammar_count' => $grammar->count(),
                'last_cv' => $laws[0]['cv'] ?? 9.99,
                'last_formula' => $laws[0]['formula'] ?? '?',
                'last_desc' => ($laws[0]['name'] ?? '?') . ' = ' . ($laws[0]['formula'] ?? '?'),
            ];
            
            // Семантический поиск ответа (CV→0 в пространстве смыслов)
            $engine = new \BeeSwarm\SemanticEngine();
            $questionRels = $engine->parse($question);
            $candidates = $engine->search($questionRels, $swarmState);
            $answer = $engine->respond($candidates, $swarmState);
            
            // Добавляем отладочную информацию о поиске
            $bestCv = $candidates[0]['cv'] ?? 1.0;
            
            $res->end(json_encode([
                'question' => $question,
                'answer' => $answer,
                'cv' => $bestCv,
                'relations_found' => count($candidates),
                'state' => [
                    'energy' => $swarmState['energy'],
                    'curiosity' => $swarmState['curiosity'],
                    'laws' => $swarmState['laws_count'],
                    'domains' => $swarmState['domains_count'],
                    'grammar' => $swarmState['grammar_count'],
                ],
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        // GET /code?topic=... — генерация PHP-кода
        if ($method === 'GET' && $path === '/code') {
            $query = $req->getQuery();
            $topic = $query['topic'] ?? 'Сократ';
            $ontology = new \BeeSwarm\Ontology();
            $codeGen = new \BeeSwarm\CodeGenerator();
            $result = $codeGen->generate($topic, $ontology);
            $res->end(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }
        
        // POST /learn-fact — обучить пчелу новому факту (persistent)
        if ($method === 'POST' && $path === '/learn-fact') {
            $sentence = $body['sentence'] ?? '';
            if (empty($sentence)) {
                $res->setStatusCode(400);
                $res->end(json_encode(['error' => 'нужно предложение']));
                return;
            }
            $result = $learner->learnFromRussian($sentence);
            $result['stats'] = $learner->stats();
            $res->end(json_encode($result, JSON_UNESCAPED_UNICODE));
            return;
        }
        
        // GET /query?concept=... — что пчела знает о концепте (persistent)
        if ($method === 'GET' && $path === '/query') {
            $query = $req->getQuery();
            $concept = $query['concept'] ?? 'пчела';
            $result = $learner->query($concept);
            $res->end(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }
        
        if ($path === '/debug-body') {
            $raw = $req->getBody();
            $parsed = json_decode($raw, true);
            $res->setStatusCode(200);
            $res->end(json_encode(['raw' => $raw, 'parsed' => $parsed, 'len' => strlen($raw)]));
            return;
        }
        
        // GET /conscious
        // POST /conscious/experience — прожить событие (persistent)
        if (($method === 'GET' && $path === '/conscious') || 
            ($method === 'POST' && $path === '/conscious/experience')) {
            static $cb = null;
            if ($cb === null) $cb = new \BeeSwarm\ConsciousBee();
            
            if ($method === 'POST') {
                $rawBody = $req->getBody();
                $event = $body['event'] ?? 'неизвестное событие';
                $effects = $body['effects'] ?? [];
                $dbg = ['before' => $cb->virtue, 'effects' => $effects, 'raw' => strlen($rawBody) . ' bytes'];
                $cb->experience($event, $effects);
                $dbg['after'] = $cb->virtue;
            }
            
            $res->end(json_encode([
                'state' => $cb->state(),
                'strategy' => $cb->searchStrategy(),
                'response' => $cb->respond($method === 'POST' ? '' : 'статус'),
                'debug' => $dbg ?? null,
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        
        $res->setStatusCode(404);
        $res->end('{"error":"not found"}');
    } catch (\Throwable $e) {
        $res->setStatusCode(500);
        $res->end(json_encode(['error' => $e->getMessage()]));
    }
});

$server->start();
