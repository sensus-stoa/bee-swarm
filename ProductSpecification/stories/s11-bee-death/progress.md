# Story S1.1: Bee Death (Starvation)

> Protocol 2.1: процесс пчелы завершается как прямое следствие неспособности находить инварианты.

## Спецификация

Из протокола §2.1:
- `E_0 = 10.0` — начальная энергия
- `ΔE_search = −0.1` — стоимость попытки поиска
- `ΔE_discovery = +2.0` — награда за открытие
- `ΔE_tick = −0.01` — базовый метаболизм за такт
- При `E_i ≤ 0` → `exit(1)`. Энергия не уходит в минус.

## Архитектура

```
Bee {
    energy: float
    tick():        E -= 0.01
    search():      E -= 0.1
    discovery():   E += 2.0
    isAlive():     E > 0
}
```

Сейчас Hive — монолит. Нужно:
1. Выделить `Bee` как отдельный процесс (или класс с энергетической моделью)
2. Перенести discover-логику из Hive в Bee
3. Bee умирает при E ≤ 0

## Phases

### Phase 1: Bee energy model
- RED: test_bee_energy — E0=10.0, tick costs 0.01
- GREEN: Bee class с energy tracking

### Phase 2: Bee death
- RED: test_bee_dies_at_zero — E ≤ 0 → isAlive=false
- GREEN: Bee::tick() проверяет energy, выбрасывает BeeDiedException или возвращает статус

### Phase 3: Wiring
- RED: test_bee_dies_after_N_failed_searches — симуляция тактов без открытий
- GREEN: Hive заселяет пчелу, пчела умирает при истощении

## Метрики цели
- verify_1_1: минимум 1 смерть за период, все смерти коррелируют с E ≤ 0

## Status
⬜ Backlog
