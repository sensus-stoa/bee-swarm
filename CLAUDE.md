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

See `ARCHITECTURE.md` — 7 layers (0-7): Environment → Laws → Self-generate → Self-coding → Semantic → Self-modify → Autonomous.

## Technology

- **Runtime:** PHP 8.x + RoadRunner
- **Database:** SQLite via PDO
- **Tests:** PHPUnit (`vendor/bin/phpunit tests/`)
- **Daemon:** `agenda.php` (run loop)
- **Tech profile:** `.claude/tech/php-rr/` (coding standards, TDD rules, templates)

## Key Files

| File | Purpose |
|------|---------|
| `agenda.php` | Daemon main loop |
| `src/Database.php` | SQLite singleton, test DB isolation |
| `src/AtomRegistry.php` | Atom discovery + compose |
| `src/Forager.php` | External data scanner |
| `src/ResourceGuard.php` | Process CPU monitoring |
| `tests/` | 40 test files, 230 tests, 481 assertions |
| `config/` | RoadRunner config |

## Critical Rules

1. **TDD:** test → code → all green. NEVER modify agenda.php without a test first.
2. **Test DB isolation:** `SWARM_DB_PATH=data/test_swarm.db` set in phpunit.xml. NEVER `--no-configuration`.
3. **Daemon restart:** `pkill -f agenda.php; sleep 1; php agenda.php &`
4. **EVOLVE DON'T ADD:** новое должно эмерджентно возникать через compose, не хардкодиться.
5. **prepare()→execute() returns bool** — always separate calls.

## Where to Find Rules

| When you need to... | Read |
|--------------------|------|
| Write a test (RED) | `.claude/agents/red.md` + `.claude/tech/php-rr/tdd.md` |
| Implement code (GREEN) | `.claude/agents/green.md` + `.claude/tech/php-rr/tdd.md` |
| Refactor | `.claude/agents/refactor.md` |
| Review test quality | `.claude/agents/review.md` |
| Understand coding standards | `.claude/tech/php-rr/coding.md` |
| See test template | `.claude/tech/php-rr/templates/test-class.md` |
| See implementation template | `.claude/tech/php-rr/templates/implementation.md` |
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
