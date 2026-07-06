<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Infra\Database;

/**
 * Story D9: KG population through accumulator
 */
class ForagerKgTest extends TestCase
{
    /** addSemanticFact() exists and inserts into KG */
    public function testAddSemanticFactInsertsIntoKg(): void
    {
        $f = new Forager();
        $db = Database::get();
        $before = (int) $db->query('SELECT COUNT(*) FROM knowledge_graph')->fetchColumn();

        $f->addSemanticFact('test_subj_' . uniqid(), 'is_a', 'test_obj_' . uniqid());

        $after = (int) $db->query('SELECT COUNT(*) FROM knowledge_graph')->fetchColumn();
        $this->assertGreaterThan($before, $after,
            'addSemanticFact() must insert into knowledge_graph');
    }
}
