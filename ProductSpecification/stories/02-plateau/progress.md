# Story 02: Plateau Honesty (Criterion 1.5)

> HONEST_CRITERIA.md §1.5
> PLATEAU_PLAN: 1.2 + 1.3

## Spec

1. `$consecutiveNoDiscovery` — счётчик тиков без открытий
2. ≥ 50 → PLATEAU: sleep 10s, лог «PLATEAU»
3. На PLATEAU: compose отключен
4. Новое открытие → выход из PLATEAU, сброс счётчика
5. Новые данные (forager) → выход из PLATEAU

## Core

[x] red: test_plateau_detect (944660c)
[x] green: PlateauDetector class (2661775) — 6/6 tests
[~] green: wire PlateauDetector into agenda.php
    [ ] replace manual $consecutiveNoDiscovery with detector
    [ ] full suite GREEN
    [ ] review
    [ ] approve
[ ] red: test_compose_suppress
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: compose suppression
    [ ] implementation done, full suite GREEN
    [ ] review
    [ ] approve
[ ] refactor
[ ] verify

## Status

- 2/5 work units done
- Next: `green: wire PlateauDetector into agenda.php`
