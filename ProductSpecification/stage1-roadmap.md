# Stage 1 Roadmap — Decomposition

> Multi-bee evolution через RoadRunner workers.
> Каждый критерий = story, каждая story = 3-5 work units TDD.

## Фундамент (до Stage 1)

| Story | Что | Сложность | Work Units |
|-------|-----|-----------|------------|
| D8 | Forager caps | ⭐ 1h | maxTotal env, 500K chunking — 2 units |
| D9 | `ExpressionTree::nodeCount()` | ⭐ 15m | 2 units |
| F1 | RoadRunner worker для пчелы | ⭐⭐ 2h | worker.php → `BeeWorker` класс, `.rr.yaml` multiprocess — 3 units |

## Stage 1 — Лёгкие

| Story | Критерий | Work Units |
|-------|----------|------------|
| S1.1 | 2.1 Смерть | `StarvationDetector` — энергия + N тиков без открытий → exit |
| S1.3 | 2.3 Изоляция | `GrammarIsolate` — у каждого worker своя `swarm_bee_{id}.db`, отдельный `grammar_ops` |
| S1.4 | 2.4 Конкуренция | `TaskScheduler` — weight = successes / (successes + failures), weighted random |

## Stage 1 — Средние

| Story | Критерий | Work Units |
|-------|----------|------------|
| S1.2 | 2.2 Рождение | `BeeSpawner` — fork worker с mutated grammar (random ±1 operation), наследование родительской БД |
| S1.5 | 2.5 Динамика | `PopulationMetrics` — grammar size distribution, diversity index, log `evolution_stats.jsonl` |

## Stage 1 — Сложные (= Stage 2 по протоколу)

| Story | Критерий | Work Units |
|-------|----------|------------|
| S2.1 | 2.5-бис Рост | `CapabilityTracker` — unsolved tasks registry, generation-over-generation solution rate |
| S2.2 | 2.5-тер Потолок | `NESTED` — `ExpressionTree::mutate()`, поиск новых операций через CV→0 над нерешёнными |
| S2.3 | 2.5-кватер Противоречие | `ContradictionResolver` — detect conflicting formulas for same task, spawn resolution task |

## Work Unit Template (каждая story)

```
red:    test_{feature} — 2-3 failing tests
green:  minimal implementation + wire
refactor: extract helpers, remove duplication
verify:  run in multi-worker environment, check logs
```

## Приоритет

```
D9 → D8 → F1 → S1.1 → S1.3 → S1.4 → S1.2 → S1.5 → E1 → D7 → S2.*
 15m   1h    2h     1h      1h      1h      2h      2h     2h    2h    ...
```
