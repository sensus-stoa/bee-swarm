# PHPUnit Test Class Template

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class {ClassName}Test extends TestCase
{
    private ?PDO $db = null;

    protected function setUp(): void
    {
        // Test DB isolation — use test_swarm.db
        $dbPath = __DIR__ . '/../data/test_swarm.db';
        $this->db = new PDO("sqlite:$dbPath");
        $this->db->exec('DELETE FROM known_laws WHERE name LIKE "test_%"');
    }

    public function test_{scenario}(): void
    {
        // Arrange

        // Act

        // Assert
        $this->assertSame($expected, $actual);
    }
}
```

## Conventions

- One test class per source class
- Test method names: `test_{what}_{expected_outcome}`
- Use `assertSame` (strict) over `assertEquals` (loose)
- Use `assertCount` over `assertSame(count(...), ...)`
- Clean test data in `setUp()`, not `tearDown()`
- NEVER use `@depends` — each test is independent
