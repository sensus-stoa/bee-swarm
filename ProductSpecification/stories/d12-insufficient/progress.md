# Story D12-INSUFFICIENT: Skip Insufficient Data Tasks Early

> 40% тиков (283/710) — INSUFFICIENT_DATA. Таски отбрасываются ПОСЛЕ routing.
> Нужно фильтровать ДО — не тратить тики на заведомо пустые задачи.

## Почему

Каждый тик: Hive выбирает случайную задачу → routing → doDiscoverTick → проверка tMin.
Если t < tMin: INSUFFICIENT_DATA, тик потрачен впустую.

710 тиков на ноуте: 283 insufficient (40%), 26 открытий (3.7%), 401 без результата.

## Phases

### Phase 1: Pre-filter insufficient tasks
- [x] filterInsufficientTasks(): dedup по max(data_count) + per-task tMin
- [x] tMin = max(10, (nFeat-1) × 5) — синхронизирован с doDiscoverTick
- [x] E2E: insufficient с 78% → 39% (PRE_FILTER: 758 skipped, ещё остаток)
- [x] 426/426 PASS

### Phase 2: Cache tMin per task
- [x] Verbose INSUFFICIENT_FILTERED: имя, t, tMin для каждого отфильтрованного
- [x] E2E: insufficient 36%→2.2% (4/182 тиков, 98% reduction) (df16758)
- [x] Оставшиеся: compose t=0 (doComposeTick, отдельный путь), foraged t=0 (пустой скан)

## Что НЕ делать
- ❌ Не удалять insufficient таски — они могут накопить данные позже
- ❌ Не менять doDiscoverTick — фильтр ДО него

## E2E
E2E: insufficient_rate ↓ (grep -c "INSUFFICIENT_DATA" за 200 тиков -> 0)

## Статус
⬜ Phase 1 — Pre-filter insufficient tasks
⬜ Phase 2 — Cache tMin per task
