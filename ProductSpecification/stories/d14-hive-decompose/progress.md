# D14 — Hive Decomposition (Улей ≤ 300 строк)

## Диагноз

Hive.php: **1037 строк**, 7 методов > 40 строк. Методы:
- `bootstrap()` — 101 строк
- `doTick()` — 202 строк
- `doDiscoverTick()` — 77 строк
- `doClozeTick()` — 54 строк
- `recordDiscovery()` — 44 строк
- `idleDreamTick()` — 56 строк
- `getTasks()` — 160 строк

Декомпозиция на 6 классов. Каждый ≤ 100 строк. Hive после: ~200 строк (оркестрация).

## Фазы

### Phase 1: BootstrapManager
**Извлечь:** `bootstrap()` (101 строк) → `BootstrapManager`
- Создание seed-пчёл, TaskRouter, preload known laws, forager startup
- Hive получает `BootstrapManager` через constructor injection
- Тест: `BootstrapManagerTest` — 3 seed bees, pairwise distinct, BOOTSTRAP log

### Phase 2: TaskManager
**Извлечь:** `getTasks()` (160 строк) + `filterInsufficientTasks()` → `TaskManager`
- Генерация задач (metrics, base, foraged), фильтрация insufficient, кеширование
- Hive получает задачи через `$this->taskManager->getTasks()`
- Тест: генерация задач, insufficient-фильтр, кеш на 100 тиков

### Phase 3: DiscoveryEngine
**Извлечь:** `doDiscoverTick()` (77 строк) + `recordDiscovery()` (44 строк) → `DiscoveryEngine`
- Search::find, Heldout, AtomRegistry::discover, discoverCompose, запись в БД
- Принимает Hive-контекст (routedBee, knownLaws, epsilonCache, log)
- Тест: discovery pipeline, DUPLICATE detection, energy reward

### Phase 4: ComposeEngine
**Извлечь:** `doComposeTick()` + `isDesperationCompose()` (70 строк) → `ComposeEngine`
- Plateau-based compose, desperation compose, cross-domain tracking
- Тест: compose triggering, desperation conditions

### Phase 5: ClozeEngine
**Извлечь:** `doClozeTick()` (54 строк) → `ClozeEngine`
- Sentence registry, window-based prediction, best-atom selection
- Тест: cloze discovery, error threshold

### Phase 6: DreamEngine (рефакторинг)
**Выделить из Hive:** `idleDreamTick()` → делегировать в `IdleDreamer` + Hive-контекст
- Подготовка dreamTasks, вызов IdleDreamer, recordDiscovery при успехе
- IdleDreamer уже существует. Hive только готовит данные и вызывает.

## После декомпозиции

```
Hive.php (~200 строк):
  - run()          — главный цикл
  - doTick()       — оркестрация: route → energy → spawn → process
  - bootstrap()    → делегирует BootstrapManager
  - computeDiversity(), avgGrammarSize(), jaccard()
  
BootstrapManager  (~80 строк)
TaskManager       (~160 строк)
DiscoveryEngine   (~100 строк)
ComposeEngine     (~60 строк)
ClozeEngine       (~50 строк)
IdleDreamer       (~50 строк)  — уже существует
```

## E2E

После каждой фазы: `paratest --processes=2` + сравнение числа тестов с baseline (434).
Все extraction — поведение не меняется, только структура.

## Сложность

| Phase | Что | Часы | Сложность |
|-------|-----|------|-----------|
| 1 | BootstrapManager | 2h | ⭐⭐ |
| 2 | TaskManager | 3h | ⭐⭐⭐ |
| 3 | DiscoveryEngine | 2.5h | ⭐⭐⭐ |
| 4 | ComposeEngine | 1.5h | ⭐⭐ |
| 5 | ClozeEngine | 1h | ⭐ |
| 6 | DreamEngine refactor | 1h | ⭐ |
