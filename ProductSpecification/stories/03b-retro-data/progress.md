# Story 03b: Retrospective Data

> 190/202 законов не проверены — нет исходных данных.
> Foraged/generated законы теряют связь с источником.

## Spec

1. Для foraged-законов: матчить имя задачи (foraged_*.md_c0c1) → пересканировать файл
2. Для generated-законов: перегенерировать таск по имени (GEN_sq_abs → sq(abs))
3. retrospectiveValidate() получает полный набор задач
4. Цель: ≥ 90% законов проверены ретроспективно

## Core

[~] red: test_retro_data_foraged — foraged-закон матчится с исходным файлом
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: foraged task reconstruction
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] red: test_retro_data_generated — generated-закон реконструируется
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: generated task reconstruction
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor
[ ] verify: retrospective ≥ 90% coverage

## Status

- Next: `red: test_retro_data_foraged`
