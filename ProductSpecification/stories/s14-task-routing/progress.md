# Story S1.4: Competitive Task Distribution

> Protocol 2.4: задачи распределяются пропорционально исторической успешности, а не циклически.

## Спецификация

Из протокола §2.4:
- `wins_i` — число открытий пчелы i
- `attempts_i` — число задач, назначенных пчеле i
- `p_i = (wins_i + 1) / Σ(wins_j + 1)` — вероятность назначения (сглаживание Лапласа)
- Таймаут: `K = 100` тактов → задача отзывается и переназначается
- Пчела, не решившая задачу, всё равно платит `ΔE_search`

## Архитектура

```
TaskRouter {
    bees: Bee[]
    wins: map<bee_id, int>
    attempts: map<bee_id, int>
    
    dispatch(task):      choose bee by p_i
    timeout(task, bee):  reassign if unsolved after K ticks
}
```

## Phases

### Phase 1: Task router
- RED: test_router_weighted_by_wins — пчела с wins=5 получает больше задач чем с wins=1
- GREEN: TaskRouter::dispatch() с вероятностным распределением

### Phase 2: Timeout + reassign
- RED: test_task_timeout_reassign — задача не решена за K тактов → другая пчела
- GREEN: TaskRouter отслеживает pending задачи, перераспределяет

### Phase 3: Wiring
- RED: test_energy_cost_on_failed_task — пчела платит ΔE_search даже при неудаче
- GREEN: интеграция с энергетической моделью Bee

## Метрики цели
- verify_1_4: распределение не равномерно (χ² тест), ≥1 задача отозвана и переназначена

## Status
⬜ Backlog
