<?php
declare(strict_types=1);

namespace BeeSwarm;

require_once __DIR__ . '/../vendor/autoload.php';

use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\Goridge\Relay;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\Worker as RRWorker;

$grammar = new Grammar();
$meta = new MetaInventor();
$bees = [];
for ($i = 0; $i < 5; $i++) {
    $bees[] = new Attractors("bee_$i");
}

function reply(HttpWorker $w, array $data, int $code = 200): void {
    $w->respond($code, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function handle(string $method, string $path, array $body): array {
    global $grammar, $meta, $bees;
    
    if ($method === 'GET' && $path === '/status') {
        return [200, [
            'bees' => array_map(fn($b) => $b->state(), $bees),
            'grammar' => ['ops' => $grammar->all(), 'count' => $grammar->count()],
        ]];
    }
    
    if ($method === 'POST' && $path === '/solve') {
        $data = $body['data'] ?? [];
        if (empty($data)) return [400, ['error' => 'no data']];
        $taskName = $body['task'] ?? 'unknown';
        $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
        $y = array_map(fn($r) => end($r), $data);
        
        $results = []; $solved = false;
        foreach ($bees as $i => $bee) {
            [$ok, $cv, $formula] = Search::find($X, $y, $grammar);
            $bee->update($ok, $cv < 0.001 ? 0.5 : 0.0);
            $results[] = ['bee' => "bee_$i", 'ok' => $ok, 'cv' => $cv, 'formula' => $formula];
            if ($ok) $solved = true;
        }
        
        if (!$solved) {
            $inv = $meta->invent([[$X, $y, $taskName]], $grammar);
            if ($inv) {
                $grammar->add($inv, $taskName);
                foreach ($bees as $i => $bee) {
                    [$ok2, $cv2, $f2] = Search::find($X, $y, $grammar);
                    $results[] = ['bee' => "bee_$i", 'ok' => $ok2, 'cv' => $cv2, 'formula' => $f2, 'invention' => $inv];
                    if ($ok2) $solved = true;
                }
            }
            
            // Level 4: Recurrence detection
            if (!$solved) {
                $rec = $meta->detectRecurrence([[$X, $y, $taskName]], $grammar);
                if ($rec) {
                    $results[] = ['recurrence' => $rec['desc'], 'formula' => $rec['formula'] ?? 'found', 'cv' => $rec['cv'] ?? 0];
                    $solved = true;
                }
            }
        }
        
        if ($solved) {
            $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0]);
            Database::get()->prepare("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
                ->execute([$taskName, $best['formula']??null, $best['cv']??9, 'api']);
        }
        
        $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0]);
        return [200, ['task'=>$taskName,'solved'=>$solved,'best_cv'=>$best['cv']??9,'best_formula'=>$best['formula']??null,'results'=>array_slice($results,-5)]];
    }
    
    if ($method === 'POST' && $path === '/learn') {
        $tasks = $body['tasks'] ?? [];
        $report = [];
        foreach ($tasks as $name => $data) {
            $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
            $y = array_map(fn($r) => end($r), $data);
            [$ok, $cv, $f] = Search::find($X, $y, $grammar);
            $report[$name] = ['solved'=>$ok, 'cv'=>$cv, 'formula'=>$f];
        }
        return [200, ['learned'=>count($tasks), 'report'=>$report]];
    }
    
    if ($method === 'POST' && $path === '/invent') {
        $tasks = $body['tasks'] ?? [];
        $domain = $body['domain'] ?? 'unknown';
        $unsolved = [];
        foreach ($tasks as $name => $data) {
            $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
            $y = array_map(fn($r) => end($r), $data);
            [$ok] = Search::find($X, $y, $grammar);
            if (!$ok) $unsolved[] = [$X, $y, $name];
        }
        if (empty($unsolved)) return [200, ['invented'=>false,'reason'=>'all solved']];
        $inv = $meta->invent($unsolved, $grammar);
        return [200, $inv ? ['invented'=>true,'operation'=>$inv] : ['invented'=>false,'reason'=>'no strategy worked']];
    }
    
    return [404, ['error'=>'not found']];
}

// ── RoadRunner loop ──
$env = Environment::fromGlobals();
$relay = Relay::create($env->getRelayAddress());
$worker = new HttpWorker(new RRWorker($relay));

while (true) {
    $grammar->reloadFromDb();  // sync across workers
    $req = $worker->waitRequest();
    if ($req === null) break;
    
    try {
        $path = parse_url($req->uri, PHP_URL_PATH);
        $method = $req->method;
        $body = json_decode($req->body, true) ?? [];
        
        // Check hive knowledge before solving
        $taskName = $body['task'] ?? '';
        if ($method === 'POST' && ($path === '/solve') && $taskName) {
            try {
                $db = Database::get();
                $stmt = $db->prepare("SELECT formula, cv FROM waggle_dance WHERE task_name = ? ORDER BY cv ASC LIMIT 1");
                if ($stmt) {
                    $stmt->execute([$taskName]);
                    $known = $stmt->fetch();
                    if ($known) {
                        reply($worker, [
                            'task' => $taskName, 'solved' => true,
                            'best_cv' => $known['cv'], 'best_formula' => $known['formula'],
                            'results' => [['dance' => 'known from hive', 'formula' => $known['formula']]]
                        ]);
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // Table might not exist yet, proceed normally
            }
        }
        
        [$code, $data] = handle($method, $path, $body);
        
        // Waggle dance: broadcast if solved
        if ($method === 'POST' && $path === '/solve' && ($data['solved'] ?? false)) {
            $db = Database::get();
            $db->prepare("INSERT INTO waggle_dance (bee_name, task_name, formula, cv, strategy_used) VALUES (?,?,?,?,?)")
               ->execute(['worker_bee', $taskName, $data['best_formula'] ?? 'found', $data['best_cv'] ?? 0, 'cv_search']);
            
            // Coalition check: multiple bees found this task?
            try {
                $dances = $db->prepare("SELECT bee_name, formula, cv FROM waggle_dance WHERE task_name = ?");
                if ($dances) {
                    $dances->execute([$taskName]);
                    $dances = $dances->fetchAll();
                    if (count($dances) >= 2) {
                        $formulas = array_column($dances, 'formula');
                        $cvs = array_column($dances, 'cv');
                        $fidelity = 1.0 / (1.0 + (max($cvs) - min($cvs)));
                        $resolved = $formulas[array_search(min($cvs), $cvs)];
                        $bees = implode(',', array_column($dances, 'bee_name'));
                        $db->prepare("INSERT OR IGNORE INTO coalition (task_name, bees_involved, formulas_found, resolved_formula, fidelity) VALUES (?,?,?,?,?)")
                           ->execute([$taskName, $bees, implode(' | ', $formulas), $resolved, $fidelity]);
                    }
                }
            } catch (\Throwable $e) { /* coalition silently fails */ }
        }
        
        // Paradigm spawn: if a new grammar op was invented, record it
        if ($method === 'POST' && $path === '/invent' && ($data['invented'] ?? false)) {
            $db = Database::get();
            $opName = $data['operation'] ?? 'unknown';
            $domain = $body['domain'] ?? 'unknown';
            $db->prepare("INSERT OR IGNORE INTO paradigms (name, domain, grammar_ops, spawned_from) VALUES (?,?,?,?)")
               ->execute([$opName, $domain, $opName, $domain]);
        }
        
        reply($worker, $data, $code);
    } catch (\Throwable $e) {
        reply($worker, ['error' => $e->getMessage()], 500);
    }
}
