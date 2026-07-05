# Story 01: Deduplication (Criterion 1.6)

> HONEST_CRITERIA.md §1.6
> Цель: система не переоткрывает известные законы при рестарте
> PLATEAU_PLAN: 1.1 — починить knownLaws preload

## Spec

При старте `agenda.php` загружает `knownLaws` из БД. 
Сейчас: preload матчит НЕ все форматы ключей (атомы + Search::find).
После фикса: 0 повторов после рестарта.

Measurement: `verify_0_6` — в логе нет пар DISCOVERY с одинаковыми (task_name, formula). 
DB: `SELECT COUNT(*) FROM laws GROUP BY name, formula HAVING COUNT(*) > 1` → 0 rows.

## Core

[~] red: test_knownlaws_preload — все форматы ключей матчатся после рестарта
    [ ] test written, RED confirmed
    [ ] review passed
    [ ] approve (user reviews diff)
[ ] green: knownLaws preload fix
    [ ] implementation done, full suite GREEN
    [ ] approve (user reviews diff)
[ ] refactor
    [ ] structural improvements, full suite GREEN
    [ ] approve (user reviews diff)
[ ] verify: daemon restart → pgrep check → 0 повторов в логе

## Status

- Tests: 0 done
- Next: `red: test_knownlaws_preload`
