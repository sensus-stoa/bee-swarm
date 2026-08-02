# V1 — Стадия 1: формальная верификация

## Статус: ⬜ 0/5 критериев верифицированы. Главный барьер: V1.3 grammar isolation.

## Критерии и их статус

| # | Критерий | Код | Verify-скрипт | Барьер |
|---|----------|-----|---------------|--------|
| 2.1 | Реальная смерть | ✅ DEATH log, энергомодель | ❌ | — |
| 2.2 | Реальное рождение | ✅ SPAWN log, grammar mutate | ❌ | — |
| **2.3** | **Грамм. изоляция** | **❌ общая grammar_ops** | ❌ | 🔴 архитектурный |
| 2.4 | Конкурентное распределение | ✅ TaskRouter + Laplace | ❌ | — |
| 2.5 | Эволюционная динамика | ⚠️ GEN log, diversity | ❌ | — |

## План

### 🔴 V1.3: Grammar Isolation (4.5h)
Архитектурное изменение. См. `v1.3-grammar-isolation/progress.md`.
Фазы: per-bee grammar → spawn inheritance → Hive wiring → verify_1_3.

### 🟡 V1.0-V1.5: Verify scripts (6h)
Написать 5 скриптов по аналогии с Stage 0:
- `verify_1_1.php` — смерть: ≥1 DEATH за 24h, все коррелируют с E≤0
- `verify_1_2.php` — рождение: ≥3 SPAWN за 24h, родитель≠потомок, G_child≠G_parent
- `verify_1_3.php` — изоляция: нет необъяснённых идентичных грамматик (после V1.3)
- `verify_1_4.php` — маршрутизация: распределение не равномерно (χ²), ≥1 переназначение
- `verify_1_5.php` — динамика: generation tracking, diversity > 0

### 🔴 V1.9: 24h Production Gate (24h wall time)
`verify_all.php --production --stage=1` → 5/5 PASS + count(SPAWN) ≥ 3 + count(DEATH) ≥ 1.

## E2E
V1.9: `verify_all.php --production --stage=1 --log=logs/agenda.log` → STAGE 1 PASS.
