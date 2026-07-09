# Story S1-WIRE: Hive Population Wiring

> Подключить Bee + TaskRouter + RoadRunner к Hive. Превратить монолитный agenda.php в супервизор популяции.

## Почему сейчас нельзя измерить S1.5

```
Сейчас:     agenda.php → Hive::tick() → монолит, 1 grammar, 1 цикл
Нужно:      Hive супервизор → TaskRouter → N пчёл в RoadRunner workers
            → energy tracking → spawn → death → эволюция
```

Без wiring'а S1.4 (роутинг) и S1.5 (динамика) — мёртвый код. Тесты проходят, в продакшене не используется.

## Архитектура цели

```
Hive (supervisor)
├── Forager → задачи
├── TaskRouter → кому задачу?
├── RoadRunner pool (4 workers)
│   ├── Bee 1 (grammar₁, E₁)  ← /task
│   ├── Bee 2 (grammar₂, E₂)
│   ├── Bee 3 (grammar₃, E₃)
│   └── Bee 4 (grammar₄, E₄)
├── Spawn manager → E≥15 → proc_open bee
├── Death monitor → pgrep, упавшие → логи
└── Metrics → FCI, diversity, |G|
```

## Phases

### Phase 1: BeeWorker → реализация discover в воркере
- [ ] RED: BeeWorker::handleTask вызывает реальный Search::find
- [ ] GREEN: task JSON → BeeWorker извлекает X/y → Search::find → результат
- [ ] worker.php отдаёт {discovery: формула, cv: 0.001} или {discovery: null}

### Phase 2: Hive → TaskRouter вместо random
- [ ] Hive::tick() получает задачу → TaskRouter::route($task) → bee_id
- [ ] Hive отправляет HTTP POST /task на выбранного воркера
- [ ] Hive получает результат, вызывает TaskRouter::recordOutcome()

### Phase 3: Energy loop замыкается
- [ ] Успех → Bee.rewardDiscovery() (через HTTP /reward)
- [ ] Неудача → энергия уже списана (chargeSearch в handleTask)
- [ ] Такты → Bee.tick() (метаболизм)
- [ ] E≤0 → worker завершается (exit или RR убивает)

### Phase 4: Spawn loop
- [ ] Hive мониторит /status всех воркеров
- [ ] E≥15 → Hive отправляет команду spawn (новый worker с мутированной grammar)
- [ ] Dead worker → RoadRunner респавнит с seed grammar

### Phase 5: Metrics → S1.5 ready
- [ ] Hive собирает: поколения, FCI, |G|, diversity
- [ ] Данные пишутся в лог → verify_1_5 может работать

## Зависимости

- F1 ✅ RoadRunner workers running
- S1.1 ✅ Bee energy model
- S1.2 ✅ GrammarMutator + spawn
- S1.3 ✅ Grammar isolation
- S1.4 ✅ TaskRouter

## Статус
⬜ Backlog — критическая зависимость для S1.5
