<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * ArchitectProxy: я (LLM) говорю ЧТО изменить — рой проверяет и применяет.
 * Я не пишу код. Я даю спецификацию. Рой spawn → test → apply/reject.
 */
class ArchitectProxy
{
    private SwarmSpawner $spawner;
    
    public function __construct()
    {
        $this->spawner = new SwarmSpawner();
    }
    
    /**
     * Применить изменение к файлу через spawn → benchmark → apply/reject.
     * 
     * @param string $file       файл для изменения (Search.php, Grammar.php...)
     * @param string $old        что заменить
     * @param string $new        на что заменить
     * @param string $description что делаем
     */
    public function propose(string $file, string $old, string $new, string $description): array
    {
        $testTasks = [
            ['task'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['task'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
            ['task'=>'Add','domain'=>'arith','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
        ];
        
        // 1. Тест родителя (без изменений)
        $parent = $this->spawner->spawn([
            'search_depth'=>3, 'bees'=>2, 'grammar_ops'=>['+','−','×','/','abs'],
            'port'=>28800
        ]);
        $parentBench = $this->spawner->benchmark($parent, $testTasks);
        $parentScore = $this->score($parentBench);
        exec("rm -rf {$parent['path']} 2>/dev/null");
        
        // 2. Применяем изменение к копии
        $child = $this->spawner->spawn([
            'search_depth'=>3, 'bees'=>2, 'grammar_ops'=>['+','−','×','/','abs'],
            'port'=>28801
        ]);
        
        $targetFile = $child['path'] . '/' . $file;
        if (!file_exists($targetFile)) {
            exec("rm -rf {$child['path']} 2>/dev/null");
            return ['status' => 'rejected', 'reason' => "file not found: $file"];
        }
        
        $code = file_get_contents($targetFile);
        if (!str_contains($code, $old)) {
            exec("rm -rf {$child['path']} 2>/dev/null");
            return ['status' => 'rejected', 'reason' => 'old string not found in file'];
        }
        
        $newCode = str_replace($old, $new, $code);
        if ($newCode === $code) {
            exec("rm -rf {$child['path']} 2>/dev/null");
            return ['status' => 'rejected', 'reason' => 'no change applied'];
        }
        
        file_put_contents($targetFile, $newCode);
        
        // 3. Тест ребёнка
        $childBench = $this->spawner->benchmark($child, $testTasks);
        $childScore = $this->score($childBench);
        exec("rm -rf {$child['path']} 2>/dev/null");
        
        // 4. Решение
        if ($childScore > $parentScore) {
            // Применяем к реальному файлу
            $realFile = '~/.bee_swarm/' . $file;
            $realCode = file_get_contents($realFile);
            $realNew = str_replace($old, $new, $realCode);
            file_put_contents($realFile, $realNew);
            
            return [
                'status' => 'applied',
                'file' => $file,
                'description' => $description,
                'parent_score' => round($parentScore, 2),
                'child_score' => round($childScore, 2),
                'parent_tasks' => $parentBench['tasks_solved'],
                'child_tasks' => $childBench['tasks_solved'],
            ];
        }
        
        return [
            'status' => 'rejected',
            'file' => $file,
            'description' => $description,
            'parent_score' => round($parentScore, 2),
            'child_score' => round($childScore, 2),
            'reason' => "child score ($childScore) <= parent ($parentScore)",
        ];
    }
    
    private function score(array $bench): float
    {
        $parts = explode('/', $bench['tasks_solved']);
        $solved = (int)($parts[0] ?? 0);
        $total = (int)($parts[1] ?? 1);
        $accuracyScore = $total > 0 ? ($solved / $total) * 100 : 0;
        return $accuracyScore + (1.0 / ($bench['elapsed_sec'] + 0.01));
    }
}
