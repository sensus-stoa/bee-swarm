<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use BeeSwarm\Database;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Ensure DB is initialized
        Database::get();
    }
}
