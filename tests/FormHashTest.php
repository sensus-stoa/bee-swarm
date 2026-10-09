<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\FormHash;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * S2.2-TER (§3.2.2): form-hash = LawShape AFTER expansion of birth atoms.
 *
 * Contract (probe 3, 10.10):
 *  - expansion: recursive substitution of birth-word definitions to leaves
 *    (depth guard, bound tree re-substituted - nested words);
 *  - masking ON THE TREE (re-parse does not segment glued 'x1maxx2' tokens;
 *    regex masking of the rendered string gives a different canon);
 *  - for formulas without birth atoms FormHash == LawShape (same canon).
 */
class FormHashTest extends TestCase
{
    private string $atomSimple = 'BWformtest001';

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec("DELETE FROM grammar_ops WHERE source='birth' AND name LIKE 'BWformtest%'");
        // COUNT-sentinel: delete+insert keeps the count -> stale birthOps list
        // (resetCaches is the single static-reset point, ExpressionEvaluator:128).
        ExpressionEvaluator::resetCaches();
        FormHash::clearCache();
        $st = $db->prepare(
            'INSERT INTO grammar_ops (name, source, definition, status) VALUES (?,?,?,?)'
        );
        // definition (x0-x1): atom applied to two slots = difference
        $st->execute([$this->atomSimple, 'birth', '(x0−x1)', 'active']);
        ExpressionEvaluator::resetCaches();
        FormHash::clearCache();
    }

    protected function tearDown(): void
    {
        Database::get()->exec("DELETE FROM grammar_ops WHERE source='birth' AND name LIKE 'BWformtest%'");
        ExpressionEvaluator::resetCaches();
        FormHash::clearCache();
        parent::tearDown();
    }

    /**
     * T4a: atom formula and its raw expansion produce ONE form hash.
     */
    public function testAtomExpansionEqualsRawShape(): void
    {
        $atomF = "((x1{$this->atomSimple}x2)×x0)";
        $rawF = '((x1−x2)×x0)';
        $this->assertSame(FormHash::of($rawF), FormHash::of($atomF));
    }

    /**
     * T4b: nested words (definition contains another word) expand recursively.
     */
    public function testNestedAtomExpansion(): void
    {
        $atom2 = 'BWformtest002';
        $st = Database::get()->prepare(
            'INSERT INTO grammar_ops (name, source, definition, status) VALUES (?,?,?,?)'
        );
        // atom2 = (atom1 applied to x0,x1) multiplied by x0
        $st->execute([$atom2, 'birth', "((x0{$this->atomSimple}x1)×x0)", 'active']);
        ExpressionEvaluator::resetCaches();
        FormHash::clearCache();
        $nested = "(x5{$atom2}x7)";
        $raw = '((x5−x7)×x5)';
        $this->assertSame(FormHash::of($raw), FormHash::of($nested));
    }

    /**
     * T4c: glued tokens vanish - every terminal of an expanded word is
     * masked; the hash contains no raw column reference inside a word.
     */
    public function testGluedTokensMasked(): void
    {
        $atomMM = 'BWformtest003';
        $st = Database::get()->prepare(
            'INSERT INTO grammar_ops (name, source, definition, status) VALUES (?,?,?,?)'
        );
        // max/min-shaped difference (compressor-native definition shape)
        $st->execute([$atomMM, 'birth', '((x0maxx1)−(x0minx1))', 'active']);
        ExpressionEvaluator::resetCaches();
        FormHash::clearCache();
        $hash = FormHash::of("(x1{$atomMM}x2)");
        $this->assertStringNotContainsString('x1max', $hash);
        $this->assertStringNotContainsString((string) $atomMM, $hash);
        // structure preserved: max and min nodes masked to (*max*) / (*min*)
        // (render convention: '(' . l . op . r . ')' - outer + node parens)
        $this->assertSame('((*max*)−(*min*))', $hash);
    }

    /**
     * T4d: formulas without birth atoms - FormHash identical to LawShape
     * (single canon, no third normalizer).
     */
    public function testPlainFormulasMatchLawShape(): void
    {
        foreach (['(x0+K1)sq', '((x0×x0²))', '(x0−K2)', '(((x0+x1)×K1)/x2)'] as $f) {
            $this->assertSame(LawShape::of($f), FormHash::of($f), "formula: {$f}");
        }
    }
}
