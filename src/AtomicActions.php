<?php
declare(strict_types=1);
namespace BeeSwarm;

use BeeSwarm\Core\Search;

use BeeSwarm\Core\Grammar;

/**
 * Инструкция (inspired by @ctx/self-replace + atomic-actions)
 *
 * Рой не пишет "если DQ упал → скажи дышать".
 * Рой КОМПОЗИРУЕТ атомы.
 * Новые композиции находит через CV→0 на своём логе.
 */

/** Чистый in-memory Grammar, не трогающий БД */
function mkGrammar(array $relations): Grammar {
    $g = new Grammar();
    $refl = new \ReflectionClass($g);
    $prop = $refl->getProperty('ops');
    $prop->setAccessible(true);
    $ops = [];
    foreach ($relations as $r) $ops[$r] = ['fn'=>'custom_'.$r,'symbol'=>$r];
    $prop->setValue($g, $ops);
    return $g;
}

class AtomicActions {
    private string $logPath;

    public function __construct() {
        $this->logPath = '/tmp/roe_action_log.jsonl';
    }

    /** LOG — записать finding */
    public function LOG(string $event, array $data): void {
        $line = json_encode([
            'ts' => date('c'), 'event' => $event, 'data' => $data
        ]) . "\n";
        file_put_contents($this->logPath, $line, FILE_APPEND);
    }

    /** ALERT — HTTP-сигнал */
    public function ALERT(string $msg): bool {
        $webhook = getenv('ROE_WEBHOOK');
        if (!$webhook) { $this->LOG('alert_muted', ['msg'=>$msg]); return false; }
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['text'=>$msg,'ts'=>date('c')]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 3,
        ]);
        curl_exec($ch); curl_close($ch);
        return true;
    }

    /** SPAWN — дочерний процесс */
    public function SPAWN(string $label, array $grammarOps, array $testData): ?array {
        $spawner = new SwarmSpawner();
        $child = $spawner->spawn([
            'search_depth' => 3, 'bees' => 2,
            'grammar_ops' => $grammarOps,
            'port' => rand(18900, 19900),
        ]);

        // заменяем Search.php на оптимизированный
        $searchSrc = file_get_contents('~/.bee_swarm/src/Search.php');
        // features-first уже там (применён ранее)
        file_put_contents($child['path'].'/src/Search.php', $searchSrc);

        $bench = $spawner->benchmark($child, $testData);
        exec("rm -rf {$child['path']} 2>/dev/null");

        $solved = $bench['tasks_solved'] === '3/3';
        return [
            'label' => $label, 'solved' => $solved,
            'elapsed' => $bench['elapsed_sec'], 'mem_kb' => $bench['mem_delta_kb']
        ];
    }

    /** ADJUST — изменить параметр поиска */
    public function ADJUST(string $param, $value): bool {
        $allowed = ['search_depth'=>[1,5], 'bees'=>[1,16], 'pool_slice'=>[10,200]];
        if (!isset($allowed[$param])) return false;
        [$min,$max] = $allowed[$param];
        if ($value < $min || $value > $max) return false;

        $this->LOG('adjust', ['param'=>$param,'value'=>$value,'old'=>null]);
        // Применяется через /concept endpoint в реальном рантайме
        return true;
    }

    /** REQUEST — запросить данные */
    public function REQUEST(string $domain): array {
        $gen = new DataSelfGenerator();
        $tasks = $gen->fromMetrics();
        foreach ($gen->fromLaws() as $t) if ($t['domain']===$domain) $tasks[] = $t;
        return array_slice($tasks, 0, 5);
    }
}
