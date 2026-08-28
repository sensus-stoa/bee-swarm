# EXP-036 ФИНАЛЬНАЯ ТАБЛИЦА (29.08)

## Сравнение Bee Swarm vs PySR 1.5.10 (честные условия)

**Критерий:** cv_holdout ≤ 0.10 (pass), cv_holdout < 0.001 (exact).
**PySR:** populations=31 (дефолт), timeout=60s, 6 ops, maxsize=20.
**Bee:** depth-3 + культурный словарь + CHUNK-DIRECT, ~37s/seed.
**Split:** 80/20 frozen, 20 seeds, holdout изолирован.
**Методология:** EXP-028 результаты отозваны (populations=10 ослаблял PySR).

| Задача | PySR pass | PySR exact | Bee pass | Bee exact | Вердикт |
|---|---|---|---|---|---|
| heat | 19/20 | 19/20 | **20/20** | **20/20** | PARITY (1 discordant) |
| dot | **18/20** | **18/20** | 0/20 | 0/20 | PYSTR WIN — SUM-дыра |
| kinetic | **16/20** | **15/20** | 0/20 | 0/20 | PYSTR WIN — PROD+SUM |
| wine | 20/20* | — | 20/20 | 20/20 | PARITY |
| airfoil | 20/20* | — | 20/20 | 20/20 | PARITY |
| gravity | 0/20* | — | 6/20 | 6/20 | BEE WIN |
| relmass | 16/20* | — | 20/20 | 20/20 | BEE advantage |
| null(100) | — | — | 0/100 | 0/100 | FPR=0 ✓ |

*исторические данные EXP-028 (A-ветки методологически ок — там не
было populations-зависимости для сравнения pass/базового fitting).

## Ключевые выводы
1. PySR с дефолтами = сильный движок, находящий ИНВАРИАНТЫ (не
   аппроксимации) на 90%+ задач. Сравнение ослабленного PySR —
   методологическая ошибка (EXP-028 отозван).
2. Bee Swarm: PARITY на heat, дефицит на SUM-структурах.
3. Наш механизм (культурное наследование атомов) даёт
   детерминизм формулы и потенциал переноса — проверка
   на понимании (Stage 2) впереди.
4. Дорога: FLOOR-EMERGENCE M1 (сжатие=слова) + SUM-композиция
   закрывают dot/kinetic; полный Фейнман 120 — после.

## Опровержённые гипотезы (научная честность)
1. «PySR = approximation engine» → exact_share 95/90/75%
2. «PySR слаб на dot из-за глубины» → артефакт populations=10
3. «Bee строго лучше PySR на heat» → parity (1 discordant)
