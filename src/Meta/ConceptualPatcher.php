<?php
declare(strict_types=1);
namespace BeeSwarm\Meta;

/**
 * ConceptualPatcher: «добавь быстрый путь» → сам находит КУДА и КАК.
 * Рой понимает концептуальные команды без указания строк.
 */
class ConceptualPatcher
{
    /**
     * Применить концептуальное изменение.
     * @param string $file     какой файл
     * @param string $concept  что сделать (fast_path, loop_hoist, etc)
     */
    public function apply(string $file, string $concept): array
    {
        $path = '~/.bee_swarm/' . $file;
        if (!file_exists($path)) return ['status' => 'rejected', 'reason' => 'file not found'];
        
        $code = file_get_contents($path);
        $newCode = $this->generateChange($code, $concept);
        
        if ($newCode === $code) return ['status' => 'rejected', 'reason' => 'no change generated'];
        
        // Тестируем через ArchitectProxy
        $proxy = new ArchitectProxy();
        // Находим old/new через сравнение — используем первую отличающуюся строку
        $result = $this->testChange($file, $code, $newCode, $concept, $proxy);
        
        return $result;
    }
    
    private function generateChange(string $code, string $concept): string
    {
        return match ($concept) {
            'fast_path' => $this->addFastPath($code),
            'loop_hoist' => $this->hoistLoopInvariant($code),
            'early_return' => $this->addEarlyReturn($code),
            'cache_arrays' => $this->cacheArrayCalls($code),
            default => $code,
        };
    }
    
    /**
     * «Быстрый путь»: проверить features до сложных выражений.
     */
    private function addFastPath(string $code): string
    {
        if (str_contains($code, 'Evaluate FEATURES first')) return $code; // уже есть
        
        $marker = '// Evaluate all expressions';
        if (!str_contains($code, $marker)) return $code;
        
        $block = <<<'PHP'
        // Evaluate FEATURES first (fast path)
        foreach ($feats as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
            }
            if ($exact) return [true, 0.0, $name];
        }
        
PHP;
        return str_replace($marker, $block . "\n        " . $marker, $code);
    }
    
    /**
     * «Вынести инвариант»: $va за внутренний цикл.
     */
    private function hoistLoopInvariant(string $code): string
    {
        if (str_contains($code, '// hoisted')) return $code;
        
        $pattern = '/(for\s*\(\s*\$a\s*=.+?\$pool.+?\)\s*\{)\s*(for\s*\(\s*\$b\s*=.+?\$pool.+?\)\s*\{)\s*(\$va\s*=\s*\$exprs\[\s*\$pool\[\s*\$a\s*\]\s*\];)/s';
        
        if (preg_match($pattern, $code, $m)) {
            return str_replace($m[0], $m[1] . "\n                " . $m[3] . "  // hoisted\n                " . $m[2], $code);
        }
        return $code;
    }
    
    /**
     * «Ранний возврат»: если CV уже хороший, не проверять остальное.
     */
    private function addEarlyReturn(string $code): string
    {
        if (str_contains($code, 'if ($cv < 0.001)')) return $code;
        
        $old = 'if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; }';
        if (!str_contains($code, $old)) return $code;
        
        $new = 'if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; if ($cv < 0.001) break; }';
        return str_replace($old, $new, $code);
    }
    
    /**
     * «Кэшировать массивы»: array_keys($exprs) → переменная.
     */
    private function cacheArrayCalls(string $code): string
    {
        if (str_contains($code, '$exprKeys = array_keys')) return $code;
        if (substr_count($code, 'array_keys($exprs)') < 2) return $code;
        
        $code = str_replace(
            '// Evaluate all expressions',
            '$exprKeys = array_keys($exprs); // cached' . "\n        " . '// Evaluate all expressions',
            $code
        );
        // Заменяем первый вызов
        $pos = strpos($code, 'array_keys($exprs)');
        if ($pos !== false) {
            $code = substr_replace($code, '$exprKeys', $pos, strlen('array_keys($exprs)'));
        }
        return $code;
    }
    
    private function testChange(string $file, string $original, string $patched, string $concept, ArchitectProxy $proxy): array
    {
        // Находим первую разницу для old/new
        $origLines = explode("\n", $original);
        $patchLines = explode("\n", $patched);
        
        $old = ''; $new = '';
        $inDiff = false;
        $diffLines = [];
        
        for ($i = 0; $i < max(count($origLines), count($patchLines)); $i++) {
            $ol = $origLines[$i] ?? '';
            $pl = $patchLines[$i] ?? '';
            if ($ol !== $pl) {
                if (!$inDiff) {
                    // Контекст: 2 строки до изменения
                    $ctx = [];
                    for ($j = max(0, $i-2); $j < $i; $j++) $ctx[] = $origLines[$j];
                    $old = implode("\n", array_merge($ctx, [$ol]));
                    $inDiff = true;
                }
                $diffLines[] = $pl;
            } elseif ($inDiff) {
                $new = implode("\n", $diffLines);
                break;
            }
        }
        
        if (empty($old) || empty($new)) {
            return ['status' => 'rejected', 'reason' => 'could not compute diff'];
        }
        
        return $proxy->propose($file, $old, $new, "concept: $concept");
    }
}
