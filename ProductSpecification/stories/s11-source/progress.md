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
- [x] StreamingAccumulator: fd таблица + `source_path` TEXT, все INSERT передают `$path`
- [x] task-структура: `'source_path' => $sourcePath` из первого ряда GROUP BY
- [x] laws-таблица: LAWS_DDL + миграция ALTER TABLE — колонки `source_path`, `content_sample`
- [x] Hive::recordDiscovery: INSERT 6 params (было 4), пишет source_path + первые 200 символов content
- [x] Лог открытий: `🔍 {task} -> {formula} (CV={cv}) [{domain}] src={basename}`
- [x] 2 теста: testTasksIncludeSourcePath, testLawsTableHasSourceColumns (418/418 PASS)

### Phase 2: Traceability tools
- [ ] `bee law show <id>` — показать source_path + первые 200 символов content_sample
- [ ] `bee law sources` — список законов с их источниками
- [ ] При отсутствии source — честно писать `src=unknown`

### Phase 3: Semantic column labeling
- [ ] Markdown-таблицы: парсить header row → имена колонок (`курс_доллара` вместо `x0`)
- [ ] CSV: первая строка = заголовки колонок
- [ ] JSON: ключи объекта = имена колонок
- [ ] StreamingAccumulator: поле `col_labels` в task-структуре
- [ ] Search::find: использовать `col_labels` для имён фич в формулах
- [ ] laws-таблица: колонка `col_labels TEXT` (JSON array)
- [ ] Пример результата: `((курс/Rmax_курс)+(инфляция/Rmax_инфляция))` вместо `((x4/Rmaxx4)+(x5/Rmaxx5))`
- [ ] При отсутствии заголовков → fallback на x0, x1, ...

### Phase 4 (будущее): Обратная трассировка
- [ ] Индекс: source_path → список законов
- [ ] Подсветка source-файла в vault при просмотре закона

## Архитектура

```
StreamingAccumulator.scan()
  ├── fd: pattern, row_json, source_path[NEW], col_labels[NEW], content
  └── task: name, data, domain, content, source_path[NEW], col_labels[NEW]
        │
        ▼
Hive::doDiscoverTick()
  └── Search::find(X, y, grammar, depth, col_labels[NEW])  ← именованные фичи
        │
        ▼
  Hive::recordDiscovery(task, ...)
        ├── laws: name, formula, cv, domain, source_path, content_sample, col_labels
        └── log: "🔍 {task} -> {formula} (CV={cv}) [{domain}] src={path}"
                  формула: ((курс/Rmax_курс)+(инфляция/Rmax_инфляция))  ← не x4, x5
```

## Что НЕ делать
- ❌ Не дублировать контент (content_sample — только первые 200 символов)
- ❌ Не менять формат task['name'] (хеш остаётся)
- ❌ Не замедлять сканирование (source_path и так известен)
- ❌ Не требовать заголовки — fallback на x0, x1 при отсутствии
- ❌ Не усложнять Search::find — col_labels опциональны

## Статус
✅ Phase 1 — Source metadata pipeline (a9770e4)
✅ Phase 2 — Traceability tools (476ef0e)  
✅ Phase 3 — Semantic column labeling (512f204)
⬜ Phase 4 — Обратная трассировка (backlog)
