<?php
declare(strict_types=1);
namespace BeeSwarm;

use BeeSwarm\Infra\Database;

/**
 * HypothesisTester: проверяет гипотезы через CV→0.
 * Если данные есть → поиск. Если нет → запрос данных.
 */
class HypothesisTester
{
    /**
     * Проверяет одну гипотезу. Нужны данные из целевого домена.
     */
    public function test(array $hypothesis): array
    {
        $db = Database::get();
        $target = $hypothesis['target_domain'] ?? null;
        $operation = $hypothesis['operation'] ?? ($hypothesis['predicted_operations'][0] ?? null);
        
        if (!$target && !$operation) {
            return ['status' => 'skipped', 'reason' => 'гипотеза без целевого домена и операции'];
        }
        
        if (!$target) {
            return ['status' => 'meta', 'hypothesis' => $hypothesis['hypothesis']];
        }
        
        // Есть ли данные в целевом домене?
        $stmt = $db->prepare("SELECT name, formula FROM laws WHERE domain = ?");
        if (!$stmt) {
            return ['status' => 'error', 'reason' => 'database error'];
        }
        $stmt->execute([$target]);
        $existing = $stmt->fetchAll();
        
        if (empty($existing)) {
            return [
                'status' => 'needs_data',
                'hypothesis' => $hypothesis['hypothesis'],
                'reason' => "Нет данных в домене '$target'. Нужны примеры (вход, выход).",
                'action' => "POST /domain с параметрами {\"domain\":\"$target\",\"tasks\":{...}}",
            ];
        }
        
        // Данные есть — проверяем наличие операции
        $hasOp = false;
        foreach ($existing as $law) {
            if ($operation && str_contains($law['formula'], $operation)) {
                $hasOp = true;
                break;
            }
        }
        
        if ($hasOp) {
            return [
                'status' => 'confirmed',
                'hypothesis' => $hypothesis['hypothesis'],
                'evidence' => "Операция '$operation' уже найдена в '$target': {$law['name']} = {$law['formula']}",
                'confidence_after' => 1.0,
            ];
        }
        
        // Данные есть, но операция не найдена — может, нужно поискать?
        return [
            'status' => 'unconfirmed',
            'hypothesis' => $hypothesis['hypothesis'],
            'reason' => "В домене '$target' есть " . count($existing) . " законов, но '$operation' не обнаружена.",
            'action' => "Возможно, нужны другие данные или операция действительно отсутствует.",
            'confidence_after' => round($hypothesis['confidence'] * 0.5, 2),
        ];
    }
    
    /**
     * Тестирует ВСЕ гипотезы и возвращает сводку.
     */
    public function testAll(): array
    {
        $gen = new HypothesisGenerator();
        $result = $gen->generate();
        $hypotheses = $result['hypotheses'] ?? [];
        
        $confirmed = [];
        $needsData = [];
        $unconfirmed = [];
        
        foreach ($hypotheses as $h) {
            $test = $this->test($h);
            if ($test['status'] === 'confirmed') $confirmed[] = $test;
            elseif ($test['status'] === 'needs_data') $needsData[] = $test;
            else $unconfirmed[] = $test;
        }
        
        // Приоритет: взять первую needs_data и запросить
        $nextAction = null;
        if (!empty($needsData)) {
            $next = $needsData[0];
            $target = $next['hypothesis'];
            // Извлекаем домен из гипотезы
            if (preg_match("/в домене '([^']+)'/", $next['hypothesis'], $m)) {
                $domain = $m[1];
                $nextAction = [
                    'action' => 'POST /domain с данными',
                    'domain' => $domain,
                    'why' => $next['hypothesis'],
                ];
            }
        }
        
        return [
            'total' => count($hypotheses),
            'confirmed' => count($confirmed),
            'unconfirmed' => count($unconfirmed),
            'needs_data' => count($needsData),
            'next_action' => $nextAction,
            'confirmed_list' => array_slice($confirmed, 0, 5),
            'needs_data_list' => array_slice($needsData, 0, 3),
        ];
    }
}
