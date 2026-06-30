<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * ParadigmValidator: замыкает цикл парадигма → CV→0 → подтверждение.
 * 1. Берёт парадигмальные гипотезы из графа знаний
 * 2. Проверяет: есть ли CV→0 законы для центральных концептов
 * 3. Подтверждает (есть законы) или запрашивает данные (нет законов)
 */
class ParadigmValidator
{
    /**
     * Проверяет все парадигмы против базы законов.
     */
    public function validate(): array
    {
        $gen = new ParadigmHypothesis();
        $paradigms = $gen->generate();
        
        if (($paradigms['status'] ?? '') === 'need_more_knowledge') {
            return ['status' => 'insufficient', 'facts' => $paradigms['facts']];
        }
        
        $db = Database::get();
        $laws = $db->query("SELECT name, formula, cv, domain FROM laws")->fetchAll();
        
        $validated = [];
        $requests = [];
        
        foreach ($paradigms['hypotheses'] as $h) {
            $result = $this->checkParadigm($h, $laws);
            $validated[] = $result;
            
            if ($result['status'] === 'needs_data') {
                $requests[] = $result['request'];
            }
        }
        
        $confirmed = array_filter($validated, fn($v) => $v['status'] === 'confirmed');
        $needsData = array_filter($validated, fn($v) => $v['status'] === 'needs_data');
        
        $nextAction = null;
        if (!empty($needsData)) {
            $next = reset($needsData);
            $nextAction = $next['request'] ?? null;
        }
        
        return [
            'status' => 'ok',
            'paradigms_total' => count($validated),
            'confirmed' => count($confirmed),
            'needs_data' => count($needsData),
            'next_action' => $nextAction,
            'results' => array_values($validated),
        ];
    }
    
    private function checkParadigm(array $hypothesis, array $laws): array
    {
        $concept = $hypothesis['hub'] ?? ($hypothesis['concept'] ?? null);
        
        // Если нет центрального концепта — это не семантический кластер
        if (!$concept) {
            return [
                'status' => 'meta',
                'hypothesis' => $hypothesis['hypothesis'],
                'reason' => 'нет центрального концепта для проверки',
            ];
        }
        
        // Ищем законы где формула или имя задачи содержат концепт
        $matchingLaws = [];
        foreach ($laws as $law) {
            if (stripos($law['name'], $concept) !== false || 
                stripos($law['formula'], $concept) !== false ||
                stripos($law['domain'], $concept) !== false) {
                $matchingLaws[] = $law;
            }
        }
        
        // Ищем законы в доменах, связанных с парадигмой
        $members = $hypothesis['members'] ?? [];
        $domainMatches = [];
        foreach ($laws as $law) {
            foreach ($members as $member) {
                if (stripos($law['domain'], $member) !== false ||
                    stripos($law['name'], $member) !== false) {
                    $domainMatches[] = $law;
                    break;
                }
            }
        }
        
        // Подтверждение: есть законы с CV→0 в релевантных доменах
        $cv0Laws = array_filter($matchingLaws, fn($l) => $l['cv'] < 0.01);
        $allMatching = array_merge($matchingLaws, $domainMatches);
        
        if (!empty($cv0Laws)) {
            return [
                'status' => 'confirmed',
                'hypothesis' => $hypothesis['hypothesis'],
                'concept' => $concept,
                'evidence' => count($cv0Laws) . ' законов с CV≈0 содержат концепт ' . $concept,
                'laws' => array_values($cv0Laws),
                'confidence' => min(1.0, ($hypothesis['confidence'] ?? 0.5) + 0.2),
            ];
        }
        
        if (!empty($matchingLaws)) {
            return [
                'status' => 'partial',
                'hypothesis' => $hypothesis['hypothesis'],
                'concept' => $concept,
                'evidence' => count($matchingLaws) . ' законов с CV>0 — нужно уточнение',
                'confidence' => $hypothesis['confidence'] ?? 0.5,
            ];
        }
        
        // Нет законов — запрашиваем данные
        $memberList = implode(', ', array_slice($members, 0, 3));
        return [
            'status' => 'needs_data',
            'hypothesis' => $hypothesis['hypothesis'],
            'concept' => $concept,
            'reason' => "Концепт '$concept' (связанный с: $memberList) не имеет CV→0 законов.",
            'request' => [
                'action' => 'POST /domain',
                'domain' => $concept . '_validation',
                'why' => "Проверить парадигму: {$hypothesis['hypothesis']}",
                'format' => '{"tasks":{"test":[[x1,y1],[x2,y2],...]}}',
            ],
        ];
    }
}
