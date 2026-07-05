<?php
declare(strict_types=1);
namespace BeeSwarm\Hive;

use BeeSwarm\Bee\CellBee;

/**
 * DensityHive: density-based routing. Никаких меток доменов.
 * Задача приходит → все пчёлы пробуют → выигрывает лучшая CV.
 * Грамматика ОПРЕДЕЛЯЕТ пригодность, не метка.
 */
class DensityHive
{
    private array $bees = [];
    private int $gen = 0;
    
    public function __construct(int $initialBees = 5)
    {
        // Старт: пчёлы с РАЗНОЙ грамматикой (не доменами!)
        $grammars = [
            ['+'],
            ['+', '−'],
            ['+', '×'],
            ['−', '/'],
            ['+', '−', '×'],
            ['is_a', 'can'],
            ['virtue_of'],
            ['+', 'sqrt'],
        ];
        for ($i = 0; $i < $initialBees; $i++) {
            $bee = new CellBee('any');
            $bee->grammar = new RelationGrammar('any');
            $bee->grammar->setRelations($grammars[$i % count($grammars)]);
            $this->bees[] = $bee;
        }
    }
    
    /**
     * Один тик: случайная задача → density-based routing → лучшая пчела.
     */
    public function tick(): array
    {
        $this->gen++;
        $tasks = $this->allTasks();
        if (empty($tasks)) return ['status' => 'no_tasks', 'gen' => $this->gen];
        
        $task = $tasks[array_rand($tasks)];
        $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
        $y = array_map(fn($r) => end($r), $task['data']);
        
        // DENSITY-BASED ROUTING: каждая пчела пробует
        $best = null; $bestCv = 9.99; $allResults = [];
        foreach ($this->bees as $bee) {
            if (!$bee->isReady()) continue;
            $r = $bee->search($X, $y);
            $cv = $r[1]; $allResults[] = ['bee' => $bee->id, 'cv' => $cv, 'domain' => $bee->domain];
            if ($cv < $bestCv) { $bestCv = $cv; $best = $bee; }
        }
        
        if (!$best) return ['status' => 'all_tired', 'gen' => $this->gen];
        
        // Пчела-победитель живёт цикл
        $result = $best->live($X, $y);
        
        if ($best->isDead()) {
            $this->bees = array_filter($this->bees, fn($b) => $b !== $best);
            $result['event'] = 'died';
        }
        
        $child = $best->divide();
        if ($child) {
            $this->bees[] = $child;
            $result['event'] = 'divided';
        }
        
        return [
            'generation' => $this->gen,
            'bees_count' => count($this->bees),
            'task' => $task['name'],
            'router' => 'density-based',
            'all_cv' => $allResults,
            'winner' => $result,
        ];
    }
    
    private function allTasks(): array
    {
        return [
            ['name'=>'AND','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['name'=>'Add','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
            ['name'=>'Mul','data'=>[[1,2,2],[2,3,6],[3,4,12],[5,6,30]]],
            ['name'=>'Sqrt','data'=>[[0,0],[1,1],[4,2],[9,3],[16,4]]],
            ['name'=>'MIN','data'=>[[0,0,0],[2,3,2],[5,1,1],[4,4,4]]],
            ['name'=>'OR','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
            ['name'=>'Max','data'=>[[0,0,0],[2,3,3],[5,1,5],[4,4,4]]],
            ['name'=>'Div','data'=>[[6,2,3],[12,3,4],[20,4,5],[10,2,5]]],
        ];
    }
    
    public function state(): array
    {
        return [
            'generation' => $this->gen,
            'bees' => count($this->bees),
            'population' => array_map(fn($b) => [
                'id' => $b->id, 'energy' => round($b->energy,2),
                'grammar' => $b->grammar->all(),
                'successes' => $b->successes,
            ], $this->bees),
        ];
    }
}
