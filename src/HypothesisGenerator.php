<?php
declare(strict_types=1);
namespace BeeSwarm;

use BeeSwarm\Core\Grammar;

/**
 * HypothesisGenerator: генерирует проверяемые гипотезы из кросс-доменных мостов.
 * Принцип: если операция × работает в 6 доменах, она ДОЛЖНА работать в домене X.
 * CV→0 проверяет гипотезу позже — генератор предлагает ЧТО проверить.
 */
class HypothesisGenerator
{
    /**
     * Генерирует гипотезы на основе кросс-доменных паттернов.
     * Возвращает список: что искать, в каком домене, с какой уверенностью.
     */
    public function generate(): array
    {
        $db = Database::get();
        $g = new Grammar();
        
        // Все законы с формулами и доменами
        $laws = $db->query("SELECT name, formula, cv, domain FROM laws ORDER BY domain")->fetchAll();
        $domains = $db->query("SELECT DISTINCT domain FROM laws")->fetchAll(\PDO::FETCH_COLUMN);
        $ops = $g->all();
        
        if (count($laws) < 5) {
            return ['status' => 'недостаточно данных', 'hypotheses' => []];
        }
        
        $hypotheses = [];
        
        // 1. ПАТТЕРН: операция используется в N доменах → должна работать в остальных
        foreach ($ops as $op) {
            $domainsWithOp = [];
            foreach ($laws as $law) {
                if (str_contains($law['formula'], $op)) {
                    $domainsWithOp[$law['domain']] = true;
                }
            }
            
            $hasDomains = array_keys($domainsWithOp);
            $missingDomains = array_diff($domains, $hasDomains);
            
            if (count($hasDomains) >= 2 && !empty($missingDomains)) {
                foreach ($missingDomains as $missing) {
                    $confidence = min(0.9, count($hasDomains) / count($domains) * 1.2);
                    $hypotheses[] = [
                        'type' => 'operation_extension',
                        'hypothesis' => "Операция '$op' используется в " . implode(', ', $hasDomains) . 
                                       ". Вероятно, она применима и в домене '$missing'.",
                        'operation' => $op,
                        'existing_domains' => $hasDomains,
                        'target_domain' => $missing,
                        'confidence' => round($confidence, 2),
                        'action' => "Поискать законы с '$op' в домене '$missing'.",
                    ];
                }
            }
        }
        
        // 2. ПАТТЕРН: формула-шаблон повторяется → найти где ещё
        $formulaPatterns = [];
        foreach ($laws as $law) {
            // Нормализуем формулу: x0, x1... → x, x...
            $normalized = preg_replace('/x\d+/', 'x', $law['formula']);
            if (!isset($formulaPatterns[$normalized])) {
                $formulaPatterns[$normalized] = [];
            }
            $formulaPatterns[$normalized][] = $law['domain'];
        }
        
        foreach ($formulaPatterns as $pattern => $patternDomains) {
            $uniqueDomains = array_unique($patternDomains);
            if (count($uniqueDomains) >= 2) {
                $missing = array_diff($domains, $uniqueDomains);
                foreach ($missing as $m) {
                    $hypotheses[] = [
                        'type' => 'pattern_replication',
                        'hypothesis' => "Паттерн '$pattern' найден в " . implode(', ', $uniqueDomains) . 
                                       ". Возможно, он существует и в '$m'.",
                        'pattern' => $pattern,
                        'existing_domains' => $uniqueDomains,
                        'target_domain' => $m,
                        'confidence' => round(0.7, 2),
                        'action' => "Проверить паттерн '$pattern' на данных из домена '$m'.",
                    ];
                }
            }
        }
        
        // 3. ПАТТЕРН: домен без законов с базовыми операциями
        $baseOps = ['×', '+', '/', '−', '²'];
        foreach ($domains as $domain) {
            foreach ($baseOps as $op) {
                $found = false;
                foreach ($laws as $law) {
                    if ($law['domain'] === $domain && str_contains($law['formula'], $op)) {
                        $found = true; break;
                    }
                }
                if (!$found && $domain !== 'api') {
                    $hypotheses[] = [
                        'type' => 'missing_base_op',
                        'hypothesis' => "В домене '$domain' нет законов с операцией '$op'. " .
                                       "Это базовый паттерн — он должен там быть.",
                        'operation' => $op,
                        'target_domain' => $domain,
                        'confidence' => 0.85,
                        'action' => "Поискать '$op'-законы в домене '$domain'.",
                    ];
                }
            }
        }
        
        // 4. ПАТТЕРН: новый домен — предсказать какие операции там будут
        $grammarFootprint = [];
        foreach ($domains as $d) {
            $grammarFootprint[$d] = [];
            foreach ($ops as $op) {
                foreach ($laws as $law) {
                    if ($law['domain'] === $d && str_contains($law['formula'], $op)) {
                        $grammarFootprint[$d][$op] = true;
                    }
                }
            }
        }
        
        // Средний grammar footprint по всем доменам
        $avgOps = [];
        foreach ($ops as $op) {
            $cnt = 0;
            foreach ($grammarFootprint as $fp) {
                if (isset($fp[$op])) $cnt++;
            }
            $avgOps[$op] = $cnt / max(1, count($domains));
        }
        $predictedOps = [];
        foreach ($avgOps as $op => $freq) {
            if ($freq > 0.5) $predictedOps[] = $op;
        }
        
        // Добавляем НЕИССЛЕДОВАННЫЕ домены из онтологии
        $knownDomains = ['логика', 'арифметика', 'сравнения', 'паттерны', 
                        'медицина', 'право', 'химия', 'язык', 'социология'];
        $exploredDomains = array_merge($domains, ['api']);
        $unexplored = array_diff($knownDomains, $exploredDomains);
        
        foreach ($unexplored as $newDomain) {
            if (!empty($predictedOps)) {
                $hypotheses[] = [
                    'type' => 'unexplored_domain',
                    'hypothesis' => "Домен '$newDomain' не исследован. " .
                                   "Предсказываю операции: " . implode(', ', $predictedOps) . 
                                   ". Это универсальный grammar footprint.",
                    'predicted_operations' => $predictedOps,
                    'target_domain' => $newDomain,
                    'confidence' => 0.9,
                    'action' => "Дать 5-10 примеров из '$newDomain'. Формат: [[x1, x2, y], ...].",
                ];
            }
        }
        
        // Предсказание для нового домена
        
        // Сортировка по confidence
        usort($hypotheses, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        
        return [
            'status' => 'ok',
            'total_laws' => count($laws),
            'total_domains' => count($domains),
            'total_ops' => count($ops),
            'hypotheses_count' => count($hypotheses),
            'hypotheses' => array_slice($hypotheses, 0, 10),
        ];
    }
}
