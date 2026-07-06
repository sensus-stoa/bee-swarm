# Story E1: Self-Discovered Regex Atoms

> CV→0 поиск над грамматикой текстовых операций.
> Система САМА открывает regex-паттерны, максимизирующие извлечение законов.
> Никакого хардкода стратегий. Тот же принцип что с math-атомами.

## Идея

```
Грамматика: +, ×, abs, sin, ... И preg_match, extract_col, split_by, tokenize, ...
Задача:     текст → [число, число, ...] → CV→0?
Открытие:   extract_col(preg_match("\\d+", text)) → CV=0 на metrics.jsonl
```

## Что нужно

1. **Текстовые атомы в Grammar** — `preg_match`, `extract_col`, `split_by`, `tokenize`, `count_matches`
2. **Cloze-подобные задачи из текста** — уже есть SentenceRegistry + CorpusVocabulary
3. **Фитнес** — открытый regex-атом → больше законов → выше сигнал → выживает
4. **Мутация** — compose(preg_match, extract_col) → новые комбинации
5. **Селекция** — атомы без открытий удаляются (уже работает: strategyScores=0 → unset)

## Отличие от текущего Forager

| Сейчас | Будет |
|--------|-------|
| 6 хардкод-стратегий | CV→0 открытые атомы |
| compose = json_encode(x) | compose = extract(preg_match(...)) |
| Стратегии живут вечно | Атомы с 0 законов удаляются |
| Человек добавляет паттерны | Система САМА находит паттерны |

## Core

[~] red: test_regex_atom_in_grammar — preg_match как атом
[ ] green: Grammar: preg_match + extract_col
[ ] red: test_text_task_from_metrics — metrics.jsonl → text task
[ ] green: TextTaskGenerator
[ ] red: test_regex_discovery — CV→0 открывает regex-паттерн
[ ] green: discover с текстовыми атомами
[ ] verify: демон открыл новый regex-атом

## Status

- Next: `red: test_regex_atom_in_grammar`
