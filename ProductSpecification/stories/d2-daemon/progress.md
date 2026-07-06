# Story D2: Daemon Class

> agenda.php (271 строка) → Daemon class + тонкий entry point
> Цель: тестируемый tick(), инжекция зависимостей

## Spec

1. `src/Hive/Daemon.php` — класс с `run()`, `tick()`
2. Зависимости через конструктор: PlateauDetector, Forager, Database
3. `agenda.php` → `(new Daemon())->run()`
4. getTasks() → метод Daemon или отдельный TaskProvider

## Core

[~] red: test_daemon_tick — tick() выполняет один цикл
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: Daemon class + tick()
    [ ] implementation, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor: agenda.php → (new Daemon())->run()
    [ ] full suite GREEN
    [ ] daemon restart check
    [ ] review
    [ ] approve

## Status
- Next: `red: test_daemon_tick`
