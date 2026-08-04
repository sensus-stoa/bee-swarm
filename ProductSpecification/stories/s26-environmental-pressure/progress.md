# Story S2.6: Environmental Pressure

> Протокол §2.6: среда должна создавать давление на популяцию.

## Spec

```
Периодические события среды, влияющие на популяцию:
- DATA_DROUGHT: нет новых задач → ускоренный метаболизм (ΔE_tick×2)
- DATA_FLOOD: >100 задач в пуле → spawning bonus (+0.5 к энергии за спавн)
- DOMAIN_SHIFT: новый домен → временный boost к diversity
Измерение: verify_1_6 проверяет наличие ≥1 события в логе
```

## Core

[ ] red: test_data_drought_accelerates_metabolism — no tasks → tick cost ×2
[ ] red: test_data_flood_bonus — >100 tasks → spawn energy bonus
[ ] red: test_domain_shift_boosts_diversity — new domain → diversity bump
[ ] green: EnvironmentalPressure::tick() in Hive
[ ] green: wiring in doTick()
[ ] refactor + lint

## Work Units

[ ] red: test_data_drought_accelerates_metabolism
[ ] red: test_data_flood_bonus
[ ] red: test_domain_shift_boosts_diversity
[ ] green: EnvironmentalPressure class
[ ] green: wiring
[ ] tests pass + review

## Status
- Next: `red: test_data_drought_accelerates_metabolism`
