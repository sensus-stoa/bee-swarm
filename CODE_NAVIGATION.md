# CODE_NAVIGATION.md — карта кода для AI-агентов

> Назначение: быстрая ориентация в архитектуре bee_swarm.
> Обновлён: 03.08.2026 (V0.8.5, 491 tests).

## Точки входа

| Файл | Роль |
|------|------|
| `agenda.php` | Главный цикл: создаёт Hive, запускает `run()` |
| `tests/` | 491 тест, PHPUnit, `SWARM_DB_PATH=:memory:` |

## Поток данных (data flow)

```
Obsidian .md / metrics.jsonl
  │
  ▼
Forager (StreamingAccumulator)
  │  Сканирует файлы → создаёт foraged_num_* и foraged_txt_* задачи
  │  ПРИМЕЧАНИЕ: Text atoms требуют AtomRegistry для создания foraged_txt_*
  ▼
TaskGenerator::generate()
  │  foraged задачи + базовые + cross-pair + cloze
  │  crossPairTasks: foraged_txt_* → txt_pair_X_to_Y (domain='text_pairs')
  ▼
Hive::doTick()
  │  Маршрутизация по domain:
  │  - 'cloze' → ClozeEngine::findBestAtom()
  │  - 'text_pairs'/'foraged'/'generated' → doDiscoverTick() → DiscoveryEngine → Search::find
  │  - 'foraged_text' → E1 text atom discovery (preg_match/match_label)
  ├── doClozeTick()   → ClozeEngine → grammar atom selection
  ├── doDiscoverTick() → DiscoveryEngine → Search::find (CV→0)
  └── E1 feedback loop → AtomRegistry::applyTextAtom() → addDiscoveredTextAtom()
  ▼
RecordKeeper::record() → INSERT INTO laws
  │  Сохраняет: atom, cv, domain, source_path, law_class (V0.8.5)
  ▼
SQLite (data/swarm.db)
```

## Ключевые классы

### Core (алгоритмы поиска)
| Класс | Файл | Роль |
|-------|------|------|
| `Search` | `src/Core/Search.php` | CV→0 поиск: `find(X, y, grammar)` → `[found, cv, formula, cv_test, class]` |
| `Grammar` | `src/Core/Grammar.php` | Операции: BASE_OPS + SEMANTIC_OPS + fromOps() |
| `AtomRegistry` | `src/Core/AtomRegistry.php` | Реестр атомов: `all()`, `applyTextAtom()`, `isTextAtom()`, `addDiscoveredTextAtom()` |
| `TextAtomCrossPairer` | `src/Core/TextAtomCrossPairer.php` | `crossPair(atoms)`: текст-атомы → txt_pair X/y задачи |

### Hive (управление роем)
| Класс | Файл | Роль |
|-------|------|------|
| `Hive` | `src/Hive/Hive.php` | Главный цикл: doTick(), маршрутизация, логирование |
| `DiscoveryEngine` | `src/Hive/DiscoveryEngine.php` | `discover(X, y, grammar)`: Search + heldout + compose |
| `ClozeEngine` | `src/Hive/ClozeEngine.php` | Поиск через cloze (word prediction) |
| `RecordKeeper` | `src/Hive/RecordKeeper.php` | Запись законов в БД, дедупликация |
| `TaskGenerator` | `src/Hive/TaskGenerator.php` | Генерация задач: базовые + cross-pair + cloze |
| `TaskRouter` | `src/Hive/TaskRouter.php` | Маршрутизация задач к пчёлам |

### Forager (сбор данных)
| Класс | Файл | Роль |
|-------|------|------|
| `StreamingAccumulator` | `src/Forager/StreamingAccumulator.php` | Сканирование файлов, создание foraged задач |
| `Scanner` | `src/Forager/Scanner.php` | Поиск файлов по маске |
| `DataSelfGenerator` | `src/Forager/DataSelfGenerator.php` | Генерация синтетических данных |

### Infra
| Класс | Файл | Роль |
|-------|------|------|
| `Database` | `src/Infra/Database.php` | SQLite singleton, DDL, миграции, WAL-режим |

## Ключевые таблицы БД

| Таблица | Назначение |
|---------|-----------|
| `laws` | Открытые законы: name, formula, cv, domain, law_class |
| `grammar_ops` | Операции грамматики + открытые текст-атомы |
| `knowledge_graph` | Семантические факты (subject, predicate, object) |
| `overlap_log` | $1.8: попарное сравнение ответов пчёл |

## Тестовые паттерны

```php
// Все тесты наследуют TestCase → in-memory SQLite
class MyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();  // Инициализирует Database, блокирует prod DB
    }
}

// Запуск: SWARM_DB_PATH=:memory: vendor/bin/phpunit tests/
// Паратест (2 потока): vendor/bin/paratest -p2 tests/
```

## Известные pitfalls

### 1. preg_match без capture groups → нет foraged_txt_* (исправлено V0.8.5)
`applyTextAtom('preg_match', content, 'акты')` без `()` в паттерне возвращает `[[]]`.
`is_numeric([])` = false → StreamingAccumulator не создавал задачи.
**Фикс**: `count($result)` для non-numeric результатов.
**Тест**: `ForagerTextAtomRegressionTest`

### 2. fromOps создавал мёртвые custom_* операции (исправлено)
`Grammar::fromOps()` использовал `'custom_' . $op` вместо реальных callables из BASE_OPS.
**Тест**: `GrammarFromOpsRegressionTest`

### 3. Статический кеш в getTasks() (исправлено)
`Hive::getTasks()` кешировал результат → тесты видели устаревшие данные.
**Фикс**: убран static cache.

### 4. R-тавтологии (R×x × x/R×x = x)
Reduce-константы создают алгебраические тождества с CV=0.
**Классификация**: V0.8.5 Law Classification Gate → IDENTITY vs EMPIRICAL.

## Переменные окружения

| Переменная | Назначение |
|-----------|-----------|
| `SWARM_DB_PATH` | Путь к БД (`:memory:` для тестов) |
| `CORPUS_DIRS` | Директории для корпуса (cloze) |
| `FORAGER_SOURCES` | Директории для сканирования forager |

## Команды деплоя

```bash
# Копирование файлов
scp src/... laptop:~/.bee_swarm/src/...

# Остановка + запуск
ssh hive 'pkill -f "php agenda"; sleep 1'
ssh hive 'cd ~/.bee_swarm && CORPUS_DIRS=~/obsidian FORAGER_SOURCES=~/obsidian setsid php agenda.php >> logs/agenda.log 2>&1 < /dev/null &'
```
