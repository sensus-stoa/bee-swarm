# Story D8: Forager — Caps & Chunks

> Три причины почему Forager не видит все данные

## Spec

1. **`$maxTotal=30` → env `FORAGER_MAX_TASKS`** (default 200). Обрабатывать ВСЕ файлы
2. **`$size > 500_000` → chunk** на куски по 100K. Каждый кусок → отдельная попытка стратегий
3. **Рекурсивный скан** — уже есть (`RecursiveIteratorIterator`), просто убираем кап

## Core

[~] maxTotal → FORAGER_MAX_TASKS env (200 default)
[ ] 500K → chunking на 100K блоки
[ ] verify: все 54 файла обработаны

## Status
- Next: maxTotal env
