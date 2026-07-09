# Story S1.1: Bee Death (Starvation)

> Protocol 2.1: процесс пчелы завершается как прямое следствие неспособности находить инварианты.

## Спецификация

Из протокола §2.1:
- `E_0 = 10.0` — начальная энергия
- `ΔE_search = −0.1` — стоимость попытки поиска
- `ΔE_discovery = +2.0` — награда за открытие
- `ΔE_tick = −0.01` — базовый метаболизм за такт
- При `E_i ≤ 0` → `exit(1)`.

## Phases

### Phase 1: Bee energy model ✅
- [x] RED — Class not found
- [x] GREEN — Bee class: energy(), tick(), chargeSearch(), rewardDiscovery()

### Phase 2: Bee death ✅
- [x] RED → GREEN — isAlive() = E > 0, смерть при E≤0

### Phase 3: Wiring into Hive
- [ ] Заменить текущий монолитный Hive::tick() на Bee-centred цикл
- [ ] Bee получает задачи через Hive, платит энергию, умирает

## Метрики цели
- verify_1_1: минимум 1 смерть за период, все смерти коррелируют с E ≤ 0

## Status
🔧 Phase 3 — wiring (ждёт F1 RoadRunner или proc_open)
