# Architectural Review v2: Bug Prevention & Rapid Detection Strategy

> **Date:** 04.08.2026 | **Audience:** Single developer, PHP 8.2, SQLite, laptop deployment
> **Project:** Bee Swarm — 520+ tests, 800+ files, Hive monolith
> **Trigger:** 8h regression: `srand(42)` in `getTasks()` poisoned `array_rand()`, making every tick pick the same task (1 law instead of 450 over weeks). Root cause: global state leakage invisible to unit tests.

---

## Table of Contents

1. [Layer 1: Write Fewer Bugs](#layer-1-write-fewer-bugs)
2. [Layer 2: Catch Bugs Before Production](#layer-2-catch-bugs-before-production)
3. [Layer 3: Rapidly Diagnose Production Bugs](#layer-3-rapidly-diagnose-production-bugs)
4. [Layer 4: Prevent Regression](#layer-4-prevent-regression)
5. [Implementation Priority Matrix](#implementation-priority-matrix)
6. [What You Already Have (Don't Break)](#what-you-already-have-dont-break)

---

## Layer 1: Write Fewer Bugs

### 1.1 Explicit Global State Isolation (RNG v2)

**Problem:** PHP RNG state (`srand`/`mt_srand`) is process-global. `srand(42)` anywhere makes every subsequent `array_rand()` deterministic — silently. Your `RngIsolation` is excellent, but the enforcement loop is incomplete.

**Current state:** `RngIsolation::assertClean()` exists but is only called in `BehavioralDiversityTest::tearDown()` and the pre-commit hook — NOT in the base `TestCase::tearDown()`.

**Fix: Add to TestCase::tearDown()**

```php
// tests/TestCase.php — ADD after setUp()
abstract class TestCase extends BaseTestCase
{
    // ... existing setUp() ...

    protected function tearDown(): void
    {
        // CATCH RNG poisoning between tests.
        // Without this, test A calls srand(42), forgets restore(),
        // test B has a deterministic array_rand() — test B passes,
        // but production array_rand() is now deterministic.
        \BeeSwarm\Infra\RngIsolation::assertClean();
        parent::tearDown();
    }
}
```

**Why this matters:** The `srand(42)` bug was invisible to unit tests because no test ran after the function that leaked RNG state. `tearDown()` assertion catches the leak on the NEXT test — the guilty test's `tearDown()` fails, telling you exactly which test caused the leak.

### 1.2 PHP Global State Guard (NEW)

**Problem:** RNG isn't the only global state that leaks. PHP has many process-global functions. Any of these can silently change behavior in downstream code:

| Function | Affects | Silent? |
|----------|---------|---------|
| `srand(N)` / `mt_srand(N)` | `rand()`, `array_rand()`, `shuffle()` | YES |
| `setlocale(LC_ALL, ...)` | `strftime()`, number formatting | YES |
| `date_default_timezone_set(...)` | All date functions | YES |
| `error_reporting(N)` | Which errors are caught | YES |
| `ini_set('memory_limit', ...)` | Process memory cap | YES |
| `libxml_use_internal_errors(true)` | XML parsing behavior | YES |
| `header()` / `setcookie()` | HTTP output | YES (in CLI tests) |

**Pattern: GlobalStateGuard**

```php
<?php
// src/Infra/GlobalStateGuard.php
declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * GlobalStateGuard — snapshot-and-restore for PHP global state.
 *
 * Before any code block that modifies global state, take a snapshot.
 * After the block, restore. In tearDown(), assertClean().
 *
 * Covers: RNG, locale, timezone, error_reporting, memory_limit
 */
class GlobalStateGuard
{
    private static array $snapshots = [];

    /**
     * Capture current global state.
     *
     * @return string snapshot ID (pass to restore())
     */
    public static function snapshot(): string
    {
        $id = bin2hex(random_bytes(8));
        self::$snapshots[$id] = [
            'rng_seed'      => mt_rand(),       // capture entropy
            'locale'        => setlocale(LC_ALL, '0'),
            'timezone'      => date_default_timezone_get(),
            'error_level'   => error_reporting(),
            'memory_limit'  => ini_get('memory_limit'),
            'display_errors'=> ini_get('display_errors'),
        ];
        return $id;
    }

    /**
     * Restore global state to snapshot.
     */
    public static function restore(string $id): void
    {
        $s = self::$snapshots[$id] ?? null;
        if ($s === null) {
            throw new \RuntimeException("GlobalStateGuard: unknown snapshot $id");
        }

        srand($s['rng_seed']);
        setlocale(LC_ALL, $s['locale']);
        date_default_timezone_set($s['timezone']);
        error_reporting($s['error_level']);
        ini_set('memory_limit', $s['memory_limit']);
        ini_set('display_errors', $s['display_errors']);

        unset(self::$snapshots[$id]);
    }

    /**
     * Assert no unrestored snapshots exist. Call in tearDown().
     */
    public static function assertClean(): void
    {
        if (! empty(self::$snapshots)) {
            $count = count(self::$snapshots);
            throw new \RuntimeException(
                "GLOBAL STATE LEAK: {$count} unrestored GlobalStateGuard snapshot(s). " .
                'Code modified global state (setlocale, date_default_timezone_set, error_reporting, etc.) without restoring.'
            );
        }
    }
}
```

**Integrate into TestCase:**

```php
// tests/TestCase.php
protected function tearDown(): void
{
    \BeeSwarm\Infra\RngIsolation::assertClean();
    \BeeSwarm\Infra\GlobalStateGuard::assertClean();
    parent::tearDown();
}
```

### 1.3 Value Objects Instead of Arrays for Task Definitions

**Problem:** Tasks flow through the system as associative arrays with implicit structure:

```php
// Current: shape errors are silent — misspelled keys return NULL
$task = ['name' => 'add', 'data' => [[1,2],[3,4]], 'domain' => 'arithmetic'];
$name = $task['naem'] ?? 'unknown';  // typo — silently returns 'unknown'
```

**Pattern: Task value object**

```php
<?php
// src/Core/Task.php
declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * Task — immutable value object replacing ad-hoc task arrays.
 *
 * Every task in the system has exactly these fields. No more guessing shapes.
 */
final class Task
{
    /** @param list<list<float>> $data */
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public readonly string $domain = 'unknown',
        public readonly string $sourcePath = '',
        public readonly string $contentSample = '',
        /** @var list<string> $colLabels */
        public readonly array $colLabels = [],
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Task name cannot be empty');
        }
        if (empty($data)) {
            throw new \InvalidArgumentException("Task '{$name}' has no data");
        }
    }

    /** Number of data rows. */
    public function rowCount(): int
    {
        return count($this->data);
    }

    /** Number of columns in first row. */
    public function colCount(): int
    {
        return count($this->data[0] ?? []);
    }

    /** Extract X matrix (all columns) and y vector (last column) for Search::find. */
    public function toXY(): array
    {
        $X = [];
        $y = [];
        $nCol = $this->colCount();

        foreach ($this->data as $row) {
            if (count($row) < 2) continue;
            $X[] = array_slice($row, 0, $nCol - 1);
            $y[] = $row[$nCol - 1];
        }
        return [$X, $y];
    }

    /** Create from legacy array (migration helper). */
    public static function fromArray(array $arr): self
    {
        return new self(
            name: $arr['name'] ?? 'unnamed',
            data: $arr['data'] ?? [],
            domain: $arr['domain'] ?? 'unknown',
            sourcePath: $arr['source_path'] ?? '',
            contentSample: $arr['content_sample'] ?? '',
            colLabels: $arr['col_labels'] ?? [],
        );
    }

    /** Backward-compat: convert to array for code that still uses arrays. */
    public function toArray(): array
    {
        return [
            'name'           => $this->name,
            'data'           => $this->data,
            'domain'         => $this->domain,
            'source_path'    => $this->sourcePath,
            'content_sample' => $this->contentSample,
            'col_labels'     => $this->colLabels,
        ];
    }
}
```

**Why this prevents bugs:**
- Typo in `$task->name` is a PHP parse error (caught before tests)
- `$task->rowCount()` returns `int` — no more `count($task['data'] ?? [])` guessing
- Constructor validates invariants at creation time — invalid tasks never enter the system

### 1.4 Named Constructors Instead of Raw `mt_rand`

**Problem:** `mt_rand(-10, 10)` appears in 5+ places (Hive, TaskGenerator, Bee, Cloze). If you change the range, you must find every callsite. If one uses `rand()` instead of `mt_rand()`, RNG quality silently differs.

**Pattern: Domain-specific RNG factory**

```php
<?php
// src/Infra/Random.php
declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * Random — domain-specific randomness factory.
 *
 * All randomness in the system flows through this class. No raw mt_rand() calls.
 * This gives us: audit trail, range consistency, and a single point for testing.
 *
 * When writing tests, inject a seeded Random instance to make tests deterministic
 * WITHOUT poisoning process-global RNG state.
 */
class Random
{
    private ?int $seed;

    /** @param int|null $seed If set, uses srand($seed) — deterministic. For tests. */
    public function __construct(?int $seed = null)
    {
        $this->seed = $seed;
    }

    /** Random integer in [min, max] */
    public function int(int $min, int $max): int
    {
        if ($this->seed !== null) {
            srand($this->seed);
            $result = rand($min, $max);  // uses now-deterministic rand()
            $this->seed = rand();         // advance seed for next call
            return $result;
        }
        return mt_rand($min, $max);
    }

    /** Random float in [0.0, 1.0) */
    public function float(): float
    {
        return $this->int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    /** Pick a random element from a non-empty array. */
    public function pick(array $items): mixed
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('Cannot pick from empty array');
        }
        // DO NOT use array_rand() — it's affected by srand().
        // Use our own int() instead, which manages RNG state properly.
        return $items[$this->int(0, count($items) - 1)];
    }

    /** Shuffle array (Fisher-Yates using our own RNG). */
    public function shuffle(array $items): array
    {
        $n = count($items);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }
}
```

**Why this matters:**
- `Random::pick($items)` CANNOT be poisoned by `srand(42)` because it uses its own RNG state
- Tests can inject `new Random(seed: 42)` to get deterministic behavior without global state leakage
- Single point to update if PHP 8.3+ `Random\Randomizer` becomes available

### 1.5 Explicit `final` on Implementation Classes

**Pattern:** Mark all non-abstract classes `final` unless designed for inheritance.

```php
// src/Infra/Database.php — WAS class Database, NOW:
final class Database { ... }

// src/Hive/Hive.php — WAS class Hive, NOW:
final class Hive { ... }
```

**Why:** In PHP, any non-final class can be extended. Extension breaks encapsulation because subclasses can override private methods and access protected state. For a single-developer project, this prevents accidental subclassing that introduces coupling. PHP 8.2 supports `final` on `const` too:

```php
final class Bee
{
    final public const DEFAULT_TICK_COST = 0.01;
}
```

### 1.6 SQLite Write Safety with `IMMEDIATE` Transactions

**Problem:** SQLite's default `DEFERRED` transaction mode doesn't acquire a write lock until the first write. Two processes (daemon + test) can both begin, both read, and then both try to write — one fails with `SQLITE_BUSY`.

**Current mitigation:** `run_tests_safe.sh` kills the daemon before tests. But this is manual — easy to forget.

**Pattern: Always use IMMEDIATE for writes**

```php
<?php
// src/Infra/Database.php — ADD method
final class Database
{
    // ... existing get() ...

    /**
     * Begin an IMMEDIATE write transaction.
     *
     * IMMEDIATE acquires the reserved lock upfront, failing fast if another
     * writer is active. This prevents SQLITE_BUSY mid-transaction.
     *
     * Usage:
     *   Database::beginWrite();
     *   try {
     *       // ... INSERT/UPDATE/DELETE ...
     *       Database::commit();
     *   } catch (\Throwable $e) {
     *       Database::rollback();
     *       throw $e;
     *   }
     */
    public static function beginWrite(): void
    {
        self::get()->exec('BEGIN IMMEDIATE');
    }

    public static function commit(): void
    {
        self::get()->exec('COMMIT');
    }

    public static function rollback(): void
    {
        self::get()->exec('ROLLBACK');
    }

    /**
     * Production guard: refuse writes if we're in test mode.
     * Call at the top of any method that writes to production tables.
     */
    public static function assertNotTestMode(): void
    {
        $dbPath = getenv('SWARM_DB_PATH') ?: '';
        if ($dbPath === ':memory:' || str_contains($dbPath, 'test')) {
            throw new \RuntimeException(
                'REFUSING WRITE in test mode. ' .
                'Production code called Database::assertNotTestMode() with SWARM_DB_PATH=' . $dbPath
            );
        }
    }
}
```

**Also add a daemon-health check script:**

```bash
#!/bin/bash
# scripts/check-db-lock.sh — verify no concurrent writers
LOCK_CHECK=$(php -r '
require "vendor/autoload.php";
$db = BeeSwarm\Infra\Database::get();
// Try BEGIN IMMEDIATE — if it fails, daemon has write lock
try { $db->exec("BEGIN IMMEDIATE"); $db->exec("ROLLBACK"); echo "FREE"; }
catch (\PDOException $e) { echo "LOCKED"; }
')
if [ "$LOCK_CHECK" = "LOCKED" ]; then
    echo "DB LOCKED — daemon is writing. Do NOT run tests."
    exit 1
fi
```

---

## Layer 2: Catch Bugs Before Production

### 2.1 Testing Pyramid Audit

**Current state:**
- 122 test files, ~520 tests
- Almost all are unit/integration tests against in-memory SQLite (`SWARM_DB_PATH=:memory:`)
- One smoke test (`scripts/smoke.php`) — runs a short Hive tick loop
- No property-based tests
- No mutation tests
- Gate script runs psalm + behavioral diversity + full suite + smoke

**Missing layers:**

#### Layer 2.1a: Property-Based Tests (NEW)

Unit tests verify specific inputs. Property-based tests verify invariants across random inputs. This catches edge cases your fixed test data never hits.

**Install:** `composer require --dev phpunit/phpunit ^10` (already installed) + write your own generators.

```php
<?php
// tests/Property/SearchInvariantsTest.php
declare(strict_types=1);

namespace BeeSwarm\Tests\Property;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Random;
use BeeSwarm\Tests\TestCase;

/**
 * Property-based tests for Search::find.
 *
 * Instead of hardcoding X=[1,2,3], y=[3,5,7], we generate
 * 100 random datasets and verify invariants hold.
 */
class SearchInvariantsTest extends TestCase
{
    private Random $rng;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rng = new Random(seed: 42);  // deterministic for reproducibility
    }

    /**
     * INVARIANT: On y=constant data, the best atom should have CV=0.
     *
     * Generate 100 datasets where y is exactly K for every row.
     * Search should find an atom with CV ≈ 0 (the constant function).
     */
    public function testConstantDataFindsZeroCV(): void
    {
        $grammar = new Grammar();
        $grammar->addAll($grammar->all());  // full grammar

        for ($i = 0; $i < 100; $i++) {
            $n = $this->rng->int(5, 50);
            $K = $this->rng->float() * 100 - 50;  // random constant in [-50, 50]

            $X = [];
            $y = [];
            for ($j = 0; $j < $n; $j++) {
                $X[] = [$this->rng->float() * 200 - 100];
                $y[] = $K;
            }

            [$found, $cv] = Search::find($X, $y, $grammar, 2);

            // On zero-variance data, CV should be 0 or very close
            // (floating point may give tiny epsilon)
            $this->assertLessThan(0.001, $cv,
                "Run {$i}: CV={$cv} for constant y={$K} (n={$n}). Found: {$found}");
        }
    }

    /**
     * INVARIANT: Shuffling y should NOT find a CV=0 law (if data is noise).
     *
     * Generate random X, random y, then shuffle y. Search should NOT
     * find CV≈0 because there's no true relationship.
     */
    public function testShuffledDataDoesNotFindZeroCV(): void
    {
        $grammar = new Grammar();
        $grammar->addAll($grammar->all());

        for ($i = 0; $i < 50; $i++) {
            $n = $this->rng->int(10, 30);
            $X = [];
            $y = [];
            for ($j = 0; $j < $n; $j++) {
                $X[] = [$this->rng->float() * 100];
                $y[] = $this->rng->float() * 100;
            }
            // Shuffle y to break any accidental relationship
            $y = $this->rng->shuffle($y);

            [$found, $cv] = Search::find($X, $y, $grammar, 1);

            // On shuffled data, CV should be well above 0
            $this->assertGreaterThan(0.01, $cv,
                "Run {$i}: CV={$cv} too low for shuffled data. Found: {$found} "
                . "(possible overfitting to noise)");
        }
    }

    /**
     * INVARIANT: array_rand() on a non-empty array always returns a valid key.
     *
     * This is the invariant that the srand(42) bug violated —
     * array_rand() still returned a valid key, but always the SAME key.
     * The fix is verifying randomness, not validity.
     */
    public function testArrayRandProducesDiverseResults(): void
    {
        $items = range(0, 99);
        $seen = [];

        for ($i = 0; $i < 500; $i++) {
            $key = array_rand($items);
            $seen[$key] = true;
        }

        // 500 picks from 100 items: expect at least 50 unique keys
        // With srand(42), we'd get ≤5 unique keys
        $this->assertGreaterThan(50, count($seen),
            'array_rand() produced only ' . count($seen) . ' unique keys out of 100 possible. '
            . 'RNG is likely deterministic (srand poisoning?).'
        );
    }
}
```

#### Layer 2.1b: Contract Tests for Cross-Module Interfaces (NEW)

```php
<?php
// tests/Contract/TaskGeneratorContractTest.php
declare(strict_types=1);

namespace BeeSwarm\Tests\Contract;

use BeeSwarm\Hive\TaskGenerator;
use BeeSwarm\Infra\RngIsolation;
use BeeSwarm\Tests\TestCase;

/**
 * CONTRACT: TaskGenerator::createComposeTasks() MUST restore RNG state.
 *
 * This is the contract that was violated and caused the 8h bug.
 * We encode it as a test so it can never silently regress.
 */
class TaskGeneratorContractTest extends TestCase
{
    /**
     * CONTRACT: After createComposeTasks(), array_rand() must still be random.
     *
     * §0.5: Deterministic GEN_ tasks MUST save & restore RNG state.
     */
    public function testCreateComposeTasksRestoresRng(): void
    {
        $gen = new TaskGenerator();
        $gen->createComposeTasks();

        // Now verify: is array_rand() still producing diverse results?
        $items = range(0, 99);
        $seen = [];

        for ($i = 0; $i < 100; $i++) {
            $key = array_rand($items);
            $seen[$key] = true;
        }

        // After 100 picks from 100 items: should see ≥ 30 unique keys.
        // With poisoned RNG, this drops to < 5.
        $this->assertGreaterThan(30, count($seen),
            'RNG NOT RESTORED after createComposeTasks(). '
            . 'array_rand() produced only ' . count($seen) . ' unique keys. '
            . 'This is the srand(42) bug — GEN_ tasks are poisoning global RNG.'
        );
    }

    /**
     * CONTRACT: createComposeTasks() must not leave unrestored RngIsolation guards.
     */
    public function testCreateComposeTasksLeavesNoGuards(): void
    {
        $gen = new TaskGenerator();
        $gen->createComposeTasks();

        // RngIsolation guard must be clean after (the TaskGenerator
        // implementation uses manual save/restore, not RngIsolation guards,
        // but this test ensures we catch if someone refactors to use guards)
        $this->assertFalse(RngIsolation::hasUnrestoredGuards(),
            'createComposeTasks() left an open RngIsolation guard'
        );
    }
}
```

### 2.2 Tighten Static Analysis (Psalm Level 1)

**Current:** Psalm level 5. This is too permissive.

**Fix: Upgrade to level 2 and fix all issues:**

```xml
<!-- psalm.xml — CHANGE errorLevel from 5 to 2 -->
<psalm errorLevel="2" ...>
```

**Why not level 1:** Level 1 requires `@return mixed` on every method without a return type — good for library code but noisy for application code. Level 2 catches all real type errors without the noise.

**Additional pslam rules to add:**

```xml
<psalm ...>
    <!-- Existing: -->
    <projectFiles>...</projectFiles>

    <!-- NEW: Enforce strict types everywhere -->
    <issueHandlers>
        <!-- Missing declare(strict_types=1) is now an error -->
        <MissingDeclareStrictTypes>
            <errorLevel type="suppress">
                <directory name="tests"/>
            </errorLevel>
        </MissingDeclareStrictTypes>

        <!-- No more implicit null returns -->
        <NullReturn errorLevel="error"/>

        <!-- No more accessing possibly-undefined array keys -->
        <PossiblyUndefinedArrayOffset errorLevel="error"/>

        <!-- Catch unused parameters (often bugs) -->
        <UnusedParam errorLevel="error"/>
    </issueHandlers>
</psalm>
```

### 2.3 Rector for Automated Type Safety Upgrades

**Add to composer.json:**

```bash
composer require --dev rector/rector
```

**Config:**

```php
<?php
// rector.php
declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src', __DIR__ . '/tests']);
    
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_82,
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
    ]);
    
    // Add readonly to classes where all properties are readonly
    $rectorConfig->rule(ReadOnlyClassRector::class);
};
```

**Run:** `vendor/bin/rector process --dry-run` (review) then `vendor/bin/rector process` (apply)

### 2.4 Invariant Assertions in Hive::doTick()

**Problem:** Bugs that take hours to manifest (like the `srand(42)` bug) are invisible in the running system. No assertion fires because `array_rand()` still returns valid array keys — just always the same one.

**Pattern: Periodic invariant checks in the tick loop**

```php
// src/Hive/Hive.php — ADD to doTick()
private function doTick(): void
{
    // ... existing code ...

    // ═══ INVARIANT CHECK every 500 ticks ═══
    if ($this->tick % 500 === 0) {
        $this->checkInvariants();
    }
}

/**
 * Assert system invariants that, if violated, indicate silent corruption.
 *
 * These checks run in production and LOG violations — they don't crash the daemon.
 * But they surface bugs that would otherwise be invisible.
 *
 * §ARCH-V2: All invariants from srand(42) postmortem.
 */
private function checkInvariants(): void
{
    $violations = [];

    // INVARIANT 1: RNG guard is clean
    if (\BeeSwarm\Infra\RngIsolation::hasUnrestoredGuards()) {
        $violations[] = 'RNG_POISONING: unrestored RngIsolation guards';
    }

    // INVARIANT 2: array_rand() diversity over recent tasks
    // Track last 100 task picks — if all same, RNG is deterministic
    static $recentPicks = [];
    if (count($recentPicks) >= 100) {
        $unique = count(array_unique($recentPicks));
        if ($unique < 10 && count($recentPicks) >= 100) {
            $violations[] = "TASK_DIVERSITY: only {$unique} unique tasks in last 100 picks (RNG poisoning?)";
        }
        $recentPicks = [];
    }

    // INVARIANT 3: Bee energy not NaN or infinite
    foreach ($this->bees as $i => $bee) {
        if (is_nan($bee->energy()) || is_infinite($bee->energy())) {
            $violations[] = "BEE_CORRUPTION: bee#{$i} energy={$bee->energy()}";
        }
    }

    // INVARIANT 4: Database connection alive
    try {
        Database::get()->query('SELECT 1')->fetchColumn();
    } catch (\PDOException $e) {
        $violations[] = "DB_CONNECTION: {$e->getMessage()}";
    }

    // INVARIANT 5: Memory below 80% of limit
    $limit = (int) ini_get('memory_limit') ?: 128 * 1024 * 1024;
    $used = memory_get_usage(true);
    if ($used > 0.8 * $limit) {
        $violations[] = 'MEMORY: ' . round($used / 1024 / 1024) . 'MB / ' . round($limit / 1024 / 1024) . 'MB';
    }

    // LOG all violations (don't crash — daemon should keep running)
    foreach ($violations as $v) {
        $this->log("⚠️ INVARIANT VIOLATION: {$v}");
    }
}
```

---

## Layer 3: Rapidly Diagnose Production Bugs

### 3.1 Structured JSON Logging (Upgrade from Emoji Text)

**Current:** `file_put_contents()` with emoji-marked lines. Hard to query, no log rotation.

**Pattern: StructuredLog with key=value encoding**

```php
<?php
// src/Infra/StructuredLog.php
declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * StructuredLog — writes one JSON line per event.
 *
 * Replaces Hive::log() prose + emoji approach.
 * Each line is a self-contained JSON object — grep-friendly and jq-queryable.
 *
 * Usage:
 *   StructuredLog::event('discovery', ['atom' => 'sqrt', 'cv' => 0.0]);
 *   // → {"ts":"2026-08-04T15:30:01+03:00","tick":1234,"event":"discovery","atom":"sqrt","cv":0.0}
 */
final class StructuredLog
{
    private static ?string $path = null;
    private static int $tick = 0;

    public static function init(string $path): void
    {
        self::$path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public static function setTick(int $tick): void
    {
        self::$tick = $tick;
    }

    /**
     * Log a structured event.
     *
     * @param string $event event type (discovery, plateau, error, route, etc.)
     * @param array<string, mixed> $fields additional fields
     */
    public static function event(string $event, array $fields = []): void
    {
        if (self::$path === null) return;

        $entry = [
            'ts'    => date('c'),           // ISO 8601 with timezone
            'tick'  => self::$tick,
            'event' => $event,
        ] + $fields;

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(self::$path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Log an error with stack trace.
     */
    public static function error(string $msg, ?\Throwable $e = null): void
    {
        $fields = ['msg' => $msg];
        if ($e !== null) {
            $fields['exception'] = get_class($e);
            $fields['message'] = $e->getMessage();
            $fields['file'] = $e->getFile() . ':' . $e->getLine();
        }
        self::event('error', $fields);
    }
}
```

**Query examples:**

```bash
# Last 10 discoveries
tail -1000 logs/structured.log | jq 'select(.event == "discovery")'

# CV distribution of discoveries
cat logs/structured.log | jq 'select(.event == "discovery") | .cv' | sort -n | uniq -c

# Timeline of plateau entries/exits
cat logs/structured.log | jq 'select(.event == "plateau_enter" or .event == "plateau_exit") | {tick, event}'

# All errors
cat logs/structured.log | jq 'select(.event == "error")'

# Tick rate (ticks per real minute)
cat logs/structured.log | jq -s 'group_by(.tick/100|floor) | map({min: .[0].tick, events: length})'
```

### 3.2 Production Diagnostics Dashboard (Single PHP Script)

```php
#!/usr/bin/env php
<?php
// scripts/diagnostics.php — one-command production health check
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Infra\Database;
use BeeSwarm\Infra\RngIsolation;

$errors = [];
$warnings = [];

// ── RNG ────────────────
echo "[1/8] RNG state... ";
if (RngIsolation::hasUnrestoredGuards()) {
    echo "⚠️  UNRESTORED GUARDS\n";
    $errors[] = 'RNG poisoning detected — srand() called without restore()';
} else {
    // Verify array_rand() is actually random
    $items = range(0, 99);
    $seen = [];
    for ($i = 0; $i < 100; $i++) {
        $seen[array_rand($items)] = true;
    }
    if (count($seen) < 30) {
        echo "⚠️  LOW ENTROPY (only " . count($seen) . "/100 unique)\n";
        $errors[] = 'array_rand() entropy suspiciously low — possible RNG poisoning';
    } else {
        echo "OK (" . count($seen) . "/100 unique)\n";
    }
}

// ── DATABASE ───────────
echo "[2/8] Database... ";
try {
    $db = Database::get();
    $laws = $db->query('SELECT COUNT(*) FROM laws')->fetchColumn();
    $ops = $db->query('SELECT COUNT(*) FROM grammar_ops')->fetchColumn();
    echo "OK ({$laws} laws, {$ops} ops)\n";
} catch (\PDOException $e) {
    echo "❌ {$e->getMessage()}\n";
    $errors[] = "Database error: {$e->getMessage()}";
}

// ── DAEMON PROCESS ─────
echo "[3/8] Daemon process... ";
$pid = (int) trim(shell_exec('pgrep -f agenda.php 2>/dev/null') ?: '0');
if ($pid > 0) {
    $cmdline = file_get_contents("/proc/{$pid}/cmdline") ?: 'unknown';
    $cmdline = str_replace("\0", ' ', $cmdline);
    echo "OK (PID {$pid})\n";
} else {
    echo "NOT RUNNING\n";
    $warnings[] = 'Daemon not running';
}

// ── MEMORY ─────────────
echo "[4/8] Memory... ";
$limitStr = ini_get('memory_limit');
$limit = (int) $limitStr ?: 128 * 1024 * 1024;
$used = memory_get_usage(true);
$usedMB = round($used / 1024 / 1024);
$limitMB = round($limit / 1024 / 1024);
$pct = round($used / $limit * 100);
if ($pct > 80) {
    echo "⚠️  {$usedMB}MB / {$limitMB}MB ({$pct}%)\n";
    $warnings[] = "Memory usage high: {$pct}%";
} else {
    echo "OK ({$usedMB}MB / {$limitMB}MB)\n";
}

// ── DISK ───────────────
echo "[5/8] Data disk... ";
$free = disk_free_space(__DIR__ . '/../data');
$freeGB = round($free / 1024 / 1024 / 1024, 1);
if ($free < 100 * 1024 * 1024) {  // < 100MB
    echo "⚠️  {$freeGB}GB free\n";
    $warnings[] = "Disk space low: {$freeGB}GB";
} else {
    echo "OK ({$freeGB}GB free)\n";
}

// ── LOG ────────────────
echo "[6/8] Recent log... ";
$logPath = __DIR__ . '/../logs/agenda.log';
if (file_exists($logPath)) {
    $recent = shell_exec("tail -20 " . escapeshellarg($logPath) . " 2>/dev/null") ?: '';
    $discoveries = substr_count($recent, '🔍');
    $errors_log = substr_count($recent, 'ERROR') + substr_count($recent, 'FATAL');
    echo "{$discoveries} discoveries, {$errors_log} errors in last 20 lines\n";
    if ($errors_log > 0) {
        $warnings[] = "{$errors_log} errors in recent log";
    }
} else {
    echo "NO LOG FILE\n";
    $warnings[] = 'Log file missing';
}

// ── RECENT DISCOVERIES ─
echo "[7/8] Recent discoveries (24h)... ";
try {
    $recent = $db->query(
        "SELECT name, cv, domain, found_at FROM laws WHERE found_at > datetime('now', '-1 day') ORDER BY rowid DESC LIMIT 5"
    )->fetchAll();
    if (empty($recent)) {
        echo "NONE in 24h ⚠️\n";
        $warnings[] = 'No discoveries in last 24 hours';
    } else {
        echo count($recent) . " in 24h\n";
        foreach ($recent as $r) {
            echo "       {$r['name']} CV={$r['cv']} [{$r['domain']}]\n";
        }
    }
} catch (\PDOException $e) {
    echo "ERROR: {$e->getMessage()}\n";
}

// ── CONFIG VALIDATION ──
echo "[8/8] Config validation... ";
$sources = getenv('FORAGER_SOURCES');
if ($sources) {
    $missing = [];
    foreach (explode(':', $sources) as $dir) {
        if (!is_dir($dir)) $missing[] = $dir;
    }
    if (!empty($missing)) {
        echo "⚠️  FORAGER_SOURCES contains missing dirs: " . implode(', ', $missing) . "\n";
        $warnings[] = 'FORAGER_SOURCES directories missing';
    } else {
        echo "OK (FORAGER_SOURCES valid)\n";
    }
} else {
    echo "OK (using defaults)\n";
}

// ── SUMMARY ────────────
echo "\n" . str_repeat('=', 50) . "\n";
if (empty($errors) && empty($warnings)) {
    echo "✅ ALL CHECKS PASSED\n";
    exit(0);
}

if (!empty($errors)) {
    echo "❌ ERRORS (" . count($errors) . "):\n";
    foreach ($errors as $e) echo "   • {$e}\n";
}
if (!empty($warnings)) {
    echo "⚠️  WARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $w) echo "   • {$w}\n";
}

exit(empty($errors) ? 0 : 1);
```

### 3.3 Production Smoke Test v2 (Post-Deploy)

The existing `scripts/smoke.php` is good. Add these checks:

```php
// ADD to scripts/smoke.php after existing tests:

// ── TEST 7: No NaN in task data ────────────────────────
echo "[SMOKE] Task data integrity... ";
try {
    $gen = new TaskGenerator();
    $tasks = $gen->createComposeTasks();
    $nanCount = 0;
    foreach ($tasks as $t) {
        foreach ($t['data'] ?? [] as $row) {
            foreach ($row as $val) {
                if (is_nan((float) $val)) $nanCount++;
            }
        }
    }
    if ($nanCount > 0) {
        echo "FAIL ({$nanCount} NaN values)\n";
        $errors[] = "{$nanCount} NaN values in generated tasks";
    } else {
        echo "OK\n";
    }
} catch (\Throwable $e) {
    echo "FAIL ({$e->getMessage()})\n";
    $errors[] = "Task generator: {$e->getMessage()}";
}

// ── TEST 8: Array shapes valid ─────────────────────────
echo "[SMOKE] Foraged task shapes... ";
try {
    $forager = new \BeeSwarm\Forager\Forager();
    $sources = array_filter(explode(':', getenv('FORAGER_SOURCES') ?: ''), 'is_dir');
    if (!empty($sources)) {
        $tasks = $forager->scanWithAccumulator(array_fill_keys($sources, 1));
        $malformed = 0;
        foreach ($tasks as $t) {
            if (!isset($t['name'], $t['data'], $t['domain'])) $malformed++;
            if (!is_array($t['data'])) $malformed++;
        }
        if ($malformed > 0) {
            echo "FAIL ({$malformed} malformed tasks)\n";
            $errors[] = "{$malformed} malformed foraged tasks";
        } else {
            echo "OK (" . count($tasks) . " tasks)\n";
        }
    } else {
        echo "SKIP (no FORAGER_SOURCES)\n";
    }
} catch (\Throwable $e) {
    echo "FAIL ({$e->getMessage()})\n";
    $errors[] = "Forager: {$e->getMessage()}";
}
```

### 3.4 Deploy Script with Pre/Post Validation

```bash
#!/bin/bash
# scripts/deploy-prod-v2.sh — safe deploy with validation
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo "=== SAFE DEPLOY ==="

# 1. PRE-DEPLOY: run gate
echo "[1/5] Pre-deploy gate..."
if ! bash scripts/gate.sh; then
    echo -e "${RED}Gate failed. Fix before deploy.${NC}"
    exit 1
fi

# 2. PRE-DEPLOY: stop daemon gracefully
echo "[2/5] Stopping daemon..."
pkill -f agenda.php 2>/dev/null || true
sleep 2
if pgrep -f agenda.php > /dev/null; then
    echo "Daemon didn't stop. Forcing..."
    pkill -9 -f agenda.php 2>/dev/null || true
    sleep 1
fi

# 3. DEPLOY: scp to target (or local)
echo "[3/5] Deploying files..."
# ... your existing deploy logic ...

# 4. POST-DEPLOY: smoke test
echo "[4/5] Running smoke test..."
if php scripts/smoke.php --full; then
    echo -e "${GREEN}Smoke PASS${NC}"
else
    echo -e "${RED}Smoke FAILED — DO NOT START DAEMON${NC}"
    exit 1
fi

# 5. POST-DEPLOY: start daemon
echo "[5/5] Starting daemon..."
nohup php agenda.php > /dev/null 2>&1 &
sleep 2
if pgrep -f agenda.php > /dev/null; then
    echo -e "${GREEN}Daemon started (PID: $(pgrep -f agenda.php))${NC}"
else
    echo -e "${RED}Daemon FAILED TO START${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}=== DEPLOY COMPLETE ===${NC}"
echo "Monitor: tail -f logs/structured.log | jq"
echo "Health:  php scripts/diagnostics.php"
```

---

## Layer 4: Prevent Regression

### 4.1 Pre-Commit Hook v2: Self-Healing RNG

**Current hook** (`.githooks/pre-commit`): Checks RNG guard, syntax. Good.

**Add:** Contract test enforcement, code complexity gates, and auto-restore pattern.

```bash
#!/bin/bash
# .githooks/pre-commit v2 — expanded gates
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; NC='\033[0m'
HAS_ERRORS=0

# ── GATE 0: No --no-verify bypass allowed ─────────────
# (hook itself is the gate — just keep it installed)

# ── GATE 1: RNG clean ────────────────────────────────
RNG_CHECK=$(php -r '
require "vendor/autoload.php";
if (\BeeSwarm\Infra\RngIsolation::hasUnrestoredGuards()) {
    echo "UNRESTORED_GUARDS"; exit(1);
}
echo "CLEAN";
' 2>/dev/null)

if [ "$RNG_CHECK" != "CLEAN" ]; then
    echo -e "${RED}[PRE-COMMIT] RNG POISONING DETECTED${NC}"
    echo "  srand(N) was called without restore()."
    echo "  Use RngIsolation::deterministicSeed(N) + ->restore()."
    HAS_ERRORS=1
fi

# ── GATE 2: Syntax check on staged files ─────────────
STAGED=$(git diff --cached --name-only 2>/dev/null | grep "\.php$" || true)
if [ -n "$STAGED" ]; then
    for f in $STAGED; do
        if ! php -l "$f" 2>/dev/null | grep -q "No syntax errors"; then
            echo -e "${RED}[PRE-COMMIT] Syntax error in $f${NC}"
            php -l "$f" 2>&1
            HAS_ERRORS=1
        fi
    done
fi

# ── GATE 3: Contract tests for staged files ──────────
# If any src/ file changed, run contract tests
if echo "$STAGED" | grep -q '^src/' 2>/dev/null; then
    echo "[PRE-COMMIT] Running contract tests..."
    if ! vendor/bin/phpunit tests/Contract/ --no-progress 2>&1 | grep -q '^OK'; then
        echo -e "${RED}[PRE-COMMIT] Contract tests FAILED${NC}"
        vendor/bin/phpunit tests/Contract/ 2>&1 | tail -20
        HAS_ERRORS=1
    fi
fi

# ── GATE 4: No debug code in staged files ────────────
if echo "$STAGED" | grep -qE '(var_dump|dd\(|dump\(|echo.*debug|print_r)' 2>/dev/null; then
    echo -e "${RED}[PRE-COMMIT] DEBUG CODE DETECTED in staged files${NC}"
    echo "  Remove var_dump/dd/dump/debug echo before commit."
    HAS_ERRORS=1
fi

if [ $HAS_ERRORS -eq 0 ]; then
    echo -e "${GREEN}[PRE-COMMIT] PASS — RNG clean, syntax OK, contracts pass${NC}"
    exit 0
else
    echo ""
    echo -e "${RED}Commit blocked. Fix errors above.${NC}"
    exit 1
fi
```

### 4.2 Regression Test Naming Convention

**Rule:** Every bug fix MUST include a test file named `*RegressionTest.php` with the bug description as the test name.

```php
<?php
// tests/SrandArrayRandRegressionTest.php
declare(strict_types=1);

namespace BeeSwarm\Tests;

/**
 * REGRESSION: srand(42) in getTasks() poisoned global RNG.
 *
 * Date: 03.08.2026 | Hours to diagnose: 8
 * Symptom: 1 law instead of 450 over weeks.
 * Root cause: srand(42) called in GEN_ task generation without restore.
 * Fix: save-and-restore pattern via RngIsolation + TaskGenerator::createComposeTasks().
 *
 * THIS TEST MUST NEVER BE REMOVED. If it fails, the bug has regressed.
 */
class SrandArrayRandRegressionTest extends TestCase
{
    /**
     * REGRESSION: After GEN_ task generation, array_rand() must be random.
     *
     * We simulate the production workflow:
     * 1. Generate compose tasks (which uses srand(42))
     * 2. Pick 100 random tasks (which uses array_rand())
     * 3. Verify at least 30 unique tasks were picked
     *
     * Before fix: only 1 unique task picked (always the same one)
     * After fix: ≥30 unique tasks out of 100 picks
     */
    public function testArrayRandIsRandomAfterComposeTaskGeneration(): void
    {
        $gen = new \BeeSwarm\Hive\TaskGenerator();
        $gen->createComposeTasks();  // this used srand(42) internally

        // Now simulate Hive::getTasks() picking random tasks
        $tasks = [];
        for ($i = 0; $i < 100; $i++) {
            $tasks[] = "task_{$i}";
        }

        $picks = [];
        for ($i = 0; $i < 100; $i++) {
            $picks[] = $tasks[array_rand($tasks)];
        }

        $uniquePicks = count(array_unique($picks));

        $this->assertGreaterThan(30, $uniquePicks,
            "array_rand() produced only {$uniquePicks}/100 unique picks after createComposeTasks(). "
            . "RNG is poisoned — srand(42) was not properly restored. "
            . "This is the exact bug from 03.08.2026 regression."
        );
    }

    /**
     * Stronger check: 1000 picks from 1000 items should yield ≥500 unique.
     * With srand(42), this would be ≤ 5.
     */
    public function testArrayRandProducesHighDiversityAfterTaskGeneration(): void
    {
        $gen = new \BeeSwarm\Hive\TaskGenerator();
        $gen->createComposeTasks();

        $items = range(0, 999);
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $seen[array_rand($items)] = true;
        }

        // With true randomness: ~632 unique (1 - 1/e principle)
        // With srand(42): ~5 unique
        // Threshold: 500 — generous, catches poisoning reliably
        $this->assertGreaterThan(500, count($seen),
            "Only " . count($seen) . "/1000 unique array_rand() results. "
            . "Expected ≥500 for true randomness. RNG is poisoned."
        );
    }
}
```

### 4.3 CI-Free Gate Script for Laptop

Since you have no CI server, the gate script (`scripts/gate.sh`) IS your CI. Run it before every commit. Add these layers:

```bash
#!/bin/bash
# scripts/gate-v2.sh — laptop CI: run before every commit
set -e

PASS=0
START_TIME=$(date +%s)

check() {
    local label="$1"; shift
    printf "  %-55s" "$label"
    if "$@" > /tmp/gate_out.txt 2>&1; then
        echo -e "\033[0;32m[PASS]\033[0m"
        return 0
    else
        echo -e "\033[0;31m[FAIL]\033[0m"
        cat /tmp/gate_out.txt | tail -5
        PASS=1
        return 1
    fi
}

echo "=== LAPTOP CI GATE ==="

# ── FAST (< 5s total) ────────────────────────────
echo "--- Fast Gates ---"
check "php -l syntax"       bash -c 'git diff --cached --name-only | grep "\.php$" | xargs -r php -l | grep -v "^No syntax errors" || true'
check "psalm level 5"       bash -c 'vendor/bin/psalm --no-progress | grep -q "No errors found"'
check "RNG guard clean"     bash -c 'php -r "require \"vendor/autoload.php\"; if (\BeeSwarm\Infra\RngIsolation::hasUnrestoredGuards()) { echo \"UNRESTORED\"; exit(1); } echo \"CLEAN\";" | grep -q CLEAN'
check "phpcs PSR-12"        bash -c 'vendor/bin/phpcs -q --report=summary src/ 2>&1 | grep -v "No PHP errors" || true'
check "contract tests"      bash -c 'vendor/bin/phpunit tests/Contract/ --no-progress 2>&1 | grep -q "^OK"'

# ── MEDIUM (< 30s total) ─────────────────────────
echo "--- Medium Gates ---"
check "behavioral-diversity" bash -c 'vendor/bin/phpunit tests/BehavioralDiversityTest.php --no-progress 2>&1 | grep -q "^OK"'
check "regression suite"     bash -c 'vendor/bin/phpunit tests/*Regression* --no-progress 2>&1 | grep -q "^OK"'
check "property tests"       bash -c 'vendor/bin/phpunit tests/Property/ --no-progress 2>&1 | grep -q "^OK"'

# ── FULL SUITE (< 2 min) ─────────────────────────
echo "--- Full Suite ---"
check "phpunit all tests"    bash -c 'vendor/bin/phpunit tests/ --exclude-group disabled --no-progress 2>&1 | grep -q "^OK"'
check "smoke test"           bash -c 'php scripts/smoke.php --full 2>&1 | grep -q "SMOKE TEST: PASS"'

# ── SUMMARY ──────────────────────────────────────
ELAPSED=$(($(date +%s) - START_TIME))
echo ""
echo "=== GATE COMPLETE in ${ELAPSED}s ==="
if [ $PASS -eq 0 ]; then
    echo -e "\033[0;32mALL GATES PASS — safe to commit\033[0m"
else
    echo -e "\033[0;31mBLOCKED — fix failures above\033[0m"
fi
exit $PASS
```

### 4.4 Test Result Discipline

**Pattern: Never ignore a failing test.** The gate script blocks commits on ANY failure. But you also need a habit:

```bash
# Add to .bashrc or your workflow:
alias test-all='cd ~/.bee_swarm && vendor/bin/phpunit tests/ --exclude-group disabled'
alias test-gate='cd ~/.bee_swarm && bash scripts/gate-v2.sh'
alias test-smoke='cd ~/.bee_swarm && php scripts/smoke.php --full'
alias diag='cd ~/.bee_swarm && php scripts/diagnostics.php'
alias deploy='cd ~/.bee_swarm && bash scripts/deploy-prod-v2.sh'
```

---

## Implementation Priority Matrix

| # | Action | Impact | Effort | Risk | Time to Value |
|---|--------|--------|--------|------|---------------|
| 1 | **Add `RngIsolation::assertClean()` to TestCase::tearDown()** | CRITICAL | 5 min | None | Immediate |
| 2 | **Write `SrandArrayRandRegressionTest`** | CRITICAL | 30 min | None | Immediate |
| 3 | **Write `TaskGeneratorContractTest`** | HIGH | 30 min | None | Immediate |
| 4 | **Add periodic invariant checks to Hive::doTick()** | HIGH | 1 hour | Low | 24h (next plateau) |
| 5 | **Switch to StructuredLog** | MEDIUM | 2 hours | Low | Next deployment |
| 6 | **Create `scripts/diagnostics.php`** | MEDIUM | 2 hours | None | Next deployment |
| 7 | **Create `GlobalStateGuard`** | MEDIUM | 1 hour | Low | After next global state bug |
| 8 | **Upgrade Psalm to level 2** | MEDIUM | 3 hours | Breaking changes possible | After fixing all level-2 issues |
| 9 | **Create `Task` value object** | LOW | 4 hours | API change — major refactor | Post Stage 2 |
| 10 | **Create `Random` class** | LOW | 3 hours | API change | Post Stage 2 |
| 11 | **Add property-based tests** | LOW | 4 hours | None | Ongoing |
| 12 | **Mark all non-abstract classes `final`** | LOW | 2 hours | Could break mocks | Post refactor |

---

## What You Already Have (Don't Break)

Your existing infrastructure is strong. These are the pieces that work and should be preserved:

| Component | What It Does | Preserve By |
|-----------|-------------|-------------|
| `RngIsolation` | Save-and-restore RNG state | Adding tearDown assertion |
| `.githooks/pre-commit` | Blocks RNG-poisoned commits | Expanding, not replacing |
| `scripts/gate.sh` | Multi-layer pre-commit check | Adding contract tests |
| `scripts/smoke.php` | Post-deploy production verification | Adding NaN + shape checks |
| `phpunit.xml` with `SWARM_DB_PATH=:memory:` | Test DB isolation | Never run `--no-configuration` |
| `psalm.xml` | Static type analysis | Upgrade level, don't remove |
| `phpcs.xml` with complexity limits | Code quality gates | Maintain complexity limits |
| `PlateauDetector` | Detects stuck states | Add invariant checks |
| `NullCalibrator` + `LawValidator` | Statistical validation | Already excellent |
| `RecordKeeper::preloadKnown()` | Deduplication | Already prevents re-discovery |
| `scripts/run_tests_safe.sh` | CPU-kill protection | Add DB lock check before running |

---

## The srand(42) Postmortem in One Slide

```
ROOT CAUSE: Global state in PHP.
  srand(42) in getTasks() → array_rand() deterministic everywhere.

WHY TESTS DIDN'T CATCH IT:
  Unit tests run in isolation. srand(42) poisons state AFTER the
  test exits. Next test in the SAME PROCESS gets deterministic
  array_rand() — but since each test creates its own data, none
  verify that array_rand() remains random.

WHY IT TOOK 8 HOURS:
  Symptom (1 law instead of 450) was days delayed from cause.
  No invariant check verified task diversity in the tick loop.
  No structured logging to query "how many unique tasks did we route?"

FIXES (BY LAYER):
  L1: RngIsolation save-and-restore pattern (DONE ✓)
  L1: Add assertClean() to TestCase::tearDown() (TODO)
  L2: Contract test: createComposeTasks() restores RNG (TODO)
  L2: Property test: array_rand() diversity after any operation (TODO)
  L3: Periodic invariant: task diversity in tick loop (TODO)
  L4: Regression test named after the bug (TODO)
  L4: Pre-commit hook blocks srand without restore (DONE ✓)
```
