# Story 01: Deduplication (Criterion 1.6)

> HONEST_CRITERIA.md §1.6
> Цель: система не переоткрывает известные законы при рестарте
> PLATEAU_PLAN: 1.1 — починить knownLaws preload

## Spec

При старте `agenda.php` загружает `knownLaws` из БД. 
BUG: `AtomRegistry::discover()` возвращает 5 атомов для y=x (abs, floor, ceil, round, relu),
но `laws.name UNIQUE` — только первый пишется в БД. После рестарта 4 атома теряются.

Measurement: `verify_0_6` — в логе нет пар DISCOVERY с одинаковыми (task_name, formula).

## Core

[x] red: test_knownlaws_preload — все форматы ключей матчатся после рестарта
    [x] test written, RED confirmed
    [x] review passed (agent-review + premortem)
    [x] approve → committed (0a21c51)
[~] green: knownLaws preload fix
    [ ] implementation done, full suite GREEN
    [ ] review (agent-review + premortem)
    [ ] approve (user reviews diff)
[ ] refactor
    [ ] structural improvements, full suite GREEN
    [ ] review (agent-review + premortem)
    [ ] approve
[ ] verify: daemon restart → pgrep check → 0 повторов в логе

## Status

- Tests: 1 test written (disabled), 0 scenarios passing yet
- Next: `green: knownLaws preload fix`
