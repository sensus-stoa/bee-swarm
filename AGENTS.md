# bee_swarm — AGI Roj Project

> TDD Framework (adapted from rakovi4/continue-framework)
> Stage 0 target: HONEST_CRITERIA.md — Reliable Invariant Extraction

## Quick Start

```
User: next
→ Co-Architect reads ProductSpecification/stories.md
→ finds active story, reads progress.md
→ dispatches sub-agent (red/green/refactor/review)
→ updates progress.md, commits
```

## Architecture

See `CODE_NAVIGATION.md` — полная карта кода: data flow, классы, pitfalls, тестовые паттерны.
See `ARCHITECTURE.md` — 7 layers (0-7): Environment → Laws → Self-generate → Self-coding → Semantic → Self-modify → Autonomous.

## Technology

- **Runtime:** PHP 8.x + RoadRunner
- **Database:** SQLite via PDO
- **Tests:** PHPUnit (`vendor/bin/phpunit tests/`)
- **Daemon:** `agenda.php` (run loop)
- **Tech profile:** `.agents/tech/php-rr/` (coding standards, TDD rules, templates)

## Key Files

| File | Purpose |
|------|---------|
| `CODE_NAVIGATION.md` | **AI agent code map** — data flow, classes, pitfalls |
| `agenda.php` | Daemon main loop |
| `src/Infra/Database.php` | SQLite singleton, DDL, migrations |
| `src/Core/Search.php` | CV→0 search: `find(X, y, grammar)` |
| `src/Core/AtomRegistry.php` | Atom registry + text atom discovery |
| `src/Hive/Hive.php` | Main loop, task routing, engines |
| `src/Hive/DiscoveryEngine.php` | Law discovery pipeline |
| `src/Forager/StreamingAccumulator.php` | File scanning, foraged task creation |
| `tests/` | 491 tests, 1639 assertions (03.08.2026) |

## Critical Rules

1. **TDD:** test → code → all green. NEVER modify agenda.php without a test first.
2. **Test DB isolation:** `SWARM_DB_PATH=:memory:` set in phpunit.xml (in-memory SQLite). NEVER `--no-configuration`.
3. **Daemon restart:** `pkill -f agenda.php; sleep 1; php agenda.php &`
4. **EVOLVE DON'T ADD:** новое должно эмерджентно возникать через compose, не хардкодиться.
5. **prepare()→execute() returns bool** — always separate calls.

## Where to Find Rules

| When you need to... | Read |
|--------------------|------|
| Write a test (RED) | `.agents/agents/red.md` + `.agents/tech/php-rr/tdd.md` |
| Implement code (GREEN) | `.agents/agents/green.md` + `.agents/tech/php-rr/tdd.md` |
| Refactor | `.agents/agents/refactor.md` |
| Review test quality | `.agents/agents/review.md` |
| Understand coding standards | `.agents/tech/php-rr/coding.md` |
| See test template | `.agents/tech/php-rr/templates/test-class.md` |
| See implementation template | `.agents/tech/php-rr/templates/implementation.md` |
| Know all pitfalls | skill: `swarm-tdd` (Hermes) |

## Progress Tracking

```
ProductSpecification/
├── stories.md              ← master story list
└── stories/
    └── 01-dedup/           ← one folder per story
        └── progress.md     ← checkpoint state
```

Status markers: `[x]` done, `[~]` in-progress, `[ ]` pending, `[S]` skipped.
