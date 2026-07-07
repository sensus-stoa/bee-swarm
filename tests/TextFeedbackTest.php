<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * E1.4: Feedback loop — discovered text atoms become available
 *
 * @group disabled
 */
class TextFeedbackTest extends TestCase
{
    /** addDiscoveredTextAtom() регистрирует открытый compose-атом */
    public function testAddDiscoveredTextAtomRegisters(): void
    {
        $this->assertTrue(
            method_exists(AtomRegistry::class, 'addDiscoveredTextAtom'),
            'RED: addDiscoveredTextAtom() must exist to register discovered text atoms'
        );

        $composed = 'match_label(GI)';
        AtomRegistry::addDiscoveredTextAtom('match_label', 'GI');

        $atoms = AtomRegistry::all();
        $this->assertContains($composed, $atoms,
            'After addDiscoveredTextAtom, match_label(GI) must be in grammar');
        $this->assertTrue(AtomRegistry::isTextAtom($composed),
            'Discovered compose atom must be recognized as text atom');
    }
}
