<?php
declare(strict_types=1);
namespace BeeSwarm\volution;

/**
 * AutonomousOptimizer: замкнутый цикл самоулучшения.
 * 1. Анализирует свой код (token_get_all)
 * 2. Находит узкие места (циклы, повторные вычисления)
 * 3. Генерирует микро-оптимизации (одно маленькое изменение)
 * 4. Спавнит → тестирует → если лучше → применяет к себе
 * 5. Повторяет
 */
class AutonomousOptimizer
{
    private SwarmSpawner $spawner;
    private string $srcPath;
    private array $history = [];
    
    public function __construct()
    {
        $this->spawner = new SwarmSpawner();
        $this->srcPath = '~/.bee_swarm/src';
    }
    
    /**
     * Один шаг эволюции: найти → улучшить → проверить → применить/откатить.
     */
    public function step(): array
    {
        // 1. Анализируем код — ищем что оптимизировать
        $opportunities = $this->analyzeCode();
        if (empty($opportunities)) {
            return ['status' => 'done', 'reason' => 'нет возможностей для оптимизации'];
        }
        
        // 2. Берём первую возможность — генерируем патч
        $opp = $opportunities[0];
        $patch = $this->generatePatch($opp);
        
        // 3. Тестируем на дочернем процессе
        $testResult = $this->testPatch($patch);
        
        // 4. Решаем: применить или откатить
        $entry = [
            'opportunity' => $opp['description'],
            'file' => $opp['file'],
            'patch_description' => $patch['description'],
            'test' => $testResult,
        ];
        
        if ($testResult['better'] ?? false) {
            // Применяем к себе
            $this->applyPatch($patch);
            $entry['applied'] = true;
        } else {
            $entry['applied'] = false;
        }
        
        $this->history[] = $entry;
        
        return [
            'status' => 'stepped',
            'opportunity' => $opp['description'],
            'patch' => $patch['description'],
            'applied' => $entry['applied'],
            'score_before' => $testResult['score_original'] ?? 0,
            'score_after' => $testResult['score_patched'] ?? 0,
            'history_count' => count($this->history),
        ];
    }
    
    /**
     * Анализирует PHP-код роя — ищет узкие места.
     * Использует token_get_all для структурного анализа.
     */
    private function analyzeCode(): array
    {
        $opportunities = [];
        
        // Анализируем Search.php — узкое место
        $searchFile = $this->srcPath . '/Search.php';
        if (!file_exists($searchFile)) return $opportunities;
        
        $code = file_get_contents($searchFile);
        $tokens = token_get_all($code);
        
        // Ищем: вложенные циклы (N² и выше)
        $loopDepth = 0;
        $maxDepth = 0;
        $nestedLoops = [];
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok)) continue;
            if ($tok[0] === T_FOR || $tok[0] === T_FOREACH) {
                $loopDepth++;
                if ($loopDepth > $maxDepth) $maxDepth = $loopDepth;
                if ($loopDepth >= 3) {
                    $nestedLoops[] = ['line' => $tok[2], 'depth' => $loopDepth];
                }
            }
            if ($tok[0] === T_ENDFOR || $tok[0] === T_ENDFOREACH) {
                $loopDepth = max(0, $loopDepth - 1);
            }
        }
        
        if ($maxDepth >= 3) {
            $opportunities[] = [
                'type' => 'nested_loops',
                'file' => 'Search.php',
                'description' => "Вложенные циклы глубиной $maxDepth — кандидат на оптимизацию",
                'detail' => $nestedLoops,
            ];
        }
        
        // Ищем: повторные вызовы array_column, array_keys, array_slice на одних данных
        $repeatedCalls = [];
        $funcCalls = [];
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok)) continue;
            if ($tok[0] === T_STRING && in_array($tok[1], ['array_column','array_keys','array_slice','array_map','array_filter'])) {
                $funcCalls[] = ['func' => $tok[1], 'line' => $tok[2]];
            }
        }
        
        $funcCounts = array_count_values(array_column($funcCalls, 'func'));
        foreach ($funcCounts as $func => $count) {
            if ($count >= 5) {
                $opportunities[] = [
                    'type' => 'repeated_calls',
                    'file' => 'Search.php',
                    'description' => "Функция $func вызывается $count раз — кандидат на кэширование",
                ];
            }
        }
        
        // Ищем: большие массивы (slice с большим лимитом)
        if (preg_match_all('/array_slice\([^,]+,\s*0,\s*(\d+)\)/', $code, $m)) {
            foreach ($m[1] as $size) {
                if ((int)$size >= 40) {
                    $opportunities[] = [
                        'type' => 'large_slice',
                        'file' => 'Search.php',
                        'description' => "array_slice с лимитом $size — много элементов, можно уменьшить",
                    ];
                    break;
                }
            }
        }
        
        return $opportunities;
    }
    
    /**
     * Генерирует КОНКРЕТНЫЙ патч для оптимизации.
     */
    private function generatePatch(array $opp): array
    {
        $file = $this->srcPath . '/' . $opp['file'];
        $code = file_get_contents($file);
        $patched = $code;
        $desc = '';
        
        switch ($opp['type']) {
            case 'nested_loops':
                // Стратегия: добавить кэширование результатов во внешнем цикле
                $desc = 'Кэшировать промежуточные результаты во внешнем цикле';
                // Практически: вынести $feats[$fname] за внутренний цикл
                $patched = $this->cacheInLoops($code);
                break;
                
            case 'repeated_calls':
                // Стратегия: сохранять результат первого вызова в переменную
                $desc = 'Кэшировать повторные вызовы функций';
                $patched = $this->cacheFunctionCalls($code);
                break;
                
            case 'large_slice':
                // Стратегия: уменьшить размер slice
                $desc = 'Уменьшить лимит array_slice для ускорения';
                $patched = preg_replace('/(array_slice\([^,]+,\s*0,\s*)\d+/', '$1' . '20', $code, 1);
                break;
        }
        
        return [
            'description' => $desc,
            'file' => $file,
            'original' => $code,
            'patched' => $patched,
        ];
    }
    
    private function cacheInLoops(string $code): string
    {
        // Выносим $va = $exprs[$pool[$a]] за внутренний цикл
        // Было: for($a) { for($b) { $va = ...$pool[$a]...; $vb = ...$pool[$b]...; } }
        // Стало: for($a) { $va = ...$pool[$a]...; for($b) { $vb = ...$pool[$b]...; } }
        
        $pattern = '/(for\s*\(\s*\$a\s*=.+?\$pool.+?\)\s*\{)\s*(for\s*\(\s*\$b\s*=.+?\$pool.+?\)\s*\{)\s*(\$va\s*=\s*\$exprs\[\s*\$pool\[\s*\$a\s*\]\s*\];)\s*(\$vb\s*=\s*\$exprs\[\s*\$pool\[\s*\$b\s*\]\s*\];)/s';
        
        if (preg_match($pattern, $code, $m)) {
            $replacement = $m[1] . "\n                " . $m[3] . "  // hoisted\n                " . $m[2] . "\n                    " . $m[4];
            return str_replace($m[0], $replacement, $code);
        }
        
        return $code;
    }
    
    private function cacheFunctionCalls(string $code): string
    {
        // Добавляем сохранение array_keys($exprs) в переменную если вызывается >1 раза
        if (substr_count($code, 'array_keys($exprs)') >= 2) {
            $code = str_replace(
                '// Evaluate all expressions',
                '$exprKeys = array_keys($exprs); // cached
        // Evaluate all expressions',
                $code
            );
            // Заменяем последующие вызовы array_keys($exprs) на $exprKeys
            $code = preg_replace(
                '/array_keys\(\$exprs\)/',
                '$exprKeys',
                $code,
                2  // только последующие 2 вызова
            );
        }
        return $code;
    }
    
    /**
     * Тестирует патч на дочернем процессе.
     */
    private function testPatch(array $patch): array
    {
        $testTasks = [
            ['task'=>'AND','domain'=>'logic','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['task'=>'OR','domain'=>'logic','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
            ['task'=>'Add','domain'=>'arith','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
        ];
        
        // Тест оригинала
        $origChild = $this->spawner->spawn([
            'search_depth'=>3, 'bees'=>2, 'grammar_ops'=>['+','−','×','/','abs'], 'port'=>28765
        ]);
        $origBench = $this->spawner->benchmark($origChild, $testTasks);
        exec("rm -rf {$origChild['path']} 2>/dev/null");
        
        // Тест патча
        $patchChild = $this->spawner->spawn([
            'search_depth'=>3, 'bees'=>2, 'grammar_ops'=>['+','−','×','/','abs'], 'port'=>28766
        ]);
        file_put_contents($patchChild['path'] . '/src/Search.php', $patch['patched']);
        $patchBench = $this->spawner->benchmark($patchChild, $testTasks);
        exec("rm -rf {$patchChild['path']} 2>/dev/null");
        
        $origSolved = $origBench['tasks_solved'] === '3/3';
        $patchSolved = $patchBench['tasks_solved'] === '3/3';
        
        $origScore = ($origSolved ? 100 : 0) + (1.0 / ($origBench['elapsed_sec'] + 0.01));
        $patchScore = ($patchSolved ? 100 : 0) + (1.0 / ($patchBench['elapsed_sec'] + 0.01));
        
        return [
            'score_original' => round($origScore, 2),
            'score_patched' => round($patchScore, 2),
            'better' => $patchScore > $origScore && $patchSolved,
            'orig_time' => $origBench['elapsed_sec'],
            'patch_time' => $patchBench['elapsed_sec'],
        ];
    }
    
    private function applyPatch(array $patch): void
    {
        if ($patch['patched'] !== $patch['original']) {
            file_put_contents($patch['file'], $patch['patched']);
        }
    }
}
