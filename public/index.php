<?php
declare(strict_types=1);

namespace BeeSwarm;

require_once __DIR__ . '/../vendor/autoload.php';

// ── Простой HTTP-сервер (без RoadRunner) ──

$grammar = new Grammar();
$meta = new MetaInventor();
$bees = [];
for ($i = 0; $i < 5; $i++) {
    $bees[] = new Attractors("bee_$i");
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET' && $path === '/status') {
    json_response([
        'bees' => array_map(fn($b) => $b->state(), $bees),
        'grammar' => ['ops' => $grammar->all(), 'count' => $grammar->count()],
    ]);
}

if ($method === 'POST' && $path === '/solve') {
    $data = $body['data'] ?? [];
    if (empty($data)) json_response(['error' => 'no data'], 400);
    
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
        $invention = $meta->invent([[$X, $y, $taskName]], $grammar);
        if ($invention) {
            $grammar->add($invention, $taskName);
            foreach ($bees as $i => $bee) {
                [$ok2, $cv2, $f2] = Search::find($X, $y, $grammar);
                $results[] = ['bee' => "bee_$i", 'ok' => $ok2, 'cv' => $cv2, 'formula' => $f2, 'invention' => $invention];
                if ($ok2) $solved = true;
            }
        }
    }
    
    if ($solved) {
        $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0]);
        Database::get()->prepare("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
            ->execute([$taskName, $best['formula']??null, $best['cv']??9, 'api']);
    }
    
    $best = array_reduce($results, fn($a,$b) => ($b['cv']??9) < ($a['cv']??9) ? $b : $a, $results[0]);
    json_response([
        'task' => $taskName, 'solved' => $solved,
        'best_cv' => $best['cv'] ?? 9, 'best_formula' => $best['formula'] ?? null,
        'results' => array_slice($results, -5),
    ]);
}

if ($method === 'POST' && $path === '/learn') {
    $tasks = $body['tasks'] ?? [];
    $report = [];
    foreach ($tasks as $name => $data) {
        $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
        $y = array_map(fn($r) => end($r), $data);
        [$ok, $cv, $f] = Search::find($X, $y, $grammar);
        $report[$name] = ['solved' => $ok, 'cv' => $cv, 'formula' => $f];
    }
    json_response(['learned' => count($tasks), 'report' => $report]);
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
    if (empty($unsolved)) json_response(['invented' => false, 'reason' => 'all solved']);
    $inv = $meta->invent($unsolved, $grammar);
    json_response($inv ? ['invented' => true, 'operation' => $inv] : ['invented' => false, 'reason' => 'no strategy worked']);
}

json_response(['error' => 'not found'], 404);
