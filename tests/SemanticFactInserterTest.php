<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\SemanticFactInserter;
use BeeSwarm\Infra\Database;

/**
 * Story D10 Phase 3: SemanticFactInserter — addSemanticFact() extracted from Forager
 */
class SemanticFactInserterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clean test data
        Database::get()->exec("DELETE FROM knowledge_graph WHERE subject IN ('Лондон','Париж','Берлин','это','123','он')");
    }

    /**
     * Valid triple → inserted into knowledge_graph with confidence=0.3
     */
    public function testInsertsNewTriple(): void
    {
        $inserter = new SemanticFactInserter();
        $inserter->insert('Лондон', 'is_a', 'город');

        $stmt = Database::get()->prepare(
            'SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate=? AND object=?'
        );
        $stmt->execute(['Лондон', 'is_a', 'город']);
        $confidence = $stmt->fetchColumn();

        $this->assertNotFalse($confidence, 'Triple must exist in knowledge_graph');
        $this->assertEqualsWithDelta(0.3, (float) $confidence, 0.0001, 'First insertion → confidence=0.3');
    }

    /**
     * Repeat insertion → confidence increases by 0.15
     */
    public function testBoostsConfidenceOnRepeat(): void
    {
        $inserter = new SemanticFactInserter();
        $inserter->insert('Париж', 'is_a', 'город');
        $inserter->insert('Париж', 'is_a', 'город');

        $stmt = Database::get()->prepare(
            'SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate=? AND object=?'
        );
        $stmt->execute(['Париж', 'is_a', 'город']);
        $confidence = (float) $stmt->fetchColumn();

        $this->assertEqualsWithDelta(0.45, $confidence, 0.0001, 'Second insertion → 0.3 + 0.15 ≈ 0.45');
    }

    /**
     * Confidence capped at 1.0
     */
    public function testCapsConfidenceAtOne(): void
    {
        $inserter = new SemanticFactInserter();
        for ($i = 0; $i < 10; $i++) {
            $inserter->insert('Берлин', 'is_a', 'город');
        }

        $stmt = Database::get()->prepare(
            'SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate=? AND object=?'
        );
        $stmt->execute(['Берлин', 'is_a', 'город']);
        $confidence = (float) $stmt->fetchColumn();

        $this->assertEqualsWithDelta(1.0, $confidence, 0.0001, 'Confidence must be capped at 1.0');
    }

    /**
     * Short words (<3 chars) are rejected
     */
    public function testRejectsShortWords(): void
    {
        $inserter = new SemanticFactInserter();
        $inserter->insert('он', 'is_a', 'кт');

        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM knowledge_graph WHERE subject=? AND predicate=?'
        );
        $stmt->execute(['он', 'is_a']);
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(0, $count, 'Short words must be rejected');
    }

    /**
     * Stop-words are rejected
     */
    public function testRejectsStopWords(): void
    {
        $inserter = new SemanticFactInserter();
        $inserter->insert('это', 'is_a', 'очень');

        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM knowledge_graph WHERE subject=? AND predicate=?'
        );
        $stmt->execute(['это', 'is_a']);
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(0, $count, 'Stop-words must be rejected');
    }

    /**
     * Numeric strings are rejected
     */
    public function testRejectsNumericStrings(): void
    {
        $inserter = new SemanticFactInserter();
        $inserter->insert('123', 'is_a', '45.6');

        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM knowledge_graph WHERE subject=?'
        );
        $stmt->execute(['123']);
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(0, $count, 'Numeric strings must be rejected');
    }
}
