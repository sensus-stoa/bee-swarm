<?php
declare(strict_types=1);

namespace BeeSwarm\Validation;

use BeeSwarm\AtomRegistry;
use BeeSwarm\Database;


// ~/.bee_swarm/src/LawVerifier.php
// H1: Верификация старых законов на новых данных → сжатие grammar

class LawVerifier
{
    private float $threshold;

    public function __construct(float $threshold = 0.1)
    {
        $this->threshold = $threshold;
    }

    /** Проверить закон на новых данных */
    public function verify(array $law, array $newData): array
    {
        if (count($newData) < 2) {
            return ['verified' => false, 'cv' => 9.99, 'reason' => 'insufficient_data'];
        }

        $formula = $law['formula'] ?? '';
        $atoms = $this->parseFormula($formula);
        if (!$atoms) {
            return ['verified' => false, 'cv' => 9.99, 'reason' => 'unparseable'];
        }

        $X = array_map(fn($r) => array_slice($r, 0, -1), $newData);
        $y = array_column($newData, count($newData[0]) - 1);

        $vec = [];
        foreach ($X as $row) {
            $v = $this->applyFormula($atoms, $row);
            if ($v === null || is_nan($v) || is_infinite($v)) {
                return ['verified' => false, 'cv' => 9.99, 'reason' => 'apply_error'];
            }
            $vec[] = $v;
        }

        $cv = AtomRegistry::cv($vec, $y);
        $verified = $cv <= $this->threshold;

        return ['verified' => $verified, 'cv' => $cv];
    }

    /** Проверить все законы домена и удалить ложные */
    public function verifyAndPrune(string $domain, array $newData): array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT name, formula, cv, domain FROM laws WHERE domain = ?");
        $stmt->execute([$domain]);
        $laws = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = ['checked' => 0, 'pruned' => 0, 'confirmed' => 0, 'details' => []];

        foreach ($laws as $law) {
            $v = $this->verify($law, $newData);
            $result['checked']++;

            if ($v['reason'] === 'insufficient_data' || $v['reason'] === 'unparseable') {
                continue;
            }

            if ($v['verified']) {
                $result['confirmed']++;
            } elseif ($v['cv'] > $this->threshold) {
                $delStmt = $db->prepare("DELETE FROM laws WHERE name = ? AND domain = ?");
                $delStmt->execute([$law['name'], $domain]);
                $result['pruned']++;
                $result['details'][] = [
                    'name' => $law['name'],
                    'formula' => $law['formula'],
                    'old_cv' => $law['cv'],
                    'new_cv' => round($v['cv'], 4),
                ];
            }
        }

        return $result;
    }

    /** Получить случайный старый закон */
    public function getRandomLaw(string $domain = 'arithmetic'): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT name, formula, cv, domain FROM laws WHERE domain = ? ORDER BY RANDOM() LIMIT 1");
        $stmt->execute([$domain]);
        $law = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $law ?: null;
    }

    // ═══ PARSER ═══

    private function parseFormula(string $formula): ?array
    {
        $formula = trim($formula);

        if (preg_match('/^(\w+)\(x0,x1\)$/', $formula, $m)) {
            return ['type' => 'binary', 'outer' => $m[1]];
        }
        if (preg_match('/^(\w+)\(x0\)$/', $formula, $m)) {
            return ['type' => 'unary', 'outer' => $m[1]];
        }
        if (preg_match('/^(\w+)\((\w+)\(x0,x1\)\)$/', $formula, $m)) {
            return ['type' => 'compose', 'outer' => $m[1], 'inner' => $m[2]];
        }
        if (preg_match('/^(\w+)\((\w+)\(x0\)\)$/', $formula, $m)) {
            return ['type' => 'compose_unary', 'outer' => $m[1], 'inner' => $m[2]];
        }

        return null;
    }

    private function applyFormula(array $atoms, array $row): ?float
    {
        switch ($atoms['type']) {
            case 'binary':
                if (count($row) < 2) return null;
                return AtomRegistry::apply($atoms['outer'], (float)$row[0], (float)$row[1]);
            case 'unary':
                return AtomRegistry::apply($atoms['outer'], (float)$row[0]);
            case 'compose':
                if (count($row) < 2) return null;
                $v1 = AtomRegistry::apply($atoms['inner'], (float)$row[0], (float)$row[1]);
                if ($v1 === null) return null;
                return AtomRegistry::apply($atoms['outer'], $v1);
            case 'compose_unary':
                $v1 = AtomRegistry::apply($atoms['inner'], (float)$row[0]);
                if ($v1 === null) return null;
                return AtomRegistry::apply($atoms['outer'], $v1);
        }
        return null;
    }
}
