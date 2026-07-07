# Story F1: RoadRunner Worker for Bee

> Каждая пчела — отдельный PHP процесс под управлением RoadRunner.
> Hive = HTTP supervisor, пчёлы = workers.

## Спецификация

- RoadRunner 2024.3+ с пулом workers
- Каждый worker = `Bee` instance с уникальной grammar
- Hive раздаёт задачи через HTTP, пчёлы возвращают результат
- Пчела с E≤0 → worker завершается → RoadRunner пересоздаёт с seed grammar

## Текущее состояние

- `.rr.yaml`: pool=4, port=8765, entry=`php src/worker.php`
- `src/worker.php`: НЕТ
- `rr` binary: НЕТ (нужен `vendor/bin/rr` или глобально)

## Phases

### Phase 1: RoadRunner setup
- [ ] Установить `rr` binary
- [ ] Написать `src/worker.php` — минимальный worker с PSR-7 ответом
- [ ] Запустить `rr serve`, проверить HTTP healthcheck

### Phase 2: Bee in worker
- [ ] Worker хранит Bee instance (своя grammar)
- [ ] `POST /task` → Bee обрабатывает → возвращает результат
- [ ] `GET /status` → `{energy, grammar_size, discoveries}`

### Phase 3: Hive as supervisor
- [ ] Hive отправляет задачи пчёлам через HTTP
- [ ] Hive отслеживает статус каждой пчелы
- [ ] При E≤0 — worker умирает, RoadRunner пересоздаёт

## Status
⬜ Backlog
