<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * NESTED Level 5: Meta-learning from invention history.
 * Анализирует какие стратегии сработали в прошлом → применяет к новым задачам.
 */
class NestedLevel5
{
    private array $strategyHistory = [];  // [domain, strategy, success]
    
    public function __construct()
    {
        $this->loadHistory();
    }
    
    private function loadHistory(): void
    {
        $db = Database::get();
        $rows = $db->query("SELECT name, source FROM grammar_ops WHERE source LIKE 'auto-%'")->fetchAll();
        foreach ($rows as $row) {
            $this->strategyHistory[] = [
                'strategy' => $row['source'],
                'op' => $row['name'],
            ];
        }
    }
    
    /**
     * Level 5: Умное изобретение — учится на истории.
     * Возвращает [name, fn] или null.
     */
    public function invent(array $unsolved, Grammar $grammar): ?array
    {
        // Анализируем КАЖДУЮ нерешённую задачу
        foreach ($unsolved as [$X, $y, $name]) {
            $n = count($y);
            
            // 1. Анализ структуры данных
            $features = $this->analyzeData($X, $y);
            
            // 2. Поиск похожих успешных стратегий в истории
            $candidateStrategy = $this->findSimilarStrategy($features, $name);
            
            // 3. Применить стратегию-кандидат
            if ($candidateStrategy) {
                $result = $this->applyStrategy($candidateStrategy, $X, $y, $grammar);
                if ($result) return $result;
            }
            
            // 4. Сгенерировать НОВУЮ стратегию из комбинации известных
            $combination = $this->combineStrategies($X, $y, $grammar);
            if ($combination) return $combination;
        }
        
        return null;
    }
    
    private function analyzeData(array $X, array $y): array
    {
        $n = count($y);
        $nFeat = count($X[0]);
        
        $feat = [];
        $feat['n_features'] = $nFeat;
        $feat['n_points'] = $n;
        
        // Is output constant?
        $mean = array_sum($y) / $n;
        $var = 0.0;
        foreach ($y as $v) $var += ($v - $mean) ** 2;
        $cv = sqrt($var / $n) / (abs($mean) + 1e-8);
        $feat['is_constant'] = $cv < 0.01;
        $feat['mean_value'] = round($mean, 1);
        
        // Is exponential growth?
        if ($mean > 0 && $n >= 3) {
            $ratios = [];
            for ($i = 1; $i < $n; $i++) {
                if ($y[$i-1] > 0) $ratios[] = $y[$i] / $y[$i-1];
            }
            if (count($ratios) >= 2) {
                $ratioMean = array_sum($ratios) / count($ratios);
                $ratioVar = 0.0;
                foreach ($ratios as $r) $ratioVar += ($r - $ratioMean) ** 2;
                $feat['is_exponential'] = sqrt($ratioVar / count($ratios)) / (abs($ratioMean) + 1e-8) < 0.01;
                $feat['exp_base'] = round($ratioMean);
            }
        }
        
        // Is alternating?
        $unique = array_unique($y);
        if (count($unique) === 2) {
            $vals = array_values($unique);
            $feat['is_alternating'] = abs($vals[0] + $vals[1]) < 0.01;
        }
        
        // Is sequential? (first feature is 1,2,3,4...)
        if ($nFeat >= 1) {
            $col0 = array_column($X, 0);
            $isSeq = true;
            for ($i = 1; $i < $n; $i++) {
                if (abs($col0[$i] - $col0[$i-1] - 1) > 0.01) { $isSeq = false; break; }
            }
            $feat['is_sequential'] = $isSeq;
        }
        
        return $feat;
    }
    
    private function findSimilarStrategy(array $features, string $taskName): ?string
    {
        // Match features → strategies from history
        if ($features['is_constant'] ?? false) return 'auto-constant';
        if ($features['is_exponential'] ?? false) return 'auto-exponential';
        if ($features['is_alternating'] ?? false) return 'auto-parity';
        if ($features['is_sequential'] ?? false) return 'auto-recurrence';
        
        // Cross-domain transfer: if NESTED invented pow2 for patterns, 
        // and this task looks exponential, try powN
        foreach ($this->strategyHistory as $entry) {
            if ($entry['strategy'] === 'auto-exponential' && ($features['is_exponential'] ?? false)) {
                $base = $features['exp_base'] ?? 2;
                return "pow{$base}";
            }
        }
        
        return null;
    }
    
    private function applyStrategy(string $strategy, array $X, array $y, Grammar $grammar): ?array
    {
        $n = count($y);
        
        switch ($strategy) {
            case 'auto-constant':
                $mean = round(array_sum($y) / $n);
                $name = "K{$mean}";
                if (!in_array($name, $grammar->all())) {
                    return [$name, fn() => $mean];
                }
                break;
                
            case 'auto-exponential':
                $ratios = [];
                for ($i = 1; $i < $n; $i++) {
                    if ($y[$i-1] > 0) $ratios[] = $y[$i] / $y[$i-1];
                }
                $base = round(array_sum($ratios) / count($ratios));
                $name = "pow{$base}";
                if (!in_array($name, $grammar->all())) {
                    return [$name, fn($x) => $base ** $x];
                }
                break;
                
            case 'auto-parity':
                $name = 'parity';
                if (!in_array($name, $grammar->all())) {
                    return [$name, fn($x) => ((int)$x % 2 === 0) ? 1.0 : -1.0];
                }
                break;
                
            case 'auto-recurrence':
                // Try shift-2: (prev_prev, prev) → current
                if (count($y) >= 3) {
                    // The recurrence detector handles this in MetaInventor
                    return null;
                }
                break;
                
            default:
                // powN strategy from history
                if (str_starts_with($strategy, 'pow') && strlen($strategy) > 3) {
                    $base = (float)substr($strategy, 3);
                    if (!in_array($strategy, $grammar->all())) {
                        return [$strategy, fn($x) => $base ** $x];
                    }
                }
                break;
        }
        
        return null;
    }
    
    private function combineStrategies(array $X, array $y, Grammar $grammar): ?array
    {
        $n = count($y);
        
        // Combination 1: КОНСТАНТА + ЛИНЕЙНАЯ = affine transform
        $mean = round(array_sum($y) / $n);
        $kName = "K{$mean}";
        
        // Combination 2: ПОЛИНОМ = унарный квадрат + линейная
        // If square unary exists and linear exists → try a*SQ(x) + b
        
        // Combination 3: ОБРАТНАЯ = 1/x если данные убывающие
        if ($n >= 2 && $y[0] > $y[$n-1] && $y[$n-1] > 0) {
            $invName = 'inverse';
            if (!in_array($invName, $grammar->all())) {
                // Check if 1/x matches
                $col0 = array_column($X, 0);
                $testVec = array_map(fn($v) => 1.0 / ($v + 0.01), $col0);
                $match = true;
                for ($i = 0; $i < $n; $i++) {
                    if (abs($testVec[$i] - $y[$i]) > 0.01) { $match = false; break; }
                }
                if ($match) {
                    return [$invName, fn($x) => 1.0 / ($x + 0.01)];
                }
            }
        }
        
        // Combination 4: LOG если x растёт мультипликативно, y — линейно
        $col0 = array_column($X, 0);
        if ($n >= 3 && min($y) >= 0) {
            // Check if x grows multiplicatively (ratios constant)
            $xRatios = [];
            for ($i = 1; $i < $n; $i++) {
                if ($col0[$i-1] > 0) $xRatios[] = $col0[$i] / $col0[$i-1];
            }
            $xRatioConst = count($xRatios) >= 2;
            if ($xRatioConst) {
                $ratioMean = array_sum($xRatios) / count($xRatios);
                foreach ($xRatios as $r) {
                    if (abs($r - $ratioMean) > 0.1) { $xRatioConst = false; break; }
                }
            }
            
            // Check if y grows linearly (diffs constant)
            $yDiffs = [];
            for ($i = 1; $i < $n; $i++) $yDiffs[] = $y[$i] - $y[$i-1];
            $yLinear = count($yDiffs) >= 2;
            if ($yLinear) {
                $diffMean = array_sum($yDiffs) / count($yDiffs);
                foreach ($yDiffs as $d) {
                    if (abs($d - $diffMean) > 0.1) { $yLinear = false; break; }
                }
            }
            
            if ($xRatioConst && $yLinear) {
                $logName = 'log2';
                if (!in_array($logName, $grammar->all())) {
                    return [$logName, fn($x) => log(max($x, 1.0) + 1) / log(2)];
                }
            }
        }
        
        return null;
    }
}
