<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * E1.3: CV→0 over text atoms — discover text patterns from raw content
 */
class TextDiscoverTest extends TestCase
{
    /**
     * match_label извлекает структурированные данные из сырого текста
     */
    public function testMatchLabelExtractsFromContent(): void
    {
        $content = "GI: 7.2\nDQ: 6\nsleep: 5.5\nmood: 8\nenergy: 4";

        // match_label(content, 'GI') → [7.2]
        $result = AtomRegistry::applyTextAtom('match_label', $content, 'GI');
        $this->assertNotNull($result, 'match_label must extract value from text content');
        $this->assertEqualsWithDelta(
            7.2,
            $result,
            0.01,
            'match_label("GI") must extract 7.2 from "GI: 7.2"'
        );
    }

    /**
     * preg_match атом извлекает все пары label:value из текста
     */
    public function testPregMatchExtractsLabelValuePairs(): void
    {
        $content = "GI: 7.2\nDQ: 6\nsleep: 5.5";

        // preg_match(content, pattern) — pattern без делимитеров
        $result = AtomRegistry::applyTextAtom('preg_match', $content, '(\w+):\s+([\d.]+)');
        $this->assertIsArray($result, 'preg_match must return array of matches');
        $this->assertCount(3, $result, 'Must find 3 label:value pairs');
        $this->assertSame('GI', $result[0][0], 'First match label');
    }
}
