# Story 🔮 S2.12: UnfulfilledRegistry — карта непройденных дорог

> Протокол: дополнение к §3.7 (модель последствий) и §2.5-окто (D/C health)
> Инспирировано: А. Грин «Бегущая по волнам» — концепт Несбывшегося
> Отличие от Failed-Attempt Registry (S1.10-FAIL): там — «пробовали, не вышло». Здесь — «могли, но не пошли, и знаем почему»

---

## Концепт

Система помнит что сделала (законы), что провалилось (Failed-Attempt), но НЕ помнит что сознательно НЕ выбрала на развилке. UnfulfilledRegistry хранит fork points — моменты где система выбрала путь А из доступных А, Б, В. Пути Б и В не потеряны. Они revisitable.

При плато (нет открытий) система обращается к UnfulfilledRegistry и проверяет: есть ли revisitable точки где условия изменились? Если да — возвращается туда.

## Типы fork points

| Тип | Где возникает | Что хранится |
|-----|--------------|-------------|
| **ROUTING_FORK** | TaskRouter выбирает пчелу А из [А, Б, В] | task_fingerprint, chosen_bee, bypassed_bees, reason (winrate, exploration) |
| **DREAMING_FORK** | Directed Dreaming выбирает путь 1 из [1, 2, 3] | start_grammar, goal_grammar, chosen_path, bypassed_paths, reason (distance, TRAP) |
| **MUTATION_FORK** | GrammarMutator выбирает add(x) из [add(x), remove(y), replace(z,w)] | parent_grammar, chosen_mutation, bypassed_mutations, reason (affordance, salience) |
| **EXPLORE_EXPLOIT_FORK** | Hive выбирает exploitation (текущий домен) вместо exploration (новый домен) | current_domain, bypassed_domain, reason (energy, task pressure) |

## Процедура

### Запись (на каждой развилке)

```
UnfulfilledRegistry::record(array $forkPoint):
    - Сохранить: type, timestamp, grammar_state, chosen, bypassed[], reason
    - Индексировать по: grammar_hash, domain, revisitable=true
    - НЕ дублировать: если та же развилка уже записана → update timestamp
```

### Проверка при плато

```
PlateauDetector::justEnteredPlateau() → UnfulfilledRegistry::getRevisitable():
    SELECT * FROM unfulfilled_registry
    WHERE revisitable = true
      AND (
        -- ROUTING_FORK: bypassed bee теперь имеет лучший winrate?
        (type='ROUTING_FORK' AND bypassed_bee_now_better())
        -- DREAMING_FORK: TRAP на bypassed пути исчез?
        OR (type='DREAMING_FORK' AND trap_cleared(bypassed_path))
        -- MUTATION_FORK: salience bypassed опции теперь выше?
        OR (type='MUTATION_FORK' AND salience_shifted())
        -- EXPLORE_EXPLOIT_FORK: энергия теперь позволяет exploration?
        OR (type='EXPLORE_EXPLOIT_FORK' AND energy_allows_exploration())
      )
    ORDER BY age DESC, grammar_distance(current_grammar, fork_grammar) ASC
    LIMIT 5
```

### Возврат

```
Если revisitable точка найдена:
    1. Система возвращается к fork point (virtual_mutation_path)
    2. Выбирает bypassed путь вместо original chosen
    3. Логирует UNFULFILLED_REVISITED
    4. Запись помечается revisitable=false (не бесконечно пересматривать)
    
    Если возврат привёл к открытию:
    5. Запись помечается как PRODUCTIVE_FORK — развилка была значимой
    6. Penalty на original chosen reason (если reason был ошибочным)
```

## Схема БД

```sql
CREATE TABLE unfulfilled_registry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,              -- ROUTING_FORK | DREAMING_FORK | MUTATION_FORK | EXPLORE_EXPLOIT_FORK
    grammar_hash TEXT NOT NULL,      -- SHA256(json_encode(grammar_state))
    grammar_state TEXT NOT NULL,     -- JSON: grammar ops на момент развилки
    domain TEXT,
    chosen TEXT NOT NULL,            -- Что выбрали
    bypassed TEXT NOT NULL,          -- JSON array: что НЕ выбрали
    reason TEXT NOT NULL,            -- Почему выбрали chosen а не bypassed
    revisitable INTEGER DEFAULT 1,   -- Можно ли вернуться?
    revisited_at TEXT,               -- Когда вернулись (null = не возвращались)
    outcome TEXT,                    -- PRODUCTIVE_FORK | DEAD_FORK | null
    generation INTEGER,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX idx_unfulfilled_revisitable ON unfulfilled_registry(revisitable, created_at);
CREATE INDEX idx_unfulfilled_grammar ON unfulfilled_registry(grammar_hash);
```

## Отличие от Failed-Attempt Registry

| | Failed-Attempt (S1.10-FAIL) | UnfulfilledRegistry (🔮 S2.12) |
|---|---|---|
| **Что хранит** | Кандидаты которые ПРОВАЛИЛИСЬ при проверке | Развилки где НЕ ПОШЛИ по альтернативе |
| **Причина записи** | CV > ε_holdout, OVERFIT, TRIVIAL | Routing choice, dreaming choice, mutation choice |
| **Можно ли вернуться** | Нет (провал — это знание) | Да (revisitable=true пока условия не изменились) |
| **Используется при** | Falsification Feedback (penalty) | Плато (re-exploration) |
| **Результат возврата** | N/A | Либо открытие (PRODUCTIVE_FORK) либо подтверждение правильности выбора |

## Phases

### Phase 1: Базовая запись fork points
- [ ] RED: test_records_routing_fork — ROUTING_FORK сохраняется
- [ ] GREEN: UnfulfilledRegistry::record() + БД migration
- [ ] Wiring: TaskRouter::route() → record bypassed bees

### Phase 2: Запись dreaming/mutation forks
- [ ] Wiring: 🧠 G5 (Directed Dreaming) → record bypassed paths
- [ ] Wiring: GrammarMutator::mutate() → record bypassed mutations

### Phase 3: Проверка при плато
- [ ] RED: test_plateau_returns_to_revisitable_fork
- [ ] GREEN: UnfulfilledRegistry::getRevisitable() с condition checks
- [ ] Wiring: PlateauDetector → перед PLATEAU → getRevisitable → execute если есть

### Phase 4: Оценка исходов
- [ ] Возврат привёл к открытию → PRODUCTIVE_FORK
- [ ] Возврат не привёл → DEAD_FORK, revisitable=false
- [ ] Статистика: какой тип fork чаще всего productive

## Метрики

- count(UNFULFILLED_REVISITED) ≥ 1 за период наблюдения
- count(PRODUCTIVE_FORK) ≥ 1 — хотя бы один возврат дал открытие
- verify: скрипт проверяет что revisitable записи не висят вечно (есть revisited_at или удалены по aged_out)

## Сложность

⭐⭐⭐ (3/5), ~7.5 часов

- UnfulfilledRegistry class + DB migration: 2h
- Wiring в TaskRouter: 1h
- Wiring в 🧠 G5 (Directed Dreaming): 1h
- Wiring в GrammarMutator: 0.5h
- PlateauDetector integration: 2h
- Tests: 1h

## Зависимости

- Hard: T0-FIX, S0-QUERY, S1-WIRE (нужна работающая популяция)
- Soft: 🧠 G5 (Directed Dreaming — для DREAMING_FORK), S1.10-FAIL (Failed-Attempt — концептуальное соседство)
- Post-MVP: не критический путь, после Stage 2 base

## Архитектурная заметка

UnfulfilledRegistry замыкает петлю «развилка → плато → возврат → открытие». Без него система либо (а) никогда не возвращается к непройденным путям (статичный выбор), либо (б) возвращается слепо (random exploration). С ним — возвращается осмысленно, в точки где условия изменились.

Это и есть операциональное определение Несбывшегося: не сожаление о несделанном, а карта того что может быть сделано когда придёт время.
