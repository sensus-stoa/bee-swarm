<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * ParadigmHypothesis: генерирует ПАРАДИГМАЛЬНЫЕ гипотезы из графа знаний.
 * Не «× отсутствует в biology», а «compression-dissipation = универсальный принцип».
 */
class ParadigmHypothesis
{
    /**
     * Ищет глубинные паттерны в графе знаний.
     * Транзитивные цепочки → абстрактные принципы.
     */
    public function generate(): array
    {
        $db = Database::get();
        
        // Все факты из knowledge_graph
        $facts = $db->query("SELECT subject, predicate, object FROM knowledge_graph ORDER BY id")->fetchAll();
        
        if (count($facts) < 10) {
            return ['status' => 'need_more_knowledge', 'facts' => count($facts)];
        }
        
        $hypotheses = [];
        
        // 1. ТРАНЗИТИВНЫЕ ЦЕПОЧКИ: A→B→C→D → A→D
        foreach ($facts as $a) {
            foreach ($facts as $b) {
                if ($a['object'] === $b['subject'] && $a['predicate'] === $b['predicate']) {
                    // A is_a B, B is_a C → A is_a C
                    $chain = "{$a['subject']} → {$a['object']} → {$b['object']}";
                    
                    // Это уже есть как факт или вывод?
                    $already = false;
                    foreach ($facts as $c) {
                        if ($c['subject'] === $a['subject'] && 
                            $c['predicate'] === $a['predicate'] && 
                            $c['object'] === $b['object']) {
                            $already = true; break;
                        }
                    }
                    
                    if (!$already) {
                        // Классифицируем цепочку как парадигмальную если пересекает домены
                        $domains1 = $this->getDomains($a['subject'], $facts);
                        $domains2 = $this->getDomains($b['object'], $facts);
                        $crossDomain = !empty(array_diff($domains1, $domains2));
                        
                        $hypotheses[] = [
                            'type' => 'transitive_chain',
                            'hypothesis' => "{$a['subject']} {$a['predicate']} {$b['object']} " .
                                           "(через цепочку: $chain)",
                            'chain' => $chain,
                            'cross_domain' => $crossDomain,
                            'confidence' => $crossDomain ? 0.8 : 0.4,
                            'paradigm_level' => $crossDomain ? 'высокий' : 'средний',
                        ];
                    }
                }
            }
        }
        
        // 2. КОНЦЕПТУАЛЬНЫЕ МОСТЫ: концепт связывает два мира
        foreach ($facts as $a) {
            foreach ($facts as $b) {
                if ($a['subject'] === $b['subject'] && $a['predicate'] !== $b['predicate']) {
                    // X is_a A и X can B → «X соединяет онтологию и действие»
                    $hypotheses[] = [
                        'type' => 'conceptual_bridge',
                        'hypothesis' => "{$a['subject']} соединяет '{$a['object']}' и '{$b['object']}' " .
                                       "(через {$a['predicate']} и {$b['predicate']})",
                        'concept' => $a['subject'],
                        'confidence' => 0.6,
                        'paradigm_level' => 'средний',
                    ];
                    break 2; // один пример
                }
            }
        }
        
        // 4. СЕМАНТИЧЕСКИЕ КЛАСТЕРЫ: концепты одного поля в разных доменах
        // Ищем: какие концепты связаны с «compression» → образуют ли они мост?
        $semanticClusters = [];
        foreach ($facts as $f) {
            $obj = $f['object'];
            if (!isset($semanticClusters[$obj])) $semanticClusters[$obj] = [];
            $semanticClusters[$obj][] = $f['subject'];
        }
        
        // «compression» и «dissipation» — центральные концепты
        foreach (['сжатие', 'рассеивание', 'compression', 'dissipation', 'fidelity'] as $hub) {
            if (isset($semanticClusters[$hub])) {
                $members = $semanticClusters[$hub];
                if (count($members) >= 2) {
                    $hypotheses[] = [
                        'type' => 'semantic_cluster',
                        'hypothesis' => "Концепты [" . implode(', ', $members) . 
                                       "] связаны через '$hub'. " .
                                       "Это образует семантическое поле — кандидат на парадигму.",
                        'hub' => $hub,
                        'members' => $members,
                        'confidence' => min(0.95, count($members) / 3),
                        'paradigm_level' => 'высокий',
                    ];
                }
            }
        }
        
        // 6. ПАРАДИГМАЛЬНЫЙ УРОВЕНЬ: хаб пронизывает ≥3 разных доменов
        foreach ($semanticClusters as $hub => $members) {
            $uniqueMembers = array_unique($members);
            if (count($uniqueMembers) < 3) continue;
            
            // Определяем домены членов через их связи
            $domains = [];
            foreach ($uniqueMembers as $member) {
                $dom = $this->getDomain($member, $facts);
                if ($dom) $domains[$dom] = true;
            }
            
            $domainCount = count($domains);
            if ($domainCount >= 2) {
                $domainList = implode(', ', array_keys($domains));
                $hypotheses[] = [
                    'type' => 'paradigm_level',
                    'hypothesis' => "«{$hub}» — УНИВЕРСАЛЬНЫЙ ПРИНЦИП. " .
                                   "Пронизывает домены: {$domainList}. " .
                                   "Концепты-члены: " . implode(', ', array_slice($uniqueMembers, 0, 5)) . ". " .
                                   "Это не семантический кластер — это закон реальности.",
                    'hub' => $hub,
                    'members' => $uniqueMembers,
                    'domains' => array_keys($domains),
                    'domain_count' => $domainCount,
                    'confidence' => min(0.98, 0.5 + $domainCount * 0.15),
                    'paradigm_level' => 'парадигма',
                ];
            }
        }
        
        // 7. МЕТА-ПАРАДИГМА: два хаба связаны → универсальный цикл
        foreach ($semanticClusters as $hub1 => $m1) {
            foreach ($semanticClusters as $hub2 => $m2) {
                if ($hub1 >= $hub2) continue;
                // Проверяем: есть ли мост между compression и dissipation?
                $connected = false;
                foreach ($m1 as $a) {
                    foreach ($m2 as $b) {
                        foreach ($facts as $f) {
                            if (($f['subject'] === $a && $f['object'] === $b) ||
                                ($f['subject'] === $b && $f['object'] === $a)) {
                                $connected = true; break 3;
                            }
                        }
                    }
                }
                
                if ($connected) {
                    $hypotheses[] = [
                        'type' => 'meta_paradigm',
                        'hypothesis' => "«{$hub1}-{$hub2}» — МЕТА-ПАРАДИГМА. " .
                                       "Два универсальных принципа ОБЪЕДИНЕНЫ в цикл. " .
                                       "Это не просто связь — это динамика реальности.",
                        'hubs' => [$hub1, $hub2],
                        'confidence' => 0.99,
                        'paradigm_level' => 'мета-парадигма',
                    ];
                }
            }
        }
        foreach ($facts as $a) {
            foreach ($facts as $b) {
                if ($a['subject'] === $b['subject'] && 
                    $a['predicate'] !== $b['predicate'] &&
                    $a['object'] !== $b['object']) {
                    // compression — это сжатие И compression-dissipation — универсальный принцип
                    // → compression соединяет «сжатие» и «универсальный принцип»
                    $fields = [
                        $this->getField($a['object'], $facts),
                        $this->getField($b['object'], $facts),
                    ];
                    if ($fields[0] !== $fields[1]) {
                        $hypotheses[] = [
                            'type' => 'cross_field_bridge',
                            'hypothesis' => "{$a['subject']} — мост между '{$a['object']}' " .
                                           "({$fields[0]}) и '{$b['object']}' ({$fields[1]})",
                            'concept' => $a['subject'],
                            'fields' => $fields,
                            'confidence' => 0.75,
                            'paradigm_level' => 'высокий',
                        ];
                        break 2;
                    }
                }
            }
        }
        $patterns = [];
        foreach ($facts as $f) {
            $key = $f['predicate'];
            $patterns[$key][] = $f['subject'];
        }
        
        foreach ($patterns as $pred => $subjects) {
            $uniqueSubjects = array_unique($subjects);
            if (count($uniqueSubjects) >= 3) {
                $hypotheses[] = [
                    'type' => 'structural_isomorphism',
                    'hypothesis' => "Отношение '{$pred}' объединяет " . implode(', ', array_slice($uniqueSubjects, 0, 5)) . 
                                   ". Это универсальный паттерн.",
                    'predicate' => $pred,
                    'count' => count($uniqueSubjects),
                    'confidence' => min(0.9, count($uniqueSubjects) / 5),
                    'paradigm_level' => 'высокий',
                ];
            }
        }
        
        // Сортируем по confidence
        usort($hypotheses, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        
        // Оставляем топ-10, приоритет — парадигмальные
        $paradigm = array_filter($hypotheses, fn($h) => ($h['paradigm_level'] ?? '') === 'высокий');
        $rest = array_filter($hypotheses, fn($h) => ($h['paradigm_level'] ?? '') !== 'высокий');
        $selected = array_merge(array_slice($paradigm, 0, 5), array_slice($rest, 0, 5));
        
        return [
            'status' => 'ok',
            'facts_analyzed' => count($facts),
            'hypotheses_count' => count($hypotheses),
            'paradigm_level' => count($paradigm),
            'hypotheses' => array_values($selected),
        ];
    }
    
    private function getDomains(string $concept, array $facts): array
    {
        $domains = [];
        foreach ($facts as $f) {
            if ($f['subject'] === $concept || $f['object'] === $concept) {
                $domains[] = 'knowledge';
            }
        }
        return array_unique($domains);
    }
    
    private function getDomain(string $concept, array $facts): string
    {
        // Определяем домен концепта по его предикатам
        foreach ($facts as $f) {
            if ($f['subject'] === $concept || $f['object'] === $concept) {
                $pred = $f['predicate'];
                if ($pred === 'is_a') return 'онтология';
                if ($pred === 'can') return 'действие';
                if ($pred === 'has') return 'свойства';
                return 'отношения';
            }
        }
        return '';
    }
    
    private function getField(string $concept, array $facts): string
    {
        // Определяем семантическое поле концепта по его связям
        foreach ($facts as $f) {
            if ($f['object'] === $concept && $f['predicate'] === 'is_a') {
                return (string)$f['subject']; // родительское понятие
            }
        }
        return 'общее';
    }
}
