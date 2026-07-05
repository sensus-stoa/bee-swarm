# Story 02: Plateau Honesty (Criterion 1.5)

> HONEST_CRITERIA.md §1.5
> PLATEAU_PLAN: 1.2 + 1.3

## Spec

1. `$consecutiveNoDiscovery` — счётчик тиков без открытий
2. ≥ 50 → PLATEAU: sleep 10s, лог «PLATEAU»
3. На PLATEAU: compose отключен
4. Новое открытие → выход из PLATEAU, сброс счётчика
5. Новые данные (forager) → выход из PLATEAU

Parameters (E): P=50 ticks, T_plateau=60s. Arbitrary but declared.

## Core

[~] red: test_plateau_detect — 50 ticks no discovery → PLATEAU log + sleep
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: plateau detector implementation
    [ ] implementation done, full suite GREEN
    [ ] review
    [ ] approve
[ ] red: test_compose_suppress — compose=0 at plateau
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: compose suppression
    [ ] implementation done, full suite GREEN
    [ ] review
    [ ] approve
[ ] refactor
    [ ] structural improvements, full suite GREEN
    [ ] review
    [ ] approve
[ ] verify: daemon restart → pgrep check → plateau log appears after idle

## Status

- Next: `red: test_plateau_detect`
