# Story D11-SEARCH-PERF: Search Performance — Grammar Cap

> 1349 grammar ops → 909K пар в discoverCompose → каждый тик тормозит.

## Почему

- Grammar::all() возвращает 1349 ops (BASE_OPS + SEMANTIC_OPS + auto-discovered)
- discoverCompose перебирает C(1349,2) = 909,276 пар
- Для каждой пары: O(n) проход по строкам данных → CV
- На метриках (152 строки) это сотни тысяч операций

Search::find уже использует `restrictTo(BASE_OPS)` — проблема только в compose.

## Phases

### Phase 1: Grammar::capped(limit) — топ-N ops
- [ ] Grammar: `capped(int $limit): array` — BASE_OPS + top N по frequency
- [ ] frequency = количество использований в законах (laws table count by formula)
- [ ] По умолчанию limit=50
- [ ] Использовать в doDiscoverTick для compose grammar
- [ ] E2E: grep -c "🔍" за 50 тиков до/после

### Phase 2: Pruning — досрочный выход при CV > best
- [ ] discoverCompose: если промежуточный CV уже хуже лучшего → skip
- [ ] AtomProvider::discoverCompose: early exit оптимизация

### Phase 3: Time budget — ограничение по времени на тик
- [ ] doDiscoverTick: общий таймаут на все поиски в тике

## Что НЕ делать
- ❌ Не трогать Search::find (уже restricted до BASE_OPS)
- ❌ Не удалять ops из grammar — только капировать для compose

## E2E
E2E: discovery_rate ↑ (grep -c "🔍" за 50 тиков до/после деплоя)

## Статус
⬜ Phase 1 — Grammar::capped()
⬜ Phase 2 — Early exit pruning
⬜ Phase 3 — Time budget
