<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * EcoHive: экосистема из двух роёв с общей грамматикой.
 * Рой A (арифметика) и Рой B (язык/этика).
 * Грамматика общая — открытие одного доступно другому.
 * Роутер распределяет задачи между роями.
 */
class EcoHive
{
    private PersistentHive $hiveA;
    private PersistentHive $hiveB;
    
    public function __construct()
    {
        $this->hiveA = new PersistentHive('hive_a');
        $this->hiveB = new PersistentHive('hive_b');
    }
    
    /**
     * Тик для конкретного роя.
     */
    public function tick(string $which): array
    {
        $hive = $which === 'a' ? $this->hiveA : $this->hiveB;
        $domainFilter = $which === 'a' ? ['arithmetic','physics','logic'] : ['language','ethics','philosophy'];
        
        $tasks = $this->getTasksFor($domainFilter);
        if (empty($tasks)) return ['status' => 'no_tasks', 'hive' => $which];
        
        $task = $tasks[array_rand($tasks)];
        $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
        $y = array_map(fn($r) => end($r), $task['data']);
        
        $best = null; $bestCv = 9.99;
        foreach ($hive->bees() as $bee) {
            if (!$bee->isReady()) continue;
            $result = $bee->search($X, $y);
            if ($result[1] < $bestCv) { $bestCv = $result[1]; $best = $bee; }
        }
        
        if (!$best) return ['status' => 'all_tired', 'hive' => $which];
        
        $result = $best->live($X, $y);
        
        if ($best->isDead()) $hive->removeBee($best);
        
        $child = $best->divide();
        if ($child) {
            $hive->addBee($child);
            $result['event'] = 'divided';
        }
        
        $hive->bumpGeneration();
        $hive->save();
        
        return [
            'hive' => $which,
            'generation' => $hive->gen(),
            'bees_count' => $hive->beeCount(),
            'task_domain' => $task['name'],
            'result' => $result,
            'shared_grammar' => $this->getSharedGrammar(),
        ];
    }
    
    /**
     * Роутер: какая задача → какой рой.
     */
    public function route(array $task): string
    {
        $name = $task['name'] ?? '';
        // Арифметические/физические → рой A
        if (preg_match('/AND|OR|XOR|Add|Mul|Kepler|Ohm|Newton/', $name)) return 'a';
        // Языковые/этические → рой B
        if (preg_match('/is_a|can|has|virtue|ethic|Сократ|человек/', $name)) return 'b';
        // По умолчанию — пробуем оба
        return 'a'; // можно расширить: сначала A, если CV>0.5 → B
    }
    
    /**
     * Коалиция: оба роя пробуют задачу → лучший результат.
     */
    public function coalition(array $X, array $y): array
    {
        $best = null; $bestCv = 9.99; $bestHive = '';
        
        foreach (['a' => $this->hiveA, 'b' => $this->hiveB] as $name => $hive) {
            foreach ($hive->bees() as $bee) {
                if (!$bee->isReady()) continue;
                $result = $bee->search($X, $y);
                if ($result[1] < $bestCv) { $bestCv = $result[1]; $best = $result; $bestHive = $name; }
            }
        }
        
        // Если ни один рой не дал CV→0 → coalition
        $coalition = null;
        if ($bestCv > 0.1) {
            // Объединённый поиск с общей грамматикой
            $g = new Grammar();
            $coalition = Search::find($X, $y, $g, 3);
        }
        
        return [
            'best_hive' => $bestHive,
            'best_cv' => $bestCv,
            'coalition_cv' => $coalition ? $coalition[1] : null,
            'coalition_needed' => $bestCv > 0.1,
        ];
    }
    
    private function getTasksFor(array $domains): array
    {
        $all = [
            ['name'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['name'=>'Add','domain'=>'arithmetic','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
            ['name'=>'Mul','domain'=>'arithmetic','data'=>[[1,2,2],[2,3,6],[3,4,12],[5,6,30]]],
            ['name'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
            ['name'=>'Socrates','domain'=>'ethics','data'=>[[0,1],[1,1],[2,0.2],[3,0.25]]],
            ['name'=>'Virtue','domain'=>'ethics','data'=>[[0,0.2],[1,0.15],[2,0.1],[3,0.0]]],
        ];
        return array_values(array_filter($all, fn($t) => in_array($t['domain'], $domains)));
    }
    
    private function getSharedGrammar(): array
    {
        $g = new Grammar();
        return $g->all();
    }
    
    public function state(): array
    {
        return [
            'hive_a' => ['gen' => $this->hiveA->gen(), 'bees' => $this->hiveA->beeCount()],
            'hive_b' => ['gen' => $this->hiveB->gen(), 'bees' => $this->hiveB->beeCount()],
            'shared_grammar' => $this->getSharedGrammar(),
        ];
    }
}
