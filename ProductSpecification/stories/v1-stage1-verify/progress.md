# Story V1: Stage 1 Formal Verification

> Протокол: все критерии 2.1–2.7 + расширения должны вернуть pass за 7 дней, ≥100 поколений, ≥1 extinction+recovery.

## Что есть

| Критерий | verify-скрипт | Статус |
|----------|--------------|--------|
| 2.1 Death | `verify_1_1.php` | ✅ скрипт есть, PASS на production |
| 2.2 Birth | `verify_1_2.php` | ✅ скрипт есть, FAIL (0 spawns) |
| 2.3 Grammar Isolation | `verify_1_3.php` | ✅ скрипт есть |
| 2.4 Task Routing | `verify_1_4.php` | ✅ скрипт есть |
| 2.5 Evolution Dynamics | `verify_1_5.php` | ✅ скрипт есть, FAIL (GEN=0) |
| 2.5-bis Capability Growth | — | ❌ нет скрипта |
| 2.5-ter Grammar Ceiling | — | ❌ нет скрипта |
| 2.5-кватер Contradiction | — | ❌ нет скрипта |
| 2.5-септим Bloat Guard | — | ❌ нет скрипта |
| 2.5-квинкве Law Preservation | — | ❌ нет скрипта |
| 2.5-секст Falsification | — | ❌ нет скрипта |
| 2.5-нона Inference | — | ❌ нет скрипта |
| 2.6 Env Pressure | — | ❌ нет скрипта |
| 2.7 Resilience | — | ❌ нет скрипта |
| **Stage 1 Gate** | `verify_all.php --stage=1` | ❌ только Stage 0 |

## Что нужно

### Phase 1: Базовый проход (minimal viable Stage 1)
[ ] SPAWN_THRESHOLD fix или gap-spawn → поколения текут
[ ] verify_1_1…1_5 → PASS
[ ] verify_all.php --stage=1 → Stage 1 Gate

### Phase 2: verify-скрипты для расширений
[ ] verify_1_5b (capability growth)
[ ] verify_1_5c (grammar ceiling)
[ ] verify_1_5d (contradiction)
[ ] verify_1_5e (law preservation)
[ ] verify_1_5g (bloat guard)
[ ] verify_1_5i (inference)
[ ] verify_1_5k (dreaming)
[ ] verify_1_6 (env pressure)
[ ] verify_1_7 (resilience)

### Phase 3: Production gate
[ ] 7 дней непрерывного прогона
[ ] ≥100 поколений
[ ] ≥1 extinction+recovery

## Status
🔧 Phase 1 — unblocked after gap-spawn. Phase 2-3 — backlog.
