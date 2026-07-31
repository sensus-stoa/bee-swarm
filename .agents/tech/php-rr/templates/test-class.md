# PHPUnit Test Class Template

```php
<?php

declare(strict_types=1);

use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

class {ClassName}Test extends TestCase
{
    protected function setUp(): void
    {
        // Test DB isolation — in-memory SQLite (S1.10)
        $this->db = Database::get();
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
