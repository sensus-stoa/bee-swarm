# Story 🔬 S0-QUERY: Structured System Query (Theo-Conjecture T3)

> Протокол: детерминированные SQL-запросы к собственной БД. Read-only.

## Phases

### Phase 1: QueryEngine core
- [~] RED: test_query_engine_laws_by_domain — QueryEngine::lawsByDomain()
- [ ] GREEN: QueryEngine class + Database::queryReadOnly()
- [ ] LINT + REVIEW + APPROVE

### Phase 2: Integration with verify scripts
- [ ] RED: test_verify_0_1_uses_query_engine — verify-скрипты используют QueryEngine
- [ ] GREEN: Рефакторинг verify_0_*.sh → .php

### Phase 3: systemHealth
- [ ] RED: test_system_health — systemHealth() возвращает total_laws, active_domains
- [ ] GREEN: systemHealth() метод

## Status
⬜ Backlog
