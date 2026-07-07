# Story S1.2: Bee Birth (Spawn + Mutation)

> Protocol 2.2: выжившая пчела с достаточной энергией порождает дочерний процесс с мутированной грамматикой.

## Спецификация

Из протокола §2.2:
- `E_spawn = 15.0` — порог размножения
- `ΔE_spawn = −7.0` — стоимость размножения для родителя
- `E_child = 7.0` — стартовая энергия потомка
- `mutate(G)`: добавить/удалить/заменить одну случайную операцию
- Дочерний процесс — новый процесс ОС (proc_open)

## Архитектура

```
Bee (parent)                 Bee (child)
  E=15.0                       E=7.0
  grammar = {+, ×, abs}        grammar = mutate({+, ×, abs}) = {+, ×, lag}
       |                              |
       +—— spawn() ——→                run()
  E=8.0
```

## Phases

### Phase 1: Mutation
- RED: test_mutate_adds_or_removes — grammar меняется ровно на 1 операцию
- GREEN: GrammarMutator::mutate(array $ops): array

### Phase 2: Spawn
- RED: test_bee_spawns_child — parent E≥15, после spawn E=8, child E=7
- GREEN: Bee::spawn() возвращает новый Bee с мутированной grammar

### Phase 3: OS process spawn
- RED: test_spawn_creates_os_process — дочерний PID ≠ родительский
- GREEN: proc_open('php bee.php --grammar=...'), мониторинг PID

### Phase 4: Wiring
- RED: test_population_grows — за N тактов count(пчёл) увеличивается
- GREEN: Hive отслеживает популяцию, spawn при E≥15

## Метрики цели
- verify_1_2: ≥3 spawn-событий, родитель ≠ потомок (PID), grammar разная

## Status
⬜ Backlog
