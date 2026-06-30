<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * Hive: популяция пчёл-клеток.
 * Среда = поток задач из БД.
 * Пчёлы живут, мутируют, умирают, делятся.
 * Эволюция без внешнего управления.
 */
class Hive
{
    private array $bees = [];
    private int $generation = 0;
    private array $log = [];
    
    public function __construct()
    {
        // Стартовая популяция: 3 пчелы в разных доменах
        $this->bees[] = new CellBee('arithmetic');
        $this->bees[] = new CellBee('language');
        $this->bees[] = new CellBee('ethics');
    }
    
    /**
     * Один тик: взять случайную задачу, дать пчеле, обновить.
     */
    public function tick(): array
    {
        $this->generation++;
        
        $tasks = $this->getTasks();
        if (empty($tasks)) {
            return ['status' => 'no_tasks', 'bees' => count($this->bees)];
        }
        
        $task = $tasks[array_rand($tasks)];
        $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
        $y = array_map(fn($r) => end($r), $task['data']);
        
        // Выбрать пчелу по роутеру: лучший CV на этой задаче
        $best = null;
        $bestCv = 9.99;
        foreach ($this->bees as $bee) {
            if (!$bee->isReady()) continue;
            $result = $bee->search($X, $y);
            if ($result[1] < $bestCv) {
                $bestCv = $result[1];
                $best = $bee;
            }
        }
        
        if (!$best) {
            // Все устали — ждём
            return ['status' => 'all_tired', 'bees' => count($this->bees)];
        }
        
        // Пчела живёт один цикл
        $result = $best->live($X, $y);
        
        // Смерть
        if ($best->isDead()) {
            $this->bees = array_filter($this->bees, fn($b) => $b !== $best);
            $result['event'] = 'died';
        }
        
        // Деление
        $child = $best->divide();
        if ($child) {
            $this->bees[] = $child;
            $result['event'] = 'divided';
            $result['child'] = $child->id;
        }
        
        $this->log[] = $result;
        if (count($this->log) > 100) array_shift($this->log);
        
        return [
            'generation' => $this->generation,
            'bees_count' => count($this->bees),
            'alive' => array_map(fn($b) => [
                'id' => $b->id,
                'domain' => $b->domain,
                'energy' => round($b->energy, 2),
                'grammar' => $b->grammar->all(),
                'successes' => $b->successes,
            ], $this->bees),
            'latest' => $result,
            'log_size' => count($this->log),
        ];
    }
    
    /**
     * Поток задач из БД + встроенные.
     */
    private function getTasks(): array
    {
        $db = Database::get();
        $saved = $db->query("SELECT name, data_json FROM tasks ORDER BY RANDOM() LIMIT 5")->fetchAll();
        if (!empty($saved)) {
            return array_map(fn($r) => [
                'name' => $r['name'],
                'data' => json_decode($r['data_json'], true)
            ], $saved);
        }
        
        // Встроенные задачи если БД пуста
        return [
            ['name'=>'AND','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['name'=>'Add','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
            ['name'=>'OR','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
        ];
    }
    
    public function bees(): array { return $this->bees; }
    public function gen(): int { return $this->generation; }
    public function getLog(): array { return $this->log; }
}
