# E1-FIX — Text Atom → Law Pipeline

## Диагноз (02.08.2026)

```
Corpus: 0 words, 0 sentences     ← 🔴 корень
Forager: 11 tasks (все numeric)
foraged_txt в логе: 0
txt_pair задач: 0
Текст-законов: 0
```

2962 текстовых атома в grammar_ops — мёртвый груз. CorpusVocabulary не парсит файлы.
Obsidian vault: `~/obsidian/` — 1910 .md файлов. Не подключён.

## Pipeline (как должно работать)

```
Obsidian vault (1910 .md)
  → Forager читает файлы → content
    → CorpusVocabulary.tokenize → слова
      → SentenceRegistry.split → предложения
        → TextAtom extraction (preg_match, match_label)
          → StreamingAccumulator → fd table
            → foraged_txt_* tasks
              → TextAtomCrossPairer → txt_pair_X_to_Y задачи
                → doDiscoverTick (X,y формат) → CV→0
                  → laws с domain=text_pairs
```

## Фазы

### Phase 1: Corpus loader — починить чтение .md файлов
**Проблема:** `Corpus: 0 words, 0 sentences`. CorpusVocabulary/SentenceRegistry не получают контент.

**Что сделать:**
- Диагностика: почему CorpusVocabulary возвращает 0
- Проверить: Forager → контент → StreamingAccumulator → CorpusVocabulary
- Починить загрузку текста хотя бы из одного файла
- Тест: `CorpusVocabulary::tokenize(file) → non-empty`

**Сложность:** ⭐⭐ | 2h

### Phase 2: Text atom bootstrap — Hive обнаруживает атомы из контента
**Проблема:** chicken-and-egg — атомы нужны для задач, задачи для атомов.

**Что сделать:**
- Hive::doTick получает контент из foraged задач
- Text atom discovery (lines 432-450) применяет `match_label`, `preg_match`
- Обнаруженные атомы → AtomRegistry::addDiscoveredTextAtom → grammar_ops
- Следующий цикл Forager использует новые атомы

**Тест:** Text atom discovery из контента → атом в grammar_ops

**Сложность:** ⭐⭐ | 2h

### Phase 3: Cross-pair wire — txt_pair задачи → законы
**Проблема:** код cross-pairing есть (Hive.php:931-946) но не тестирован.

**Что сделать:**
- Проверить что `txt_pair_*` задачи создаются при ≥2 `foraged_txt_*`
- Проверить формат данных (X,y) подходит для `doDiscoverTick`
- Починить domain передачу (сейчас text_pairs)
- Тест: cross-pair → задача → discovery → law

**Сложность:** ⭐⭐ | 1.5h

### Phase 4: Obsidian vault integration + cross-source validation
**Проблема:** Forager сканирует Documents/Desktop/Downloads, но не Obsidian vault.

**Что сделать:**
- Добавить `~/obsidian/` в FORAGER_SOURCES
- Проверить что 1910 .md файлов дают контент
- Cross-source validation: закон подтверждается на ≥2 источниках
- Тест: закон из Obsidian + metrics → cross-source

**Сложность:** ⭐⭐⭐ | 2h

## E2E

Phase 3+: `text_laws_count = COUNT(*) FROM laws WHERE domain LIKE '%text%' OR domain LIKE '%txt%'`.
Базовая линия: 0. Цель Phase 3: >0. Цель Phase 4: >10.
