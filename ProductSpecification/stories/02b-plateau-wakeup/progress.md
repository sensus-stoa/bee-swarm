# Story 02b: Plateau Wakeup (Criterion 1.5 extension)

> HONEST_CRITERIA.md §1.5: exit PLATEAU when new data arrives
> Сейчас: выход из PLATEAU только при открытии. Forager молча приносит задачи — демон спит.

## Spec

1. Forager приносит новые задачи → `$plateauDetector->tick(true)` → выход из PLATEAU
2. Timeout: плато дольше 5 минут → принудительный probe-тик (один цикл с compose)
3. Probe-тик без открытия → обратно в PLATEAU
4. Probe-тик с открытием → выход из PLATEAU, сброс счётчика

## Core

[~] red: test_plateau_wakeup_on_new_data
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: forager → tick(true) integration
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] red: test_plateau_timeout_probe
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: timeout probe mechanism
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor
[ ] verify: daemon restart → forager wakes up PLATEAU

## Status

- Next: `red: test_plateau_wakeup_on_new_data`
