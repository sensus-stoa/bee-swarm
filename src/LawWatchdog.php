<?php
declare(strict_types=1);
namespace BeeSwarm;

use BeeSwarm\Core\Search;

use BeeSwarm\Core\ExpressionTree;

/**
 * LawWatchdog: следит за CV законов на новых данных.
 * Если CV выросло → закон нужно пересмотреть → запускает перепоиск.
 */
class LawWatchdog
{
    /**
     * Проверяет закон на новых данных. Если CV упало — закон под вопросом.
     */
    public function check(string $lawName, array $newData): array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT formula, cv as original_cv, domain FROM laws WHERE name = ? LIMIT 1");
        if (!$stmt) return ['status' => 'db_error', 'reason' => 'prepare failed'];
        $stmt->execute([$lawName]);
        $law = $stmt->fetch();
        
        if (!$law) return ['status' => 'not_found'];
        
        $X = array_map(fn($r) => array_slice($r, 0, -1), $newData);
        $y = array_map(fn($r) => end($r), $newData);
        
        // Оцениваем старую формулу на новых данных
        $oldCv = $this->evaluateFormula($law['formula'], $X, $y);
        $originalCv = (float)$law['original_cv'];
        
        // Порог: если CV выросло в 3+ раза → закон под вопросом
        $threshold = max($originalCv * 3, 0.15);
        
        if ($oldCv > $threshold) {
            // Запускаем перепоиск
            $g = new Grammar();
            [$ok, $newCv, $newFormula] = Search::find($X, $y, $g, 3);
            
            if ($ok || $newCv < $oldCv) {
                // Обновляем закон в БД
                $stmt = $db->prepare("UPDATE laws SET formula = ?, cv = ? WHERE name = ?");
                $stmt->execute([$newFormula, $newCv, $lawName]);
                
                return [
                    'status' => 'revised',
                    'old_formula' => $law['formula'],
                    'old_cv' => $oldCv,
                    'new_formula' => $newFormula,
                    'new_cv' => $newCv,
                    'reason' => "CV выросло с $originalCv до $oldCv на новых данных — закон пересмотрен",
                ];
            }
            
            return [
                'status' => 'needs_investigation',
                'old_formula' => $law['formula'],
                'old_cv' => $oldCv,
                'reason' => "CV выросло до $oldCv, новый поиск не дал улучшения",
            ];
        }
        
        return [
            'status' => 'stable',
            'original_cv' => $originalCv,
            'current_cv' => $oldCv,
            'reason' => "CV стабильно ($oldCv vs original $originalCv)",
        ];
    }
    
    private function evaluateFormula(string $formula, array $X, array $y): float
    {
        // Простая оценка: если формула вида (x0/x1), вычисляем и сравниваем
        // Для сложных формул используем ExpressionTree
        try {
            $tree = ExpressionTree::fromFormula($formula);
            if ($tree) {
                $vec = [];
                foreach ($X as $i => $row) {
                    $vec[] = $tree->evaluate($row[0] ?? 0, $row[1] ?? 0);
                }
                return Search::cv($vec, $y);
            }
        } catch (\Throwable $e) {}
        
        return 9.99;
    }
}
