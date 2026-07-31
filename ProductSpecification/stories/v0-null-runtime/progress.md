# Story V0: Runtime Null-Calibration

> Protocol: null-calibration (shuffle-based FPR=0 floor, per-fingerprint ε_train).
> Замена hardcoded CV≤0.01 на честный статистический порог.
>
> БЕЗ этого любое расширение грамматики (reduce, окна, лаги) — бессмысленно.
> 11 часов / 0 открытий на реальных данных — не проблема грамматики, а критерия.

## Почему

**Диагностика 01.08.2026 (metrics + forager + vault):**
- 140+ задач: 35 metrics (2 колонки) + 105 foraged_num (3-24 колонки) из vault
- CV≤0.01 НЕДОСТИЖИМ на реальных данных: глобальный min CV = 0.017
- 18/35 метрик-задач имеют minCV < 0.10 простыми кандидатами (структура есть!)
- 10/35 имеют |corr| > 0.30, 9 имеют R² > 0.10 (линейная структура)
- Лаговая структура: stress→cardio k=4 corr=−0.689, gi→cardio k=7 +0.440
- В production DB: 1036 grammar_ops, 0 законов из реальных данных

**Консенсус 3 экспертов (01.08.2026):**
- Эксперт №1 (GP-ортодокс): «Сначала проверь данные — если мин CV > 0.3, проблема в критерии»
- Эксперт №2 (open-ended): «Null-calibration обязателен ДО запуска»
- Эксперт №3 (PySR-практик): «Без смены критерия никакое расширение грамматики не даст открытий — главный сигнал»

**Механика из references/null-calibration.md (31.07.2026):**
- N = 100 shuffle trials: $shuffled = $y; shuffle($shuffled) → Search::find($X, $shuffled, grammar, 2)
- ε_null = 99-й перцентиль лучшего CV по пермутациям (FPR=0 floor)
- Открытие: CV_train < ε_null **И** CV_holdout < ε_null
- Три зоны: ★ DISCOVERY (< ε_train), 🔥 SIGNAL (< ε_null), 📈 IMPROVEMENT (< best_prev), ☠ DEAD
- Per-fingerprint (структурный fingerprint задачи), не per-domain
- Runtime: 100 shuffle × Search::find(depth=2) ≈ 15-20 мин на laptop на SHUFFLED данных (NOT full grammar)

**Питфол из скилла (Null-calibration OOM 31.07.2026):**
- Search::find depth=2 с 551 ops → OOM-kill даже при 512MB
- Фикс: depth=1, restrictTo(BASE_OPS) при калибровке — shuffle-данные не требуют depth>1

## Phases

### Phase 1: NullCalibrator — shuffle-based ε_null
- [ ] RED: NullCalibratorTest — на синтетических данных (y = сигнал + шум)
- [ ] GREEN: NullCalibrator::calibrate($X, $y, $grammar, $nPerms=100) → float ε_null
- [ ] LINT + REVIEW (sub-agent commit)

### Phase 2: Интеграция в Hive::doTick
- [ ] replace hardcoded 0.01 → null-калиброванный порог per-fingerprint
- [ ] Three-zone reward model: DISCOVERY/SIGNAL/IMPROVEMENT/DEAD
- [ ] Калибровка при первом encounter нового fingerprint (calibration phase, потом search phase)

### Phase 3: Проверка на реальных данных
- [ ] Прогон на metrics.jsonl — ε_null для sleep→energy
- [ ] Сравнение: сколько законов с CV<0.01 vs CV<ε_null
- [ ] Проверка, что overfit/шум НЕ проходит (held-out + permutation test)

## Что НЕ делать
- ❌ Не gradient reward (NovaMind-3 anti-pattern) — зоны только пороговые
- ❌ Не глобальный порог — per-fingerprint calibration
- ❌ Не depth=2 с полным grammar при калибровке (OOM) — depth=1 + BASE_OPS

## Зависимости
- S1.9 ✅ reduce(op, vector) — метод для нормировки в Search
- S1.10 ✅ :memory: test DB — быстрое тестирование

## Статус
⬜ Phase 1 — RED
