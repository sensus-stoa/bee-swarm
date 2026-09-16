# V0.11-ENSEMBLE WU-5: Калибровочный прогон §1.9 (16.09.2026)

> Артефакт Appendix B: preregistered калибровка EnsembleCertifier (§1.9 v1.7-draft).
> Скрипт: `scripts/verify/verify_1_9.php` (bee_swarm ccf7ea4). Логи: `/tmp/v011_wu5_*.log` (ноут).
> Прогон: run5 `--quick`, n=200, k=3..8, budget 20s, grid [0.05..0.15], null 2×2, beam OFF.

## Конфигурация (env-факт, стартовая строка лога)

```
ENV SWARM_DB_PATH=:memory: FORAGER_SOURCES=: NO_BIRTH=1
ENV SEARCH_NO_PREREG=1 SEARCH_BEAM_K=0 (beam срезает родителей compose — проба 16.09)
ENV ENSEMBLE_BUDGET_SEC=20
```

## Результаты (run5, финальный)

| # | Домен | Класс (prereg) | Ожидание | Вердикт | rec | cvH | nullMax | anchor |
|---|---|---|---|---|---|---|---|---|
| 1 | feynman_dot | точный закон (Demo#3 эталон) | ENSEMBLE_CERT | **ENSEMBLE_CERT** | 1.0 | 0 | 0 | 1.0 |
| 2 | feynman_kinetic | точный закон | ENSEMBLE_CERT | **ENSEMBLE_CERT** | 1.0 | 0 | 0 | 1.0 |
| 3 | kinetic_noise5 | закон + 5% шум | CERT (форма устойчива) | **ENSEMBLE_CERT** | 1.0 | 0.0553 | 0 | 0.9995 |
| 4 | airfoil_selfnoise | эмпирика, не закон | NO_CONSENSUS | **NO_CONSENSUS** | 0.33–0.67 | 0.045–0.061 | 0 | ~0 |
| 5 | CCPP (дегенерат Demo#2) | adversarial | UNSTABLE/NO_CONS | **NO_CONSENSUS** | 0.667 | 0.0134 | 1 | −0.49 |
| 6 | coulomb_noise15 | закон + 15% шум | CERT* | NO_CONSENSUS | 0 | — | — | — |

\* coulomb: НЕ гейт-провал V0.11 — theta-сетка [0.05..0.15] ниже честного cv домена
(0.178 при 15% шуме; проба: все члены found=false, при theta=0.2 три из трёх находят
одну форму `((x0×(x1/R+x1))/x2²)`). Известная «мертвая зона» двухступенчатого порога
(питфолл 14.09). Разрешение pre-registered: **V0.16-EPSPARITY** (верификатор наследует
calibrateEpsilon первооткрывателя). Механика отказала в безопасную сторону
(отказ ≠ ложный CERT).

## Ключевые находки

1. **Дегенерат Demo #2 не сертифицирован.** Формула `((AT/RminAT)−RrangeRH)`
   (pred ≈ −65 MW при реальных PE 420–495, corr = −0.948) распалась на ДВЕ семьи
   дегенератов при K=8 (`((x0+x1)+(x0−x2))` 4/8 + `(x0/Rminx0)−Rmax*` 4/8) —
   гейт (a) 0.8 от foundN не пройден, null-gate (nullMax=1) не пройден.
   → Цель стори достигнута: точечный сертификат Demo #2 противостоял бы
   геометрии одной выборки; структурный — нет.
2. **Ложных CERT: 0 из 6 доменов.** Ложных отказов сверх известной границы
   сетки: 0 (airfoil флак rec 0.33/0.67 между прогонами — эмпирика в обоих
   случаях отказана).
3. **Anchor работает:** m̂=1.0 на точных законах (форма несёт масштаб),
   m̂≈1.0 на noise5, отрицательный m̂ у airfoil/CCPP = честная статистика
   (знак — информация; сверка знака — зона ко-гейта V0.14, не ансамбля).

## История прогонов (триаж)

| Прогон | Симптом | Root cause | Фикс |
|---|---|---|---|
| 1 | все NO_CONSENSUS rec=0 | budget 5s < wall find на бутстрэпе depth3 (dot n=120: 12.8s при budget≥15) | budget 20s |
| 1 | coulomb ERROR abs(null) | CRLF-мусор в labels → floatval мусора | loadCsv labels-гард |
| 2-3 | dot NO_CONSENSUS | target=col5, а закон в col6 (пробой python) | target=6 |
| 3 | старый скрипт в прогоне | scp-рассинхрон (md5 не сходился) | grep-маркер после scp |
| 5 | финал | — | — |

## Выводы

- §1.9 работает: ENSEMBLE_CERT отличает закон (включая закон+шум) от
  эмпирики и дегенерата; UNSTABLE/NO_CONSENSUS — в безопасную сторону.
- Экономика: k×budget на домен (6×20s ≈ 2 мин --quick; прод K=25 ≈ 8-10 мин).
- Граница: верификаторская сетка θ — параметр домена; V0.16-EPSPARITY
  снимает ручную настройку.
- Направление V0.13 (CONTRADICTION): отрицательный anchor CCPP
  (corr −0.948) — тот же сигнал, который V0.14 уже показал как «закон
  в зеркале» (−(+) 5/5 PAID).
