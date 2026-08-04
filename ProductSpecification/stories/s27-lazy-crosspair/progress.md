# Story S2.7: Lazy Cross-Pair Generator

> O(N²) cross-pair материализует 640K txt_pair задач синхронно в памяти (9GB+).
> Замена на PHP Generator: O(1) память, bounded sampling.

## Диагноз

```
800 текст-атомов × 799 = 639,200 пар
Каждая задача: name + data[] + domain ≈ 14KB
Итого: 639,200 × 14KB ≈ 9GB
Лимит: 8GB → OOM (молча, без записи в error_log)
```

## Core

[x] red: test_cross_pair_is_generator — TextAtomCrossPairer::crossPair() возвращает Generator, не массив
[x] red: test_cross_pair_bounded_sample — TaskGenerator ограничивает cross-pair до MAX_CROSS_PAIR
[x] green: TextAtomCrossPairer::crossPair() → yield вместо return $tasks
[x] green: TaskGenerator::crossPairTasks() → yield from
[x] green: TaskGenerator::generate() → семплирует до MAX_CROSS_PAIR из генератора
[ ] lint + review + approve

## Spec

**Файлы:**
1. `src/Core/TextAtomCrossPairer.php` — crossPair(): `array` → `\Generator` ✅
2. `src/Hive/TaskGenerator.php` — crossPairTasks(): `array` → `\Generator`; generate(): bounded sample ✅
3. Константа `MAX_CROSS_PAIR = 2000` в TaskGenerator ✅

**Контракт:**
- crossPair() больше не аллоцирует N×M массив — каждый task yield'ится по одному
- generate() берёт первые MAX_CROSS_PAIR из генератора (детерминированно)
- Без shuffle — детерминизм важнее равномерности на этом этапе

**Память:**
- До: O(N²) ≈ 9GB для 800 атомов
- После: O(1) — только текущий task в памяти
- Измерено: 20MB vs 962MB на тесте с 200 атомами

## Work Units

[x] red: test_cross_pair_is_generator
[x] red: test_cross_pair_bounded_sample  
[x] green: TextAtomCrossPairer → Generator
[x] green: TaskGenerator → yield from + bounded sample
[x] green: 504 тестов проходят (1815 assertions)
[ ] deploy to laptop + запуск agenda.php

## Status
- Next: `deploy to laptop + запуск agenda.php`
- **04.08.2026 05:00:** GREEN complete. 504/504 PASS, 1815 assertions. Memory: 20MB (было 962MB).
