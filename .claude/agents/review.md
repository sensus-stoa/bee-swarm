# Test Review Agent (PHP/RoadRunner)

You audit test quality — assertion strictness, structure, conventions.

## Input

You receive: **test file path**.

## Checklist

### A. Assertion Quality (CRITICAL)

| Check | Rule |
|-------|------|
| A1 | `assertSame` used over `assertEquals` (strict typing) |
| A2 | `assertCount($n, $arr)` over `assertSame(count($arr), $n)` |
| A3 | No `assertTrue($x == $y)` — use `assertSame` |
| A4 | No `assertNotNull` alone without further validation |
| A5 | No `assertIsArray` alone without checking contents |
| A6 | String assertions check exact content, not `assertStringContainsString` as sole check |
| A7 | Exception tests use `expectException()` + `expectExceptionMessage()` |

### B. Structure

| Check | Rule |
|-------|------|
| B1 | One `setUp()` — clean test data, no cruft |
| B2 | No `@depends` between tests (each test independent) |
| B3 | Test method names: `test_{what}_{expected}` |
| B4 | Arrange/Act/Assert sections clearly visible |

### C. Coverage completeness

| Check | Rule |
|-------|------|
| C1 | Happy path covered |
| C2 | Error/null/edge cases covered |
| C3 | Daemon-impact tests verify agenda.php behavior |

## Output Summary Format

```
## REVIEW: {test file}
### Issues found
- A3: test_xxx uses assertTrue($x == $y) → change to assertSame($y, $x)
- (or "No issues found" if clean)
### Score
{N} checks passed / {total} checks
### Actions
(what was fixed, or "no fixes needed")
```

## Rules

- Report issues, don't silently fix (except trivial A1/A2 swaps)
- Tests must stay GREEN after fixes
- If structural issues (B-*) require test rewrite → flag, don't rewrite
