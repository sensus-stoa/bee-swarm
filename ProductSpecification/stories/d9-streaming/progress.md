# Story D9: Streaming Forager (SQLite accumulator)

> tMin=10 отсекает 99% foraged-данных. Нужно аккумулировать одинаковые паттерны из сотен файлов.

## Core

[x] red: test_streaming_accumulates — 5 файлов × ≥10 точек
[x] green: scanWithAccumulator() + streamFile(), 281 tests
[~] red: semantic facts → KG через accumulator (не closure-хак)

## Spec: KG fix (стратегический)

Проблема: `preg_match_is_a` использует closure `$addFact` для прямой вставки в KG. Аккумулятор не видит семантику.

Хак (отвергнут): запускать старый `scan()` параллельно для KG.

Стратегическое решение:
1. Рефакторинг: `Forager::addFact()` — публичный метод, доступный и старому scan(), и аккумулятору
2. Аккумулятор вызывает `$this->addFact()` для семантических результатов
3. Старый код использует тот же метод

## Work Units

[~] red: test_semantic_facts_populate_kg — accumulator добавляет факты в KG
[ ] green: refactor addFact → Forager::addFact(), accumulator calls it
[ ] lint + review + approve

## Status
- Next: `red: test_semantic_facts_populate_kg`
