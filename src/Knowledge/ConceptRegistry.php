<?php

declare(strict_types=1);

namespace BeeSwarm\Knowledge;

use BeeSwarm\Infra\Database;

/**
 * ConceptRegistry: отображение хеш → концепт для семантических атомов.
 * Позволяет is_a(hash_s, hash_o) находить факты в knowledge_graph.
 */
class ConceptRegistry
{
    private static array $hashToConcept = [];

    /**
     * Регистрирует концепт и возвращает его числовой идентификатор.
     */
    public static function register(string $concept): float
    {
        $hash = (float)(abs(crc32($concept)) % 1000) / 1000.0; // 0.000–0.999
        self::$hashToConcept[(string)$hash] = $concept;
        return $hash;
    }

    /**
     * Получить имя концепта по хешу (для отладки).
     */
    public static function concept(float $hash): ?string
    {
        return self::$hashToConcept[(string)$hash] ?? null;
    }

    /**
     * Проверяет факт: есть ли subject —predicate→ object в knowledge_graph.
     */
    public static function checkFact(float $subjectHash, string $predicate, float $objectHash): float
    {
        $s = self::$hashToConcept[(string)$subjectHash] ?? null;
        $o = self::$hashToConcept[(string)$objectHash] ?? null;

        if ($s === null || $o === null) {
            return 0.0;  // неизвестный концепт → факта нет
        }

        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT MAX(confidence) FROM knowledge_graph WHERE subject = ? AND predicate = ? AND object = ?"
        );
        $stmt->execute([$s, $predicate, $o]);
        $result = $stmt->fetchColumn();

        return $result !== false ? (float)$result : 0.0;
    }

    /**
     * Очистка реестра (для тестов).
     */
    public static function clear(): void
    {
        self::$hashToConcept = [];
    }
}
