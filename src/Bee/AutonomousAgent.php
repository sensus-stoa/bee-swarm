<?php
declare(strict_types=1);
namespace BeeSwarm\Bee;

use BeeSwarm\Infra\Database;

use BeeSwarm\Core\Grammar;

/**
 * AutonomousAgent: рой САМ решает что делать.
 * На основе само-описания выбирает следующее действие.
 */
class AutonomousAgent
{
    /**
     * Принять решение: что делать дальше?
     */
    public function decide(): array
    {
        $db = Database::get();
        $g = new Grammar();
        $cb = new ConsciousBee();
        $s = $cb->state();
        
        // Что мы знаем о себе
        $laws = $db->query("SELECT COUNT(*) FROM laws")->fetchColumn();
        $domains = $db->query("SELECT DISTINCT domain FROM laws")->fetchAll();
        $domainList = array_column($domains, 'domain');
        $facts = $db->query("SELECT COUNT(*) FROM knowledge_graph")->fetchColumn();
        $ops = count($g->all());
        $energy = $s['energy'];
        $virtue = $s['virtue'];
        
        $options = [];
        
        // Вариант 1: найти законы в неисследованном домене
        $knownDomains = ['логика','арифметика','геометрия','физика','биология','химия',
                         'экономика','социология','медицина','право','язык','философия'];
        $unexplored = array_diff($knownDomains, $domainList);
        if (!empty($unexplored)) {
            $target = array_values($unexplored)[array_rand($unexplored)];
            $options[] = [
                'action' => 'explore_domain',
                'domain' => $target,
                'why' => "Домен '$target' не исследован. Там могут быть законы.",
                'priority' => 80,
                'cost' => 'medium',
            ];
        }
        
        // Вариант 2: расширить грамматику
        if ($ops < 10) {
            $options[] = [
                'action' => 'expand_grammar',
                'why' => "Грамматика мала ($ops операций). Нужны новые операции для сложных законов.",
                'priority' => 60 + (10 - $ops) * 5,
                'cost' => 'high',
            ];
        }
        
        // Вариант 3: пополнить знания (факты)
        if ($facts < 100) {
            $options[] = [
                'action' => 'learn_facts',
                'why' => "База знаний мала ($facts фактов). Нужно больше семантических связей.",
                'priority' => 50 + (100 - $facts) / 5,
                'cost' => 'low',
            ];
        }
        
        // Вариант 4: проверить существующие парадигмы
        if ($laws > 10) {
            $options[] = [
                'action' => 'validate_paradigms',
                'why' => "$laws законов — можно искать парадигмы и проверять их.",
                'priority' => 70,
                'cost' => 'low',
            ];
        }
        
        // Вариант 5: если энергия низкая — отдых
        if ($energy < 0.4) {
            $options[] = [
                'action' => 'rest',
                'why' => "Энергия низкая ($energy). Нужен отдых для восстановления.",
                'priority' => 90,
                'cost' => 'none',
            ];
        }
        
        // Вариант 6: если добродетель высока — сложная задача
        if ($virtue > 0.7) {
            $options[] = [
                'action' => 'virtuous_quest',
                'why' => "Добродетель высока ($virtue). Готов к сложному кросс-доменному поиску.",
                'priority' => 75,
                'cost' => 'high',
            ];
        }
        
        // Сортируем по приоритету
        usort($options, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        $chosen = $options[0] ?? null;
        
        $nextSteps = [];
        if ($chosen) {
            switch ($chosen['action']) {
                case 'explore_domain':
                    $nextSteps[] = "POST /domain {\"domain\":\"{$chosen['domain']}\", \"tasks\":{...}}";
                    break;
                case 'expand_grammar':
                    $nextSteps[] = "POST /domain с задачей где CV>0 → MetaInventor изобретёт новую операцию";
                    break;
                case 'learn_facts':
                    $nextSteps[] = "POST /learn {\"sentence\":\"X — это Y\"} — учить новые факты";
                    break;
                case 'validate_paradigms':
                    $nextSteps[] = "GET /validate — проверить парадигмы против CV→0";
                    break;
                case 'rest':
                    $nextSteps[] = "Ждать. Энергия восстановится сама.";
                    break;
                case 'virtuous_quest':
                    $nextSteps[] = "GET /paradigm — найти глубочайшую парадигму и проверить её";
                    break;
            }
        }
        
        return [
            'self' => [
                'laws' => (int)$laws,
                'domains' => count($domainList),
                'grammar_ops' => $ops,
                'facts' => (int)$facts,
                'energy' => $energy,
                'virtue' => $virtue,
            ],
            'decision' => $chosen,
            'options_considered' => count($options),
            'next_steps' => $nextSteps,
        ];
    }
}
