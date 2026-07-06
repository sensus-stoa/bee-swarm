# Stage 0 Bugfix Stories

> Результаты аудита против протокола. 2 CRIT, 3 MEDIUM.

## Критические

### B1: Compose обходит held-out (§1.1)

- `doComposeTick()` вызывает `discoverCompose()` — без train/test split
- Compose-законы попадают в БД через `INSERT OR IGNORE` без проверки `cv_holdout ≤ 0.10`
- **Fix:** заменить `discoverCompose()` на held-out-aware версию, или добавить `LawValidator::validate()` после

### B2: Regex isTrivial сломан + compose не фильтруется (§1.4)

- Ошибка A: `'/^(add|\\\\+)\\\\(.+,0\\\\)$/'` — двойное экранирование, regex никогда не матчится
- Ошибка B: `isTrivial()` не вызывается в `discoverCompose()`
- **Fix:** исправить regex, добавить вызов в `discoverCompose()`
- **Fix (low):** добавить `neg(neg(x))`, `abs(abs(x))`, `min(x,x)` редукции

## Средние

### B3: Dedup-ключ без domain (§1.6)

- Ключ: `$task['name'] . '::' . $d['atom']` — domain отсутствует
- Протокол: `hash(task_domain, task_name, formula)`
- **Fix:** добавить domain в ключ: `$domain . '::' . $task['name'] . '::' . $d['atom']`

### B4: Sufficiency не проверяется для compose (§1.2)

- `doComposeTick()` не проверяет `t_min`
- **Fix:** добавить проверку `t_min` перед compose

### B5: Синтетические задачи на плато (§1.5)

- `getTasks()` генерирует `GEN_` задачи независимо от plateau
- Протокол: «на плато запрещено генерировать синтетические задачи»
- **Fix:** проверять `$plateau->isPlateau()` перед генерацией `GEN_`

## Статус

- [~] B1: Compose held-out
- [ ] B2: isTrivial regex + compose filter
- [ ] B3: Dedup ключ с domain
- [ ] B4: Compose sufficiency
- [ ] B5: Plateau синтетика
