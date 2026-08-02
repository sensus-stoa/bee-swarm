# S3.1 — Association Rules Mining

## Статус: ⬜ Stage 3 backlog

## Мотивация

Online Retail (541K строк) — классический датасет для market basket analysis.
CV→0 не находит законов потому что Quantity/Price — не непрерывная зависимость,
а дискретные события. Вопрос не «сколько купят», а «что покупают вместе».

## Что нужно

1. **Apriori-подобный поиск**: частые itemsets (≥2 товара) с поддержкой ≥ minsup
2. **Association rules**: A→B с confidence ≥ minconf, lift > 1
3. **Cross-domain**: правила подтверждённые на ≥2 странах/периодах

## Фазы

### Phase 1: Itemset accumulator (2h)
- `ItemsetMiner`: обходит транзакции (InvoiceNo → StockCodes)
- Строит FP-tree или bitmap для быстрого подсчёта
- minsup = 0.01 (1% транзакций)

### Phase 2: Rule generator (1h)
- `RuleGenerator`: из частых itemsets → association rules
- Confidence ≥ 0.5, lift > 1.0
- Вывод: `WHITE HANGING HEART → WHITE METAL LANTERN (conf=0.72, lift=3.4)`

### Phase 3: Cross-domain validation (1h)
- Правило должно подтверждаться на ≥2 странах (UK vs Germany)
- Или ≥2 временных периодах (2010 vs 2011)

### Phase 4: Wire в Hive (1.5h)
- Новый тип задач: `basket_UK_2010-12`
- Пчёлы соревнуются в предсказании association rules
- Overlap: подтверждение на другом срезе данных

## Сложность: ⭐⭐⭐ | 5.5h
