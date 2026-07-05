# Story D2: Daemon Class (SOLID)

> agenda.php: 220 строк процедурного спагетти → Daemon class
> Цель: тестируемый цикл, инжекция зависимостей

## Spec

1. `src/Daemon.php` — класс с `run()` методом
2. `agenda.php` → `(new Daemon())->run()` — тонкая точка входа
3. PlateauDetector, Forager, AtomRegistry — инжектятся через конструктор
4. `tick()` метод — один цикл (тестируемо!)
5. `getTasks()` → `TaskGenerator` класс (опционально, можно позже)

## Core

[~] red: test_daemon_tick — tick() выполняет один цикл
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: Daemon class + tick() implementation
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor: agenda.php → тонкая точка входа
    [ ] full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] verify: daemon restart, логи совпадают

## Status

- Next: `red: test_daemon_tick`
