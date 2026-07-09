# Story S1.5-FCI: Functional Complexity Index for Grammar Evolution

> Инспирировано: Deng et al. (2025) "Dendritic morphology and synaptic nonlinearities enhance functional complexity in human cortical neurons", PNAS.

## Идея

Нейроны с более сложной дендритной морфологией имеют более высокий FCI (Functional Complexity Index) — способность выполнять больше разных функций. Аналогично: grammar с более выразительной топологией имеет более высокий FCI — способность решать больше разных классов задач.

**Сейчас (протокол §2.5):** средний |G|↓ через поколения — grammar сжимается.
**Проблема:** |G|↓ может означать не сжатие, а потерю. Пчела удалила все ops кроме `add` — |G|=1, FCI=0.
**С FCI:** |G|↓ при FCI↑ — grammar сжимается НО coverage растёт. Настоящая эволюция.

## Спецификация

```php
FCI(bee) = count(уникальных fingerprint'ов задач, которые bee решила за последние W тактов)
         / count(решаемых fingerprint'ов лучшей пчелой в популяции)
```

Нормализованный показатель: 0.0 = ничего не решает, 1.0 = решает всё что и лучшая пчела.

## Интеграция с протоколом

Протокол §2.5 требует: `средний |G|_gen100 < средний |G|_gen20`. 
Предлагается дополнительно: `средний FCI_gen100 ≥ средний FCI_gen20`.

Это не замена критерия, а усиление. Grammar сжимается И coverage не падает.

## Phases

### Phase 1: FCI metric
- [ ] RED: test_fci_zero_for_empty_bee — пчела без решённых задач → FCI=0
- [ ] GREEN: Bee::fci() с TaskRouter.history

### Phase 2: FCI tracking in evolution
- [ ] DiversityMonitor считает FCI популяции
- [ ] verify_1_5: FCI_gen100 ≥ FCI_gen20 (дополнительно к |G|↓)

### Phase 3: FCI-driven mutation
- [ ] Если FCI падает 3 поколения подряд → mutation rate повышается
- [ ] Топология grammar важнее размера — compose-цепочки > одиночные ops

## Статус
⬜ Backlog — после S1.5 базовой
