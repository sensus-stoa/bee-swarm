# Story S1.10-MEMORY-DB: Тестовая БД в памяти (:memory:)

> Замена файловой тестовой БД (data/test_swarm.db) на SQLite :memory:.
> Цель: детерминизм, скорость, устранение класса флаков от накопленного мусора.

## Почему

1. **Накопление мусора** (01.08.2026): test_swarm.db накопила 645 test-ops от повторных
   прогонов → grammar_ops 726 → Search::find depth=2 = 4.993s на грани 5s порога →
   DaemonEfficiencyTest флакал под paratest. Чистка вручную — разовая; :memory: решает навсегда.
2. **Скрытые зависимости**: в файловой БД лежит 91 law, 96 grammar_ops, 1232 kg-факта —
   накопленный мусор, на котором тесты молча стояли (GrammarTest::testReload ждёт непустой
   grammar_ops; KnownLawsPreloadTest ждёт непустые laws). :memory: заставит тесты быть честными.
3. **Скорость**: in-memory SQLite без fsync/WAL-файлов. Сейчас 22s (узкое место — Search CPU,
   но БД-часть тоже ускорится).

## Разведка (сделана 01.08.2026)

- Все 400 тестов идут через `Database::get()` — прямых `new PDO(...)` в tests/ НЕТ (grep: 0).
- `Database::get()`: путь из `SWARM_DB_PATH` env → `PDO("sqlite:{$path}")` → WAL pragma →
  migrate(). `:memory:` работает: WAL pragma вернёт 'memory' (не ошибка).
- migrate() создаёт 4 таблицы (laws, grammar_ops, knowledge_graph, overlap_log).
  Остальные 5 в файловой БД (hive_state, action_pool, conscious_state, conscious_events, fd) —
  legacy, НИГДЕ не создаются и не читаются (grep: 0) → в :memory: их не будет, это ок.
- **БЛОКЕР**: `tests/TestCase.php:14-16` guard — `str_contains($dbPath, 'test')` →
  `:memory:` не содержит 'test' → ВСЕ тесты скипнутся (335 skipped, молча). Guard расширить.
- paratest 2 процесса: каждый процесс — свой singleton → свои :memory: БД. Это даже ЛУЧШЕ
  (сейчас 2 процесса делят ОДИН файл). Но: данные между тест-файлами не шаредятся —
  проверить, что ни один тест не ждёт данных, записанных другим файлом.

## Phases

### Phase 1: Guard + переключение ✅
- [x] RED: MemoryDbTest — guard скипает :memory: (3/3 Skipped)
- [x] GREEN: guard `:memory: || str_contains($dbPath, 'test')` (3/3 PASS)
- [x] phpunit.xml: SWARM_DB_PATH=:memory:
- [x] Прогон сьюта — найден 1 FAIL (MemoryDbTest::testMemoryDbStartsClean); возвращён детерминированно через Database::reset() (970ebb5)

### Phase 2: Починить тесты, зависимые от предзаполненных данных ✅
- [x] GrammarTest::testReload — self-seed seed_op_for_reload перед reloadFromDb()
- [x] KnownLawsPreloadTest::testPreloadNonEmptyWhenDbHasLaws — self-seed SEED_LAW
- [x] QueryEngineTest::testLawsByDomain — self-seed ARITH_LAW (domain='arithmetic') + use Database
- [x] Полный сьют 402/402 PASS

### Phase 3: Чистка ✅
- [x] data/test_swarm.db — оставлена как fallback для ручных прогонов (больше не пишется тестами, git status чист)
- [x] paratest 2 процесса × 5 прогонов — стабильно OK (14.5s vs 22s файловая)
- [x] TestCase.php сообщение об ошибке — :memory: или test path
- [x] Доки: AGENTS.md, coding.md, test-class.md (шаблон → Database::get(), убран прямой PDO)

## Что НЕ делать
- ❌ Не трогать production путь (data/swarm.db остаётся файловым)
- ❌ Не добавлять новый DB-класс — только env-переключение

## Статус
✅ Phase 1-3 завершены
