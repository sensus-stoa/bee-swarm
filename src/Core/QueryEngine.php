<?php
declare(strict_types=1);

namespace BeeSwarm\Core;

use BeeSwarm\Infra\Database;

/**
 * QueryEngine — детерминированные read-only запросы к БД системы (Theo-Conjecture T3).
 *
 * Никакого ML, никакой аппроксимации. Чистый SQL над собственными данными.
 */
class QueryEngine
{
    private const WRITE_KEYWORDS = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'REPLACE'];

    /**
     * Выполнить read-only SQL-запрос.
     *
     * @throws \RuntimeException если запрос содержит write-операции
     */
    public function query(string $sql, array $params = []): array
    {
        $upper = strtoupper($sql);
        foreach (self::WRITE_KEYWORDS as $kw) {
            // Word-boundary check: keyword must be a standalone word, not substring
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $upper)) {
                throw new \RuntimeException("QueryEngine: write operation '{$kw}' blocked");
            }
        }

        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Законы для указанного домена.
     */
    public function lawsByDomain(string $domain): array
    {
        return $this->query(
            'SELECT name, formula, cv, domain FROM laws WHERE domain = ? ORDER BY name',
            [$domain]
        );
    }

    /**
     * Топ-N атомов грамматики по частоте использования.
     */
    public function topAtoms(int $n = 10): array
    {
        return $this->query(
            'SELECT name, COUNT(*) as cnt FROM grammar_ops GROUP BY name ORDER BY cnt DESC LIMIT ?',
            [$n]
        );
    }

    /**
     * Агрегированное состояние системы.
     */
    public function systemHealth(): array
    {
        $totalLaws = $this->query('SELECT COUNT(*) as cnt FROM laws')[0]['cnt'] ?? 0;
        $domains = $this->query('SELECT COUNT(DISTINCT domain) as cnt FROM laws')[0]['cnt'] ?? 0;

        return [
            'total_laws' => (int) $totalLaws,
            'active_domains' => (int) $domains,
        ];
    }
}
