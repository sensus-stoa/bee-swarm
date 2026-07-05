# PHP + RoadRunner Coding Standards

## Stack

- **Runtime:** PHP 8.x + RoadRunner (daemon mode)
- **Database:** SQLite via PDO
- **Tests:** PHPUnit (phpunit.xml, bootstrap=autoload.php)

## Daemon (agenda.php)

The daemon is the run loop. It runs continuously under RoadRunner or as a standalone process.

```
Pre-start checks:
- phpunit.xml env: SWARM_DB_PATH=data/test_swarm.db (NOT production)
- pgrep -f agenda.php → must return PID

After code changes:
- Run full test suite: vendor/bin/phpunit tests/
- Restart daemon: pkill -f agenda.php; sleep 1; php agenda.php &
- Verify: pgrep -f agenda.php
```

## Database

```php
// ALWAYS separate prepare and execute:
$stmt = $db->prepare("SELECT ...");
$stmt->execute([...]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// NEVER chain: $db->prepare()->execute() — returns bool, not stmt
```

## Test DB Isolation (CRITICAL)

```xml
<!-- phpunit.xml -->
<env name="SWARM_DB_PATH" value="data/test_swarm.db"/>
```

Tests run on SEPARATE database. Never use `--no-configuration` flag — it bypasses the env var.

## Known Pitfalls

1. `prepare()→execute()` returns bool — separate calls
2. Grammar uses Unicode minus (U+2212 '−') not ASCII '-'
3. `getSize()` / `getPathname()` need try/catch for broken symlinks
4. `sq` must be in BASE_OPS and getUnaryOps() for Search::find
5. git checkout of single file → Frankenstein codebase (src/ vs agenda.php mismatch)
6. 473 .md files unchecked → 164K words → system hangs
