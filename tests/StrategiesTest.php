<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Strategies;

/**
 * D10: Forager decomposition — Strategies extraction
 *
 * @group disabled
 */
class StrategiesTest extends TestCase
{
    public function testStrategiesClassExists(): void
    {
        $this->assertTrue(
            class_exists(Strategies::class),
            'RED: Strategies class must be extracted from Forager'
        );
    }

    public function testStrategiesHasSameMethods(): void
    {
        $s = new Strategies();
        $all = $s->all();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('preg_match_nums', $all, 'Must contain preg_match_nums');
        $this->assertArrayHasKey('preg_match_is_a', $all, 'Must contain preg_match_is_a');
    }
}
