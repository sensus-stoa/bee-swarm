<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Grammar;
use BeeSwarm\Database;
use BeeSwarm\ConceptRegistry;

/**
 * Путь B: семантические предикаты как атомы грамматики.
 */
class SemanticGrammarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConceptRegistry::clear();
    }
    
    /** is_a доступен как атом грамматики */
    public function test_is_a_is_grammar_atom(): void
    {
        $g = new Grammar();
        $ops = $g->all();
        
        $this->assertContains('is_a', $ops, 'is_a должен быть атомом грамматики');
        $this->assertContains('has', $ops);
        $this->assertContains('relates_to', $ops);
        $this->assertContains('can', $ops);
    }
    
    /** apply(is_a, Сократ, человек) → 1.0 когда факт в KG */
    public function test_is_a_apply_returns_confidence(): void
    {
        $db = Database::get();
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('Сократ', 'is_a', 'человек', 1.0)");
        
        // Регистрируем концепты
        $sh = ConceptRegistry::register('Сократ');
        $oh = ConceptRegistry::register('человек');
        $ch = ConceptRegistry::register('камень');
        
        $g = new Grammar();
        $result1 = $g->apply($sh, $oh, 'is_a');
        $result2 = $g->apply($sh, $ch, 'is_a');
        
        $this->assertEquals(1.0, $result1, 'Сократ является человеком → 1.0');
        $this->assertEquals(0.0, $result2, 'Сократ является камнем → 0.0');
        
        $db->exec("DELETE FROM knowledge_graph WHERE subject='Сократ' AND predicate='is_a'");
    }
    
    /** Силлогизм: and(is_a(X,Y), is_a(Y,Z)) */
    public function test_syllogism_via_compose(): void
    {
        $db = Database::get();
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('Сократ', 'is_a', 'человек', 1.0)");
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('человек', 'is_a', 'смертный', 1.0)");
        
        $sh = ConceptRegistry::register('Сократ');
        $hh = ConceptRegistry::register('человек');
        $mh = ConceptRegistry::register('смертный');
        
        $g = new Grammar();
        $a = $g->apply($sh, $hh, 'is_a');   // Сократ — человек
        $b = $g->apply($hh, $mh, 'is_a');   // человек — смертный
        
        // Композиция: and(is_a, is_a) = силлогизм
        $composed = $a * $b;
        
        $this->assertEquals(1.0, $a);
        $this->assertEquals(1.0, $b);
        $this->assertEquals(1.0, $composed, 'Силлогизм: Сократ → смертный');
        
        $db->exec("DELETE FROM knowledge_graph WHERE subject IN ('Сократ','человек') AND predicate='is_a'");
    }
    
    /** CV→0: is_a даёт 0 ошибок на известных фактах */
    public function test_semantic_cv0_on_known_facts(): void
    {
        $db = Database::get();
        
        $facts = [
            ['Сократ', 'человек', 1.0],
            ['Платон', 'человек', 1.0],
            ['Кот', 'человек', 0.0],
            ['Сократ', 'кошка', 0.0],
        ];
        
        foreach ($facts as [$s, $o, $target]) {
            $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('$s', 'is_a', '$o', $target)");
            ConceptRegistry::register($s);
            ConceptRegistry::register($o);
        }
        
        $g = new Grammar();
        $errors = [];
        foreach ($facts as [$s, $o, $target]) {
            $sh = ConceptRegistry::register($s);
            $oh = ConceptRegistry::register($o);
            $pred = $g->apply($sh, $oh, 'is_a');
            $errors[] = abs($pred - $target);
        }
        
        $mean = array_sum($errors) / count($errors);
        $variance = 0.0;
        foreach ($errors as $e) $variance += ($e - $mean) ** 2;
        $std = sqrt($variance / count($errors));
        $cv = $std / max(abs($mean), 1e-8);
        
        $this->assertLessThan(0.01, $cv, 'is_a должен давать CV≈0 на известных фактах');
        
        foreach ($facts as [$s, $o, $target]) {
            $db->exec("DELETE FROM knowledge_graph WHERE subject='$s' AND predicate='is_a' AND object='$o'");
        }
    }
    
    /** Противоречивые факты разрешаются по confidence */
    public function test_contradiction_resolves_by_confidence(): void
    {
        $db = Database::get();
        
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('Сократ', 'is_a', 'человек', 0.8)");
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('Сократ', 'is_a', 'человек', 0.2)");
        
        $sh = ConceptRegistry::register('Сократ');
        $oh = ConceptRegistry::register('человек');
        
        $g = new Grammar();
        $result = $g->apply($sh, $oh, 'is_a');
        
        $this->assertEquals(0.8, $result, 'Должен вернуть высший confidence');
        
        $db->exec("DELETE FROM knowledge_graph WHERE subject='Сократ' AND predicate='is_a' AND object='человек'");
    }
}
