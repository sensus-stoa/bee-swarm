# PHP + RoadRunner Coding Standards

## Stack

- **Runtime:** PHP 8.x + RoadRunner (daemon mode)
- **Database:** SQLite via PDO
- **Tests:** PHPUnit (phpunit.xml, bootstrap=autoload.php)

## SOLID (приоритет: S + I)

### S — Single Responsibility
Один класс = одна причина для изменения. AtomRegistry (508 строк) делает хранение атомов + поиск + валидацию — нарушение. Разбить на:
- `AtomProvider` — реестр атомов
- `LawValidator` — held-out validation
- `RetrospectiveValidator` — проверка существующих законов

### I — Interface Segregation
Мелкие интерфейсы = лучшая тестируемость и composability. Не плодить God-классы с 20+ public методами.

### O, L, D — deferred
Исследовательский проект. Применить когда понадобятся (DAO-слой, полиморфизм, DI-контейнер).

## Cognitive Complexity Gate

Методы с complexity > 10 → warning. Измеряется через phpcs-cognitive-complexity (D5).
- Если if/else > 2 уровней — extract method
- Если foreach внутри foreach — подумать о flatten
- scanDir(110 строк, вложенные foreach + if) — цель для рефакторинга

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

## Method Size

- ≤ 40 строк — комфортный предел для чтения за один экран
- Если метод > 40 строк и не состоит из простых объявлений — extract
- Исключение: declared arrays/configuration (атомы, стратегии)

## Directory Size

- ≤ 7 файлов в одной директории src/
- Если > 7 — разбить на поддиректории по подобластям
- tests/ — зеркалит структуру src/
