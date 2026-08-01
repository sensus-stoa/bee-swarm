# Story S1.12-EVOLVE: Grammar Evolution Through Environment

> GAP-002: Grammar должна расти снизу — среда даёт атомы, CV→0 отбирает.
> Не хардкод, не promotion из песка — exhaustive test + cross-domain signal.

## Почему

Сейчас: Search::find находит законы, но grammar НЕ растёт из найденного.
- `(x0/R+x0)` найдено → но `normalize` не становится оператором
- Система видит законы, но не умнеет от них
- GAP-003 (предел CV→0) частично решён через reduce, но grammar всё ещё статична

Механизм уже проверен в тестах (DiscoveryLoopTest):
- `AtomRegistry::discover()` — exhaustive test атомов → CV < 0.001
- `AtomRegistry::discoverCompose()` — пары атомов
- `AtomRegistry::accumulateSignal()` — кросс-доменный сигнал

Но НЕ врезан в демон.

## Phases

### Phase 1: discoverCompose в doDiscoverTick
- [ ] После Search::find → AtomRegistry::discoverCompose на той же задаче
- [ ] Найденные compose-атомы → recordDiscovery

### Phase 2: Auto-promote в grammar_ops
- [ ] CV=0 на ≥2 доменах → `$g->add($atom, 'auto-discover')`
- [ ] Кросс-доменный трекинг через accumulateSignal

### Phase 3: Grammar reload в цикле
- [ ] После promote → grammar reload для следующего тика

## Что НЕ делать
- ❌ Не добавлять операторы вручную (manual operator anti-pattern)
- ❌ Не хардкодить порог CV (использовать существующий 0.001 из AtomProvider)
- ❌ Не менять сигнатуру AtomRegistry::discover / discoverCompose

## Статус
⬜ Phase 1 — discoverCompose в doDiscoverTick
⬜ Phase 2 — Auto-promote в grammar_ops
⬜ Phase 3 — Grammar reload
