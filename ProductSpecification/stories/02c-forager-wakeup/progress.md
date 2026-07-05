# Story 02c: Forager Integration + Plateau Exit

> Wire Forager в daemon loop. Проверить выход из PLATEAU при новых данных.
> Сейчас: forager класс есть, но не вызывается. wakeup срабатывает на count(tasks) — вхолостую.

## Spec

1. Forager::scan() вызывается в daemon loop (каждые N тиков или при plateau)
2. Новые foraged tasks → $plateauDetector->wakeup() → выход из PLATEAU
3. Проверить: PLATEAU → forager scan → новые задачи → выход → compose оживает

## Core

[~] red: test_forager_wakes_plateau
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: wire Forager into agenda.php
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor
[ ] verify: daemon restart → PLATEAU → forager → exit → compose active

## Status

- Next: `red: test_forager_wakes_plateau`
