<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * E1.1: Text atoms in grammar
 */
class TextAtomsTest extends TestCase
{
    public function testIsTextAtomExists(): void
    {
        $this->assertTrue(
            method_exists(AtomRegistry::class, 'isTextAtom'),
            'AtomRegistry must have isTextAtom() to distinguish text from math atoms'
        );
    }

    public function testPregMatchIsText(): void
    {
        $this->assertTrue(
            AtomRegistry::isTextAtom('preg_match'),
            'preg_match must be recognized as text atom'
        );
    }

    public function testAddIsNotText(): void
    {
        $this->assertFalse(
            AtomRegistry::isTextAtom('add'),
            'add must NOT be recognized as text atom'
        );
    }

    /**
     * Seed text atoms exist in grammar
     */
    public function testSeedTextAtomsInAll(): void
    {
        $atoms = AtomRegistry::all();
        $this->assertContains('preg_match', $atoms, 'preg_match must be in grammar');
        $this->assertContains('match_label', $atoms, 'match_label must be in grammar');
        $this->assertContains('extract_col', $atoms, 'extract_col must be in grammar');
    }
}
