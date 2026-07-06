# D7: Forager Smart Strategies

> Текущее: 6 жёстких regex-стратегий. 54 файла → 31 таск.
> Нужно: больше стратегий, семантические из Markdown, эволюция стратегий.

## Новые стратегии

### Семантические (Markdown → факты)

| Стратегия | Паттерн | Пример |
|-----------|---------|--------|
| `preg_match_causes` | «X → Y», «X вызывает Y», «X приводит к Y» | «стресс → GI drop» |
| `preg_match_correlates` | «X коррелирует с Y», «X связан с Y» | «DQ коррелирует с intact» |
| `preg_match_threshold` | «если X > N, то Y», «при X < N — Z» | «если GI < 6.5 — recovery day» |
| `preg_match_pattern` | «паттерн: X», «закономерность: X» | pattern extraction |
| `markdown_table_extract` | Markdown таблицы → строки как данные | metrics tables |
| `markdown_list_extract` | Списки с числами → data points | «сон: 7, энергия: 8» |
| `yaml_frontmatter` | YAML с метриками → structured data | metrics.jsonl |

### Числовые (новые источники)

| Стратегия | Источник | Что извлекает |
|-----------|----------|---------------|
| `metrics_jsonl` | metrics.jsonl | sleep, energy, stress... |
| `csv_detect` | .csv файлы | авто-определение разделителя |
| `json_array_detect` | .json массивы | числовые поля |

### Эволюция стратегий

Сейчас: compose стратегий через `getComposedStrategies()` — но `json_encode(preg_match_nums(...))` бессмысленно.
Нужно: мутация стратегий — замена regex, добавление полей, изменение порогов. Composed strategies с осмысленными комбинациями.

## Статус
- В техдолге D7 — 11 пунктов + стратегии
