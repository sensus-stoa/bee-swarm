<?php
declare(strict_types=1);
namespace BeeSwarm\Bee;

/**
 * Spawner: рой порождает новый рой.
 * Копирует себя → изменяет параметры → тестирует → оставляет лучшего.
 */
class SwarmSpawner
{
    private string $basePath;
    
    public function __construct()
    {
        $this->basePath = '~/.bee_swarm';
    }
    
    /**
     * Создать дочерний рой с другими параметрами.
     */
    public function spawn(array $config): array
    {
        $id = 'swarm_' . date('Ymd_His') . '_' . rand(100, 999);
        $childPath = "/tmp/$id";
        
        // Копируем код
        $this->copyDir($this->basePath . '/src', "$childPath/src");
        $this->copyDir($this->basePath . '/public', "$childPath/public");
        copy($this->basePath . '/composer.json', "$childPath/composer.json");
        copy($this->basePath . '/.rr.yaml', "$childPath/.rr.yaml");
        
        // Копируем vendor
        $this->copyDir($this->basePath . '/vendor', "$childPath/vendor");
        
        // Инициализируем чистую БД
        mkdir("$childPath/data", 0755, true);
        copy($this->basePath . '/data/swarm.db', "$childPath/data/swarm.db");
        
        // Пропатчиваем Search.php — глубина поиска
        $searchFile = "$childPath/src/Search.php";
        if (file_exists($searchFile)) {
            $code = file_get_contents($searchFile);
            $code = str_replace('int $depth = 2', 'int $depth = ' . ($config['search_depth'] ?? 3), $code);
            file_put_contents($searchFile, $code);
        }
        
        // Пропатчиваем worker.php — порт И число пчёл
        $workerFile = "$childPath/src/worker.php";
        if (file_exists($workerFile)) {
            $code = file_get_contents($workerFile);
            $code = str_replace("port: 8765", "port: " . ($config['port'] ?? 18765), $code);
            // Меняем число параллельных пчёл
            $code = preg_replace('/\$nBees = \d+/', '$nBees = ' . ($config['bees'] ?? 4), $code);
            file_put_contents($workerFile, $code);
        }
        
        // Сидируем грамматику в БД
        $dbFile = "$childPath/data/swarm.db";
        if (file_exists($dbFile) && isset($config['grammar_ops'])) {
            $db = new \PDO("sqlite:$dbFile");
            $db->exec("DELETE FROM grammar_ops");
            $stmt = $db->prepare("INSERT INTO grammar_ops (name, source) VALUES (?, 'evolved')");
            foreach ($config['grammar_ops'] as $op) {
                $stmt->execute([$op]);
            }
        }
        
        return [
            'id' => $id,
            'path' => $childPath,
            'config' => $config,
            'port' => $config['port'] ?? 18765,
        ];
    }
    
    /**
     * Запустить дочерний рой и замерить производительность.
     */
    public function benchmark(array $child, array $testTasks): array
    {
        $port = $child['port'];
        $path = $child['path'];
        
        // Запускаем дочерний рой
        $cmd = "cd $path && php -S 127.0.0.1:$port -t public/ > /dev/null 2>&1 & echo $!";
        $pid = (int)shell_exec($cmd);
        usleep(800000); // ждём 0.8с
        
        if (!$pid) {
            return ['status' => 'failed', 'reason' => 'could not start process'];
        }
        
        // Ждём готовности (до 3 секунд)
        $ready = false;
        for ($t = 0; $t < 30; $t++) {
            if ($this->isAlive($port)) { $ready = true; break; }
            usleep(100000);
        }
        
        if (!$ready) {
            exec("kill $pid 2>/dev/null");
            return ['status' => 'failed', 'reason' => "child not ready on port $port after 3s"];
        }
        
        // Замеряем память до тестов
        $memBefore = $this->getMemory($pid);
        $startTime = microtime(true);
        $results = [];
        
        // Прогоняем тестовые задачи
        foreach ($testTasks as $task) {
            $json = json_encode($task);
            $curlCmd = "curl -s --max-time 5 -X POST http://127.0.0.1:$port/solve -H 'Content-Type: application/json' -d '$json' 2>/dev/null";
            $output = shell_exec($curlCmd);
            $result = json_decode($output ?: '{}', true) ?: [];
            $results[] = [
                'task' => $task['task'] ?? '?',
                'solved' => $result['solved'] ?? false,
                'cv' => $result['best_cv'] ?? 9.99,
            ];
        }
        
        $elapsed = round(microtime(true) - $startTime, 4);
        $memAfter = $this->getMemory($pid);
        $memDelta = $memAfter - $memBefore;
        
        // Убиваем дочерний процесс
        exec("kill $pid 2>/dev/null");
        
        $solved = count(array_filter($results, fn($r) => $r['solved']));
        
        return [
            'status' => 'ok',
            'pid' => $pid,
            'elapsed_sec' => $elapsed,
            'mem_start_kb' => $memBefore,
            'mem_end_kb' => $memAfter,
            'mem_delta_kb' => $memDelta,
            'tasks_solved' => "$solved/" . count($testTasks),
            'results' => $results,
        ];
    }
    
    /**
     * Эволюция: породить N вариантов → протестировать → оставить лучшего.
     */
    public function evolve(int $generations, array $testTasks): array
    {
        $history = [];
        $best = null;
        $bestScore = -1;
        
        $configs = [
            // Варьируем ВСЁ: глубина, грамматика, число пчёл, стратегия
            ['search_depth' => 2, 'bees' => 4, 'grammar_ops' => ['+','−','×','/'],           'label' => 'fast_4bees'],
            ['search_depth' => 3, 'bees' => 4, 'grammar_ops' => ['+','−','×','/','abs'],      'label' => 'deep_4bees_abs'],
            ['search_depth' => 3, 'bees' => 8, 'grammar_ops' => ['+','−','×','/','abs','pow2'], 'label' => 'deep_8bees_full'],
            ['search_depth' => 2, 'bees' => 2, 'grammar_ops' => ['+','−','×','/'],           'label' => 'minimal_2bees'],
            ['search_depth' => 4, 'bees' => 4, 'grammar_ops' => ['+','−','×','/','abs','²'],  'label' => 'deep4_sq'],
        ];
        
        foreach ($configs as $i => $cfg) {
            $cfg['port'] = 18765 + $i;
            
            $child = $this->spawn($cfg);
            $bench = $this->benchmark($child, $testTasks);
            
            $score = ($bench['tasks_solved'] === '3/3' ? 100 : 0) 
                   + (1.0 / ($bench['elapsed_sec'] + 0.01));
            
            $entry = [
                'generation' => $i + 1,
                'config' => $cfg,
                'benchmark' => $bench,
                'score' => round($score, 2),
            ];
            $history[] = $entry;
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
            
            // Чистим
            exec("rm -rf {$child['path']} 2>/dev/null");
        }
        
        return [
            'generations' => count($history),
            'best' => $best,
            'history' => $history,
            'verdict' => $best 
                ? "Лучший: {$best['config']['label']} — {$best['benchmark']['tasks_solved']} задач за {$best['benchmark']['elapsed_sec']}с"
                : 'Нет успешных вариантов',
        ];
    }
    
    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($src)) return;
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            $sf = "$src/$f"; $df = "$dst/$f";
            if (is_dir($sf)) $this->copyDir($sf, $df);
            else copy($sf, $df);
        }
    }
    
    private function isAlive(int $port): bool
    {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($fp) { fclose($fp); return true; }
        return false;
    }
    
    private function getMemory(int $pid): int
    {
        $mem = shell_exec("ps -o rss= -p $pid 2>/dev/null");
        return (int)trim($mem ?: '0');
    }
}
