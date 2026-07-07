# Story S1.3: Grammar Isolation

> Protocol 2.3: пчела не может получить доступ к грамматике другой пчелы иначе чем через наследование.

## Спецификация

Из протокола §2.3:
- Грамматика каждой пчелы — приватная (отдельная таблица SQLite `grammar_ops_{bee_id}` или в памяти)
- Нет общей таблицы `grammar_ops`
- При spawn: родитель сериализует G → потомок десериализует + мутирует
- После spawn грамматики расходятся независимо

## Текущая проблема

Сейчас `grammar_ops` — общая таблица. Все пчёлы (и демон) читают/пишут одну grammar. Это ломает эволюцию: мутация одной пчелы немедленно видна всем.

## Архитектура

```
Сейчас:                     Stage 1:
  grammar_ops (shared)        bee_1_grammar (SQLite table)
       ↑                     bee_2_grammar (SQLite table)
  Bee1 Bee2 Hive             bee_3_grammar (in-memory)
                              ↑
                            Hive (router only, no grammar)
```

## Phases

### Phase 1: Grammar per bee
- RED: test_bee_has_own_grammar — G1 ≠ G2 для двух пчёл после мутации
- GREEN: Bee хранит grammar в私有ном массиве, не в глобальной БД

### Phase 2: SQLite isolation
- RED: test_grammar_table_per_bee — таблица `grammar_ops_{id}` содержит только операции одной пчелы
- GREEN: Bee::getGrammar() → своя таблица, Bee::addOp() → пишет только в свою

### Phase 3: Inheritance only
- RED: test_no_cross_contamination — операция, добавленная пчелой A в рантайме, не видна пчеле B
- GREEN: проверить что нет общего состояния между пчёлами

### Phase 4: Migration
- RED: test_old_grammar_still_works — baseline grammar доступна всем при рождении
- GREEN: при spawn сериализация baseline B + мутация → потомок

## Метрики цели
- verify_1_3: нет необъяснённых идентичных грамматик (G_i ≠ G_j для живых ≥10 тактов)

## Status
⬜ Backlog
