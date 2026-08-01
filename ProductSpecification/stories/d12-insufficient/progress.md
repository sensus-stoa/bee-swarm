# Story D12-INSUFFICIENT: Skip Insufficient Data Tasks Early

> 40% тиков (283/710) — INSUFFICIENT_DATA. Таски отбрасываются ПОСЛЕ routing.
> Нужно фильтровать ДО — не тратить тики на заведомо пустые задачи.

## Почему

Каждый тик: Hive выбирает случайную задачу → routing → doDiscoverTick → проверка tMin.
Если t < tMin: INSUFFICIENT_DATA, тик потрачен впустую.

710 тиков на ноуте: 283 insufficient (40%), 26 открытий (3.7%), 401 без результата.

## Phases

### Phase 1: Pre-filter insufficient tasks
- [ ] Hive::getTasks(): параметр `$minDataPoints` — фильтровать таски с data < tMin
- [ ] tMin = max(10, nFeat * 5) — как в doDiscoverTick
- [ ] Таски без `data` ключа (text/semantic) — пропускать (не фильтровать)
- [ ] Лог: `PRE_FILTER: skipped N insufficient tasks`
- [ ] E2E: grep -c "INSUFFICIENT_DATA" после 200 тиков должно быть 0

### Phase 2: Cache tMin per task
- [ ] Таски не меняют размер — вычислить tMin один раз при создании
- [ ] Хранить `tMin` в task-структуре или вычислять lazy

## Что НЕ делать
- ❌ Не удалять insufficient таски — они могут накопить данные позже
- ❌ Не менять doDiscoverTick — фильтр ДО него

## E2E
E2E: insufficient_rate ↓ (grep -c "INSUFFICIENT_DATA" за 200 тиков -> 0)

## Статус
⬜ Phase 1 — Pre-filter insufficient tasks
⬜ Phase 2 — Cache tMin per task
