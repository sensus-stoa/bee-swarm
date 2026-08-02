# S2.2 — Idle-Time Dreaming (§2.5-децим)

## Protocol

> Пчела без задачи не ждёт. Использует idle-такты для cross-domain ассоциативного поиска.

## Фазы

- [x] **Phase 1: Wire idle pathway** — `foundAny=false` → IdleDreamer с расширенной грамматикой (все ops) на всех задачах. `usleep(500_000)` заменён на `idleDreamTick()`. DREAM в логе при открытии. Короткий сон (100ms) при безрезультатном поиске.
- [ ] **Phase 2: T_idle counter + DREAMING mode** — счётчик пустых тиков. При ≥T_idle (10) вход в режим DREAMING. Выход: discovery ИЛИ новый task. DREAMING: более агрессивный поиск (deeper compose, больше пар ops).
- [ ] **Phase 3: DREAMING weight=0.3 + origin marker** — laws получает колонку `origin` (FORAGER/DREAMING/VERIFIED). DREAMING-атомы: weight=0.3, не равны FORAGER-открытиям. recordDiscovery принимает origin.
- [ ] **Phase 4: T_dream_max cap + PLATEAU guard** — максимум 100 тиков в DREAMING. Исчерпан → DREAMING_EXHAUSTED → PLATEAU. Защита от бесконечного dreaming без данных.
- [ ] **Phase 5: Cross-domain law selection** — выбор 2 законов из РАЗНЫХ доменов (не random ops). Compose их формул как бинарные операции. Проверка на held-out данных третьего домена. Настоящий кросс-доменный перенос.

## Сложность фаз

| Phase | Что | Часы | Сложность |
|-------|-----|------|-----------|
| 1 | Wire pathway | 1.5h | ⭐ |
| 2 | T_idle + DREAMING mode | 3h | ⭐⭐ |
| 3 | Weight + origin | 2h | ⭐ |
| 4 | T_dream_max cap | 1.5h | ⭐ |
| 5 | Cross-domain selection | 3h | ⭐⭐⭐ |

## E2E
Phase 2+: `dreaming_rate = DREAM-открытий / час`. Базовая линия после Phase 1.
