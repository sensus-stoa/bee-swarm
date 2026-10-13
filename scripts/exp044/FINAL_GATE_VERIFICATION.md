# FINAL GATE VERIFICATION — EXP-044 driver v4 (2026-10-13/14)

Проверка инвариантов финального гейта перед Phase C. Драйвер: v4
(коммит c04af1f, правки: run_task передаёт $trail; WARM экспортирует все
trail marks; SHUFFLED импортирует с asserts). Прогон: DEV-smoke, seed 1,
runner laptop, артефакты `/tmp/exp044_out_s/` (results_*.json,
culture_*.json) — скопированы в дерево документов рядом с этим файлом.

## Инвариант 1 — FIXED реально работает без trail marks

| Проверка | Результат |
|---|---|
| trail_marks в БД после полного прогона FIXED (2 hidden + 4 null задачи) | **0** |
| assert драйвера `fixed_no_trail_marks_assert` | **true** |
| FPR на 4 null-задачах | **0** |

Mechanism: FIXED = NO_BIRTH=1 + TRAIL_MARKS unset (run_task получает
$trail=false от руки) + trail-only записи невозможны.

## Инвариант 2 — SHUFFLED получает ВСЁ культурное состояние WARM

| Проверка | Результат |
|---|---|
| trail marks в WARM-export | **16** записей |
| trail marks после импорта SHUFFLED | **16** записей |
| content-identical (md5 serialize-сравнение) | **true** |
| assert `trail_marks_nonempty` | true (пустой перенос = fail) |
| assert `trail_marks_count_matches_warm` | true |
| assert `atoms_count_matches_warm` | true |
| атомы SHUFFLED отличаются от WARM (пермутированы) | **true** |

Механика переноса: WARM-export `culture_warm_s1.json` содержит atoms +
trail_marks rows; SHUFFLED импортирует записи в чистую :memory: БД
(fingerprint-keyed), затем op-перестановка атомов.

## Инвариант 3 — WARM-NOCULTURE эквивалентна COLD до первой скрытой задачи

| Проверка | Результат |
|---|---|
| state после strip: laws / grammar_ops_birth / trail_marks | **0 / 0 / 0** |
| TRAIN отработан полностью (8→smoke: 3 семьи, found=true) до strip | да |
| born до strip | >0 (культура была и стёрта) |

Полный strip: grammar_ops(birth) + trail_marks + **laws** + кэши
(v3 fix #1). TEST-фаза руки стартует из COLD-эквивалентного состояния.

## Инвариант 4 — FPR на замороженных null-контролях

| Рука | null-задач | сертифицировано null | FPR |
|---|---|---|---|
| warm | 4 | 0 | **0** |
| warm_noculture | 4 | 0 | **0** |
| cold | 4 | 0 | **0** |
| shuffled | 4 | 0 | **0** |
| fixed | 4 | 0 | **0** |

Null-контроли: y-permutation с сидами из freeze-манифеста
(`EXP044NULL`+id, sha в exp044_families.json). Итог: 0 сертификаций на
20 null-задач смока. Phase C повторяет на полном объёме (12 null × 5 рук
× 6 сидов) с гейтом FPR≠0 = HONESTY BLOCKER.

## Сводная таблица смока (seed 1)

| Рука | Сертифицировано | FPR | Культура (state) |
|---|---|---|---|
| warm | 1/2 | 0 | {laws: 2, birth: 1, marks: 16} |
| warm_noculture | 2/2 | 0 | {0, 0, 0} после strip |
| cold | 2/2 | 0 | пустая |
| shuffled | 1/2 | 0 | пермутированная, метки = WARM |
| fixed | 2/2 | 0 | нет |

Честное наблюдение: warm 1/2 vs fixed 2/2 на raw-разрешимых семьях —
beam-вариативность с культурой на единичном сиде; систематика
определяется фазой C на 6 сидах.

## Артефакты

- results/culture JSON: `/tmp/exp044_out_s/` на runner laptop (копии в
  дереве документов рядом с этим файлом)
- driver v4: `scripts/exp044/driver.php` (sha256 в MANIFEST.md v4)
- Phase C по этим семантикам разрешён; критерии prereg v2.2 + amendment
  v2.3 неизменны.
