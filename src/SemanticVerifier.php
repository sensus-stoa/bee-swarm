<?php
declare(strict_types=1);

namespace BeeSwarm;

// ~/.bee_swarm/src/SemanticVerifier.php
// Кросс-валидация семантических фактов из разных источников

class SemanticVerifier
{
    /** Проверить факт: существует ли он в графе */
    public function verifyFact(string $subject, string $predicate, string $object): array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT COUNT(*) FROM knowledge_graph WHERE subject = ? AND predicate = ? AND object = ?");
        $stmt->execute([$subject, $predicate, $object]);
        $count = (int)$stmt->fetchColumn();

        return [
            'confirmed' => $count >= 1,
            'sources'   => $count,
            'confidence' => $count >= 2 ? 1.0 : ($count >= 1 ? 0.7 : 0.3),
        ];
    }

    /** Найти противоречия в is_a */
    public function findContradictions(): array
    {
        $db = Database::get();
        return $db->query(
            "SELECT k1.subject, k1.object as val1, k2.object as val2
             FROM knowledge_graph k1
             JOIN knowledge_graph k2 ON k1.subject = k2.subject AND k1.predicate = k2.predicate
             WHERE k1.object != k2.object AND k1.predicate = 'is_a'"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Кросс-валидация: семантический факт подтверждён числовым законом */
    public function crossValidate(string $subject, string $predicate, string $object): array
    {
        $db = Database::get();
        $lawName = "{$subject}→{$object}";
        $stmt = $db->prepare("SELECT COUNT(*) FROM laws WHERE (name = ? OR name = ?) AND cv < 0.05");
        $stmt->execute([$lawName, "{$object}→{$subject}"]);
        return ['numerical_backing' => $stmt->fetchColumn() > 0];
    }

    /** Проверить все недавние семантические факты */
    public function verifyAll(): array
    {
        $db = Database::get();
        $facts = $db->query("SELECT subject, predicate, object FROM knowledge_graph WHERE predicate = 'is_a' AND confidence >= 1.0")->fetchAll(\PDO::FETCH_ASSOC);

        $result = ['checked' => 0, 'confirmed' => 0, 'uncertain' => 0, 'contradictions' => 0];

        foreach ($facts as $f) {
            $v = $this->verifyFact($f['subject'], $f['predicate'], $f['object']);
            $result['checked']++;
            if ($v['confirmed']) {
                $result['confirmed']++;
            } else {
                $result['uncertain']++;
                // Понизить confidence у неподтверждённых
                $db->prepare("UPDATE knowledge_graph SET confidence = ? WHERE subject = ? AND predicate = ? AND object = ?")
                   ->execute([0.3, $f['subject'], $f['predicate'], $f['object']]);
            }
        }

        $contradictions = $this->findContradictions();
        $result['contradictions'] = count($contradictions);

        return $result;
    }
}
