# Story 02c: Forager Integration (Infrastructure Prerequisite)

> HONEST_CRITERIA.md §0 — Infrastructure prerequisite
> Без Forager демон не может: выйти из PLATEAU (0.5), накопить данные (0.2),
> открыть законы в новых доменах.

## Spec

Forager подключён к daemon loop и за период наблюдения доставляет:
1. **≥1 новый домен** ИЛИ **≥5 новых задач**
2. Лог содержит `FORAGER_NEW_DOMAIN` или `FORAGER_NEW_TASK`
3. Новые задачи → `$plateauDetector->wakeup()` → выход из PLATEAU
4. Интервал сканирования: каждые N тиков И принудительно при входе в PLATEAU

Это не критерий Stage 0 — это проверка что труба подключена.

## Core

[~] red: test_forager_produces_new_tasks
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: wire Forager::scan() into daemon loop
    [ ] triggered every N ticks + on PLATEAU entry
    [ ] wakeup() on new tasks/domains
    [ ] log FORAGER_NEW_DOMAIN / FORAGER_NEW_TASK
    [ ] full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor
[ ] verify: daemon restart → forager scan → new tasks → PLATEAU exit

## Status

- Next: `red: test_forager_produces_new_tasks`
