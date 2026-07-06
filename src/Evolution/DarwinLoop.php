<?php
declare(strict_types=1);
namespace BeeSwarm\volution;

use BeeSwarm\Infra\Database;

use BeeSwarm\Core\Search;

use BeeSwarm\Core\Grammar;

/**
 * DarwinLoop: биологическая эволюция роя.
 * Не self-modify. Spawn → mutate → compete → replace parent.
 */
class DarwinLoop
{
    private SwarmSpawner $spawner;
    private string $parentPath;
    
    public function __construct()
    {
        $this->spawner = new SwarmSpawner();
        $this->parentPath = '~/.bee_swarm';
    }
    
    /**
     * Одно поколение: spawn с мутированной РНК → тест → если лучше → РНК-победитель.
     * ДНК (php-файлы) не трогаем. Меняем grammar_ops в БД — это РНК.
     */
    public function generation(): array
    {
        $testTasks = [
            ['task'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['task'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
            ['task'=>'Add','domain'=>'arith','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
            ['task'=>'Sqrt','domain'=>'math','data'=>[[0,0],[1,1],[4,2],[9,3],[16,4]]],
        ];
        
        // Текущая РНК родителя (grammar из БД)
        $parentG = new Grammar();
        $parentOps = $parentG->all();

        // Мутации РНК: разные наборы grammar_ops
        $rnaMutations = [
            ['name' => 'base',     'ops' => ['+','−','×','/']],
            ['name' => '+abs',     'ops' => ['+','−','×','/','abs']],
            ['name' => '+pow2',    'ops' => ['+','−','×','/','pow2']],
            ['name' => '+abs+pow2','ops' => ['+','−','×','/','abs','pow2']],
            ['name' => '+sqrt',    'ops' => ['+','−','×','/','abs','sqrt']],
        ];
        
        $bestScore = -1;
        $bestRNA = null;
        $results = [];
        
        foreach ($rnaMutations as $i => $rna) {
            $child = $this->spawner->spawn([
                'search_depth' => 3, 'bees' => 2,
                'grammar_ops' => $rna['ops'],
                'port' => 28701 + $i,
            ]);
            
            $bench = $this->spawner->benchmark($child, $testTasks);
            $score = $this->score($bench);
            exec("rm -rf {$child['path']} 2>/dev/null");
            
            $results[] = ['rna' => $rna['name'], 'score' => round($score, 2), 'tasks' => $bench['tasks_solved']];
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRNA = $rna;
            }
        }
        
        // Если лучшая РНК отличается от текущей → ОБНОВЛЯЕМ
        $changed = false;
        $currentSet = array_values(array_intersect($parentOps, ['+','−','×','/']));
        $bestSet = $bestRNA ? $bestRNA['ops'] : $currentSet;
        
        if ($bestRNA && ($bestSet != $currentSet || count($bestSet) != count($parentOps))) {
            $db = Database::get();
            $db->exec("DELETE FROM grammar_ops");
            $stmt = $db->prepare("INSERT INTO grammar_ops (name, source) VALUES (?, 'evolved')");
            foreach ($bestSet as $op) $stmt->execute([$op]);
            $changed = true;
        }
        
        return [
            'level' => 'RNA',
            'parent_rna' => implode(',', $parentOps),
            'best_rna' => $bestRNA ? $bestRNA['name'] : '?',
            'best_ops' => $bestRNA ? $bestRNA['ops'] : [],
            'rna_changed' => $changed,
            'results' => $results,
        ];
    }
    
    private function score(array $bench): float
    {
        $solved = $bench['tasks_solved'] === '4/4' ? 100 : 
                  ($bench['tasks_solved'] === '3/4' ? 75 : 
                   ($bench['tasks_solved'] === '2/4' ? 50 : 0));
        return $solved + (1.0 / ($bench['elapsed_sec'] + 0.01));
    }
    
    /**
     * Мутации — случайные изменения кода.
     * НЕ хардкод стратегий. Случайные правки.
     */
    private function generateMutations(): array
    {
        $mutations = [];
        
        // Мутация 1: вынести $va за цикл (loop hoisting)
        $mutations[] = [
            'name' => 'loop_hoist',
            'apply' => function(string $code): string {
                $pattern = '/(for\s*\(\s*\$a\s*=.+?\$pool.+?\)\s*\{)\s*(for\s*\(\s*\$b\s*=.+?\$pool.+?\)\s*\{)\s*(\$va\s*=\s*\$exprs\[\s*\$pool\[\s*\$a\s*\]\s*\];)/s';
                if (preg_match($pattern, $code, $m)) {
                    return str_replace($m[0], $m[1] . "\n                " . $m[3] . "  // hoisted\n                " . $m[2], $code);
                }
                return $code;
            },
        ];
        
        // Мутация 2: features first (проверка фич до выражений)
        $mutations[] = [
            'name' => 'features_first',
            'apply' => function(string $code): string {
                if (str_contains($code, 'Evaluate FEATURES first')) return $code;
                return str_replace(
                    '// Evaluate all expressions',
                    '// Evaluate FEATURES first (fast path)
        foreach ($feats as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
            if ($exact) return [true, 0.0, $name];
        }
        // Evaluate all expressions',
                    $code
                );
            },
        ];
        
        // Мутация 3: early CV cutoff (остановка при хорошем CV)
        $mutations[] = [
            'name' => 'early_cutoff',
            'apply' => function(string $code): string {
                if (str_contains($code, 'break 2')) return $code;
                return str_replace(
                    'if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; }',
                    'if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; if ($cv < 0.001) break; }',
                    $code
                );
            },
        ];
        
        // Мутация 4: уменьшить размер слайсов (быстрее, меньше памяти)
        $mutations[] = [
            'name' => 'smaller_slices',
            'apply' => function(string $code): string|null {
                $code = preg_replace('/array_slice\(\$l1Keys,\s*0,\s*\)\d+/', 'array_slice($l1Keys, 0, 30)', $code);
                $code = preg_replace('/array_slice\(\$l1Unary,\s*0,\s*\)\d+/', 'array_slice($l1Unary, 0, 20)', $code);
                return $code;
            },
        ];
        
        return $mutations;
    }
}
