<?php
declare(strict_types=1);
namespace BeeSwarm\Forager;

use BeeSwarm\Infra\Database;

/**
 * DataRequestor: рой ЗАПРАШИВАЕТ данные для проверки гипотез.
 * Формирует конкретный запрос, который человек может выполнить.
 */
class DataRequestor
{
    /**
     * Анализирует гипотезы и формирует запросы на данные.
     */
    public function request(): array
    {
        $gen = new HypothesisGenerator();
        $result = $gen->generate();
        $hypotheses = $result['hypotheses'] ?? [];
        
        $requests = [];
        $seenDomains = [];
        
        foreach ($hypotheses as $h) {
            $target = $h['target_domain'] ?? null;
            if (!$target) continue;
            if (isset($seenDomains[$target])) continue;
            
            $db = Database::get();
            $stmt = $db->prepare("SELECT COUNT(*) FROM laws WHERE domain = ?");
            $stmt->execute([$target]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) continue; // уже есть данные
            
            $seenDomains[$target] = true;
            
            // Формируем КОНКРЕТНЫЙ запрос на данные
            $op = $h['operation'] ?? ($h['predicted_operations'][0] ?? '×');
            $requests[] = [
                'domain' => $target,
                'operation' => $op,
                'hypothesis' => $h['hypothesis'],
                'request' => "Дай мне 5-10 примеров из домена '$target'. " .
                            "Формат: [[вход1, вход2, выход], ...]. " .
                            "Я поищу там закон с операцией '$op'.",
                'api_call' => [
                    'method' => 'POST',
                    'path' => '/domain',
                    'body' => [
                        'domain' => $target,
                        'tasks' => [
                            "{$target}_test_1" => '[[...], [...], ...]',
                        ],
                    ],
                ],
                'format' => '[[x1, y1], [x2, y2], ...] где последний элемент = ожидаемый выход',
            ];
        }
        
        return [
            'status' => empty($requests) ? 'all_domains_have_data' : 'requesting_data',
            'requests_count' => count($requests),
            'requests' => $requests,
            'how_to_fulfill' => 'POST /domain {"domain":"...", "tasks":{"name":[[in,out],...]}}',
        ];
    }
}
