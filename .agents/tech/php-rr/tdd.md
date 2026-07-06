# TDD Rules — PHP/RoadRunner

## Red-Green-Refactor Cycle

Never write production code without a failing test first.

### RED Phase

1. **Write test** — one test class, one or more test methods
2. **Predict failure** — exact error type, message, or assertion text
3. **Run → verify RED** — must fail as predicted. If it passes or fails differently → fix prediction or test, re-run
4. **Prediction match check** — field by field: Type / Message / Status. All YES → proceed
5. **Disable test** — mark with `@group disabled` or skip marker
6. **Commit** — `red: {description} (Story N)`

### GREEN Phase

1. **Implement MINIMAL code** — only what's needed for the test
2. **Remove disable marker** — this is the ONLY test file change allowed
3. **Run test → verify GREEN**
4. **Run FULL suite** — vendor/bin/phpunit tests/ — no regressions
5. **Commit** — `green: {description} (Story N)`

### REFACTOR Phase

1. **Structural improvements only** — no behavior changes, no new features
2. **Run full suite after EACH change**
3. **Commit** — `refactor: {description} (Story N)`

## Phase Transitions

- RED → GREEN: only after test fails as predicted
- GREEN → REFACTOR: only after full suite GREEN
- REFACTOR → next RED: only after full suite GREEN

## Test File Rules

- Tests are READ-ONLY in GREEN phase (except removing disable marker)
- Never change assertion expected values — fix production code instead
- Never delete assertions to meet file size limits

## Daemon Rule (CRITICAL)

**Any change to agenda.php MUST have a PHPUnit test BEFORE the code change.**

This is non-negotiable. Breaking the daemon silently causes 89% CPU, lost forager tasks, DB pollution.
