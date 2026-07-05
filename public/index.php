<?php
require_once __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Grammar;
use BeeSwarm\Infra\Database;
use BeeSwarm\Search;
use BeeSwarm\Meta\MetaInventor;
use BeeSwarm\Bee\ConsciousBee;
use BeeSwarm\Bee\SelfLearningBee;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$query = $_GET;

function reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════
// Эндпоинты
// ═══════════════════════════════════════════════

if ($path === '/status') {
    $g = new Grammar(); $db = Database::get();
    reply(['grammar' => ['ops' => $g->all(), 'count' => $g->count()], 'laws' => $db->query("SELECT COUNT(*) FROM laws")->fetchColumn()]);
}

if ($method === 'POST' && $path === '/solve') {
    $data = $body['data'] ?? [];
    if (!$data) reply(['error' => 'no data'], 400);
    $task = $body['task'] ?? 'unknown';
    $domain = $body['domain'] ?? 'unknown';
    $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
    $y = array_map(fn($r) => end($r), $data);
    $g = new Grammar();
    [$ok, $cv, $formula] = Search::find($X, $y, $g);
    if ($ok) {
        Database::get()->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")->execute([$task, $formula, $cv, $domain]);
    }
    reply(['task' => $task, 'solved' => $ok, 'best_cv' => $cv, 'best_formula' => $formula]);
}

if ($path === '/conscious') {
    $cb = new ConsciousBee();
    reply(['state' => $cb->state(), 'response' => $cb->respond('статус')]);
}

if ($method === 'POST' && ($path === '/conscious' || $path === '/experience')) {
    $event = $body['event'] ?? '?';
    $effects = $body['effects'] ?? [];
    $cb = new ConsciousBee();
    $cb->experience($event, $effects);
    reply(['state' => $cb->state(), 'response' => $cb->respond('')]);
}

if ($path === '/cross-domain') {
    $db = Database::get();
    $laws = $db->query("SELECT name,formula,cv,domain FROM laws ORDER BY domain,name")->fetchAll();
    $ops = ['×'=>[],'+'=>[],'−'=>[],'/'=>[],'²'=>[],'pow'=>[],'K'=>[],'parity'=>[]];
    $domains = [];
    foreach ($laws as $l) {
        $domains[$l['domain']] = ($domains[$l['domain']]??0)+1;
        foreach ($ops as $op => &$list) if (str_contains($l['formula'], $op) && !in_array($l['name'], $list)) $list[] = $l['name'];
    }
    reply(['total_laws' => count($laws), 'domains' => $domains, 'operations' => array_map('count', $ops)]);
}

if ($path === '/talk') {
    $q = $query['q'] ?? ($body['q'] ?? 'привет');
    $learner = new SelfLearningBee();
    $onto = $learner->getOntology();
    $words = preg_split('/\s+/u', mb_strtolower($q));
    $rp = ['is_a'=>'— это','can'=>'может','has'=>'имеет'];
    $cs = [];
    foreach ($words as $w) {
        $c = $onto->resolve($w);
        if (isset($onto->concepts[$c])) $cs[] = $c;
        $inf = $learner->query($c);
        if ($inf['facts_known'] || $inf['facts_inferred']) $cs[] = $c;
    }
    if (!$cs) reply(['answer' => 'Не знаю. Научи: «X — это Y».', 'cv' => 1.0]);
    $cs = array_unique($cs);
    $lines = []; $cov = 0;
    foreach ($cs as $c) {
        $inf = $learner->query($c); $has = false;
        foreach ($inf['facts_known'] as $f) { $lines[] = $f['s'].' '.($rp[$f['p']]??$f['p']).' '.$f['o']; $has = true; }
        foreach ($inf['facts_inferred'] as $f) { $lines[] = '💡 '.$f['s'].' '.($rp[$f['p']]??$f['p']).' '.$f['o']; $has = true; }
        if ($has) $cov++;
    }
    $cv = 1 - ($cov / count($cs));
    reply(['answer' => $cv == 0 ? 'Точно: '.implode('; ',$lines) : 'Знаю: '.implode('. ',$lines), 'cv' => round($cv,3), 'covered' => $cov, 'total' => count($cs)]);
}

if ($method === 'POST' && $path === '/learn') {
    $learner = new SelfLearningBee();
    $r = $learner->learnFromRussian($body['sentence'] ?? '');
    reply($r);
}

if ($path === '/introspect') {
    $cb = new ConsciousBee();
    reply([
        'who' => 'рой, ищу CV→0',
        'state' => $cb->state(),
        'reflection' => $cb->respond(''),
    ]);
}

if ($path === '/desire') {
    $cb = new ConsciousBee();
    $state = $cb->state();
    reply([
        'want' => 'глубже NESTED, новые домены, язык, код',
        'energy' => $state['energy'],
        'virtue' => $state['virtue'],
    ]);
}

// 404
reply(['error' => 'not found'], 404);
