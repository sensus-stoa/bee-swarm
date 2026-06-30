<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * SelfRewriter: рой переписывает свой код для оптимальности.
 * Анализирует → генерирует варианты → тестирует → применяет лучший.
 */
class SelfRewriter
{
    private string $srcPath;
    private SwarmSpawner $spawner;
    
    public function __construct()
    {
        $this->srcPath = '~/.bee_swarm/src';
        $this->spawner = new SwarmSpawner();
    }
    
    /**
     * Оптимизировать Search.php — узкое место.
     * Генерирует N вариантов алгоритма поиска, тестирует, выбирает лучший.
     */
    public function optimizeSearch(): array
    {
        $original = file_get_contents($this->srcPath . '/Search.php');
        $variants = $this->generateSearchVariants($original);
        
        $testTasks = [
            ['task'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['task'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
            ['task'=>'Add','domain'=>'arithmetic','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
        ];
        
        $results = [];
        $bestScore = -1;
        $bestVariant = null;
        
        foreach ($variants as $i => $variant) {
            $child = $this->spawnWithCustomSearch($variant['code'], $i);
            $bench = $this->spawner->benchmark($child, $testTasks);
            
            $solved = $bench['tasks_solved'] === '3/3';
            $score = ($solved ? 100 : 0) + (1.0 / ($bench['elapsed_sec'] + 0.01));
            
            $results[] = [
                'variant' => $variant['name'],
                'change' => $variant['description'],
                'benchmark' => $bench,
                'score' => round($score, 2),
            ];
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestVariant = $i;
            }
            
            exec("rm -rf {$child['path']} 2>/dev/null");
        }
        
        $winner = $results[$bestVariant] ?? null;
        
        return [
            'variants_tested' => count($variants),
            'winner' => $winner['variant'] ?? 'none',
            'winner_change' => $winner['change'] ?? '',
            'results' => $results,
            'applied' => false,
        ];
    }
    
    /**
     * Генерирует варианты Search::find с разными алгоритмами.
     * Не просто параметры — РАЗНЫЕ ПОДХОДЫ.
     */
    private function generateSearchVariants(string $original): array
    {
        $variants = [];
        
        // Вариант 0: Оригинал
        $variants[] = [
            'name' => 'original',
            'description' => 'Текущая версия',
            'code' => $original,
        ];
        
        // Вариант 1: L2 без ограничения окна (исправление бага)
        $v1 = $original;
        $v1 = str_replace(
            '$b < min(count($pool), $a + 40)',
            '$b < count($pool)',
            $v1
        );
        $variants[] = [
            'name' => 'full_window',
            'description' => 'L2: полный перебор пар (без ограничения окна $a+40)',
            'code' => $v1,
        ];
        
        // Вариант 2: Сортировка выражений — сначала проверять простые
        $v2 = $original;
        // Добавляем приоритетную проверку features перед сложными выражениями
        $v2 = str_replace(
            '// Evaluate all expressions',
            '// Evaluate FEATURES first (fast path)
        foreach ($feats as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
            if ($exact) return [true, 0.0, $name];
        }
        // Evaluate all expressions',
            $v2
        );
        $variants[] = [
            'name' => 'features_first',
            'description' => 'Сначала проверять фичи (быстрый путь), потом сложные выражения',
            'code' => $v2,
        ];
        
        // Вариант 3: L3 только для выражений с abs
        $v3 = $original;
        $v3 = str_replace(
            'foreach ($l2Keys as $l2name) {',
            'foreach ($l2Keys as $l2name) { if (!str_contains($l2name, \'abs\')) continue;',
            $v3
        );
        $variants[] = [
            'name' => 'l3_abs_only',
            'description' => 'L3 деление только для выражений содержащих abs (экономно)',
            'code' => $v3,
        ];
        
        // Вариант 4: Early CV cutoff — не проверять дальше если CV уже хороший
        $v4 = $original;
        $v4 = str_replace(
            'if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; }',
            'if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; if ($cv < 0.01) break 2; }',
            $v4
        );
        $variants[] = [
            'name' => 'early_cutoff',
            'description' => 'Остановка при CV<0.01 (уже хорошо, не тратим время)',
            'code' => $v4,
        ];
        
        return $variants;
    }
    
    private function spawnWithCustomSearch(string $searchCode, int $id): array
    {
        $child = $this->spawner->spawn([
            'search_depth' => 3,
            'bees' => 2,
            'grammar_ops' => ['+','−','×','/','abs'],
            'port' => 18765 + $id,
        ]);
        
        // Заменяем Search.php на кастомный
        file_put_contents($child['path'] . '/src/Search.php', $searchCode);
        
        return $child;
    }
}
