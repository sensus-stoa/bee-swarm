# V1.3 — Grammar Isolation (§2.3)

## Статус: ⬜ архитектурное нарушение

Критический барьер для Стадии 1. Без него остальные критерии Стадии 1 не имеют смысла —
популяция без изоляции грамматик не является популяцией.

## Проблема

Сейчас: все пчёлы разделяют общую таблицу `grammar_ops`. При открытии атома любая пчела
пишет его в общую БД. При spawn — пчела НЕ наследует грамматику родителя, а получает
доступ ко всем атомам через `new Grammar()`.

Протокол §2.3: «Никакой общей таблицы grammar_ops. Никакой глобальной базы грамматик.
При порождении родитель сериализует G_parent и передаёт потомку. После порождения
грамматики родителя и потомка расходятся независимо.»

## Что сделать

### Phase 1: Per-bee grammar storage (2h)
- Bee получает `private array $grammarOps` — свой набор операций
- Grammar больше не читает grammar_ops из БД для per-bee операций
- `Bee::addToGrammar(string $op)` — добавляет атом в свою грамматику
- `Bee::grammar()` возвращает per-bee набор
- `Grammar::all()` возвращает ОБЩИЙ реестр (read-only для пчёл) + per-bee ops

### Phase 2: Spawn inheritance (1h)
- Bee::spawn() передаёт $this->grammarOps потомку (сериализация через массив)
- Потомок мутирует унаследованную грамматику (GrammarMutator)
- grammar_ops БД остаётся как read-only реестр всех когда-либо открытых атомов

### Phase 3: Hive wiring (1h)
- recordDiscovery добавляет атом в per-bee грамматику через `$this->routedBee->addToGrammar()`
- Search::find / AtomRegistry::discoverCompose используют `$bee->grammar()` вместо `(new Grammar())->all()`
- IdleDreamer использует per-bee грамматику

### Phase 4: verify_1_3 script (0.5h)
- Запрашивает грамматику каждой пчелы (через лог или дамп)
- Для каждой пары: если G_i == G_j и обе живы ≥10 тактов → FAIL
- Проверяет что грамматика потомка ≠ грамматика родителя при spawn

### Phase 5: Remove shared grammar_ops dependency (3h)
**G1:** общая таблица `grammar_ops` нарушает §2.3. Сейчас 6 путей чтения/записи.
**G4:** `recordDiscovery` пишет в обе — per-bee грамматика не источник истины.

**Подфазы:**

- [x] **5a: IdleDreamer per-bee** — `dream()` принимает `$grammarOps` параметр. Hive передаёт `baseOpNames() + bee->grammar()`. ✅ (02.08)
- [x] **5b: Remove Grammar::add() from recordDiscovery** — убрать `$g->add()` из `recordDiscovery()`. Общая БД = read-only архив. ✅ (02.08)
- [ ] **5c: AtomRegistry::all() from laws** — читать формулы из `laws.formula`, не из `grammar_ops`. (1h)
- [ ] **5d: QueryEngine::topAtoms() from laws** — то же. (0.5h)
- [ ] **5e: Verify scripts адаптация** — переключить на чтение из laws. (0.5h)

**Сложность:** ⭐⭐⭐ | 3h (суммарно)

## Сложность: ⭐⭐⭐⭐ | 7.5h

## E2E
После деплоя: `grep -c 'SPAWN' logs/agenda.log` — spawn'ы должны передавать грамматику.
`verify_1_3` → PASS.
