# PHPUnit Implementation Template

```php
<?php

declare(strict_types=1);

class {ClassName}
{
    // MINIMAL implementation — only what the test requires
    // No extra methods, no "while I'm here" improvements

    public function {methodName}({params}): {returnType}
    {
        // Implementation that makes the test pass
    }
}
```

## Implementation Rules

1. **MINIMAL** — only code needed for the current test to pass
2. **No features beyond test scope**
3. **Wire into agenda.php** — but only AFTER tests pass and daemon restart
4. **Evolve don't add** — can this emerge from compose of existing components?
