# Story S2.2: Grammar Ceiling Break (NESTED)

> Protocol 2.5-ter: система создаёт операцию, которой нет в стартовой грамматике.

## Спецификация
- TEMPORAL test: y(t) = x(t−1) + x(t−2), grammar без lag
- SEMANTIC test: target=causes, grammar без предиката causes
- Пчела должна создать недостающую операцию через NESTED/compose
- verify_1_5c: решить хотя бы один вариант с CV≤0.01

## Статус
⬜ Backlog — требует: S1-WIRE (популяция), S2-SEM-ATOMS (предикаты как атомы)
