# Story D9: Streaming Forager (SQLite accumulator)

> tMin=10 отсекает 99% foraged-данных. Нужно аккумулировать одинаковые паттерны из сотен файлов.

## Spec

1. `Forager::scan()` → записывает извлечённые данные в SQLite таблицу `forager_data`
2. Ключ: `(pattern_hash, domain)` — одинаковые паттерны из разных файлов группируются
3. При накоплении ≥ tMin точек по паттерну → выпускает задачу
4. Память: O(1) на файл (читаем → пишем в БД → забываем)
5. Совместимость: возвращает тот же формат `[{name, data, domain}, ...]` что и сейчас

## Core

[~] red: test_streaming_accumulates — 5 файлов × 3 точки = 15 точек (проходит tMin)
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: SQLite-backed scan() with accumulator
    [ ] implementation, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve

## Status
- Next: `red: test_streaming_accumulates`
