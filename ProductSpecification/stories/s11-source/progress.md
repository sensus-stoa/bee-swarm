# Story S1.11-SOURCE: Foraged Source Attribution

> GAP-004: Законы находятся, но provenance теряется.
> Task name = хеш. Нельзя узнать источник данных → нельзя применить закон.

## Почему

01.08.2026 — первые открытия на foraged-данных:
- `foraged_num_0af5a10ad369` → 3 закона (CV=0.067–0.133)
- Формулы содержат reduce (arity bridge работает)
- Но мы не знаем, ЧТО это за данные и К ЧЕМУ применять законы

Контент (первые 5000 символов файла-источника) ДОСТУПЕН в StreamingAccumulator
(поле `content` в task-структуре), но ТЕРЯЕТСЯ:
- Не попадает в laws-таблицу
- Не логируется при открытии
- Путь к файлу известен при скане, но не сохранён

## Что нужно

### Phase 1: Source metadata pipeline
- [ ] StreamingAccumulator: добавить `source_path` в fd-таблицу и task-структуру
- [ ] laws-таблица: миграция — колонки `source_path TEXT`, `content_sample TEXT`
- [ ] Hive::recordDiscovery: сохранять source_path и content_sample из task
- [ ] Лог открытий: `🔍 {task} -> {formula} (CV={cv}) [{domain}] src={path_basename}`

### Phase 2: Traceability tools
- [ ] `bee law show <id>` — показать source_path + первые 200 символов content_sample
- [ ] `bee law sources` — список законов с их источниками
- [ ] При отсутствии source — честно писать `src=unknown`

### Phase 3 (будущее): Обратная трассировка
- [ ] Индекс: source_path → список законов
- [ ] Подсветка source-файла в vault при просмотре закона

## Архитектура

```
StreamingAccumulator.scan()
  ├── fd: pattern, row_json, source_path[NEW], content
  └── task: name, data, domain, content, source_path[NEW]
        │
        ▼
Hive::doDiscoverTick()
  └── recordDiscovery(task, ...)
        ├── laws: name, formula, cv, domain, source_path[NEW], content_sample[NEW]
        └── log: "🔍 {task} -> {formula} (CV={cv}) [{domain}] src={path}"
```

## Что НЕ делать
- ❌ Не дублировать контент (content_sample — только первые 200 символов)
- ❌ Не менять формат task['name'] (хеш остаётся)
- ❌ Не замедлять сканирование (source_path и так известен)
- ❌ Не парсить source_path для извлечения метаданных (пока)

## Статус
⬜ Phase 1 — Source metadata pipeline
⬜ Phase 2 — Traceability tools
⬜ Phase 3 — Обратная трассировка (backlog)
