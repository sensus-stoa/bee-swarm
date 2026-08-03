# S2.6 — Cross-Domain Form Discovery

## Статус: ⬜ Stage 2 backlog

## Источник

Протокол §3.2-тер: Form Invariance Across Domains.
Одна математическая форма (power law, exponential, attractor) повторяется
в несвязанных системах: физика, биология, лингвистика, экономика.

## Что нужно

1. **Form Template Extraction**: LawCompressor выделяет общую форму
   из нескольких законов в разных доменах
2. **Form Registry**: `form_template`, `domains[]`, `params_per_domain[]`
3. **verify_2_2b**: ≥1 форма подтверждена в ≥3 доменах

## Данные (публичные)

| Домен | Датасет | Строк | Инвариант |
|-------|---------|-------|-----------|
| Сейсмология | USGS earthquakes | 500K | magnitude ∝ frequency^(-b) |
| Биология | Metabolic rates | 3K | metabolism ∝ mass^0.75 |
| Лингвистика | Wikipedia word freq | 6M | freq ∝ 1/rank |
| Экономика | FRED GDP | 100K | growth ∝ 1/time |

## Фазы (предварительно)

### Phase 1: FormTemplate extraction (2h)
- LawCompressor: несколько expression trees → общий шаблон
- `POWER_LAW`: `pow(x, α)` — параметр α разный, форма одна

### Phase 2: Form Registry + cross-domain validation (2h)
- `form_registry` таблица
- Для каждого шаблона: проверить на ≥3 доменах
- CV_train ≤ 0.15 в каждом

### Phase 3: verify_2_2b script (1h)

## Сложность: ⭐⭐⭐ | 5h | Stage 2
