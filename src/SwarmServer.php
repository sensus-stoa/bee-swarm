<?php
declare(strict_types=1);

namespace BeeSwarm;

require_once __DIR__ . '/../vendor/autoload.php';

use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Worker as RRWorker;

class SwarmServer
{
    private Grammar $grammar;
    private array $bees = [];
    private MetaInventor $meta;
    
    public function __construct()
    {
        $this->grammar = new Grammar();
        $this->meta = new MetaInventor();
        for ($i = 0; $i < 5; $i++) {
            $this->bees[] = new Attractors("bee_$i");
        }
    }
    
    private function json(array $data, int $code = 200): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    private function handle(string $method, string $path, array $body): array
    {
        if ($method === 'GET' && $path === '/status') {
            return [200, [
                'bees' => array_map(fn($b) => $b->state(), $this->bees),
                'grammar' => ['ops' => $this->grammar->all(), 'count' => $this->grammar->count()],
            ]];
        }
        
        if ($method === 'POST' && $path === '/solve') {
            $data = $body['data'] ?? [];
            if (empty($data)) return [400, ['error' => 'no data']];
            $taskName = $body['task'] ?? 'unknown';
            $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
            $y = array_map(fn($r) => end($r), $data);
            
            $results = []; $solved = false;
            foreach ($this->bees as $i => $bee) {
                [$ok, $cv, $formula] = Search::find($X, $y, $this->grammar);
                $bee->update($ok, $cv < 0.001 ? 0.5 : 0.0);
                $results[] = ['bee' => "bee_$i", 'ok' => $ok, 'cv' => $cv, 'formula' => $formula];
                if ($ok) $solved = true;
            }
            
            if (!$solved) {
                $invention = $this->meta->invent([[$X, $y, $taskName]], $this->grammar);
                if ($invention) {
                    $this->grammar->add($invention, $taskName);
                    foreach ($this->bees as $i => $bee) {
                        [$ok2, $cv2, $f2] = Search::find($X, $y, $this->grammar);
                        $results[] = ['bee' => "bee_$i", 'ok' => $ok2, 'cv' => $cv2, 'formula' => $f2, 'invention' => $invention];
                        if ($ok2) $solved = true;
                    }
                }
                
                // Level 4: Recurrence detection
                if (!$solved) {
                    $recurrence = $this->meta->detectRecurrence([[$X, $y, $taskName]], $this->grammar);
                    if ($recurrence) {
                        $results[] = ['recurrence' => $recurrence['desc'], 'formula' => $recurrence['formula'] ?? 'found', 'cv' => $recurrence['cv'] ?? 0];
                        $solved = true;
                    }
                }
            }
            
            if ($solved) {
                $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0]);
                $db = Database::get();
                $db->prepare("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
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
                [$ok, $cv, $f] = Search::find($X, $y, $this->grammar);
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
                [$ok] = Search::find($X, $y, $this->grammar);
                if (!$ok) $unsolved[] = [$X, $y, $name];
            }
            if (empty($unsolved)) return [200, ['invented'=>false,'reason'=>'all solved']];
            $inv = $this->meta->invent($unsolved, $this->grammar);
            if ($inv) { $this->grammar->add($inv, $domain); return [200, ['invented'=>true,'operation'=>$inv]]; }
            return [200, ['invented'=>false,'reason'=>'no strategy worked']];
        }
        
        return [404, ['error'=>'not found']];
    }
    
    public function run(): void
    {
        $worker = new HttpWorker(RRWorker::create());
        while ($req = $worker->waitRequest()) {
            try {
                $method = $req->getMethod();
                $path = $req->getUri()->getPath();
                $body = json_decode((string)$req->getBody(), true) ?? [];
                [$code, $data] = $this->handle($method, $path, $body);
                $resp = (new \Spiral\RoadRunner\Http\Response($code, json_encode($data, JSON_UNESCAPED_UNICODE)));
                $worker->respond($resp);
            } catch (\Throwable $e) {
                $worker->respond(new \Spiral\RoadRunner\Http\Response(500, json_encode(['error'=>$e->getMessage()])));
            }
        }
    }
}

(new SwarmServer())->run();
