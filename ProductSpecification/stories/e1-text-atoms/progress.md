# Story E1: CV→0 Text Atoms

> Система сама открывает regex-паттерны из markdown-файлов через тот же CV→0 поиск, что и для математических законов.

## Архитектура

```
Сейчас (math):  X=[1,2,3], y=[1,4,9] → discover → sq (CV=0) → закон
E1 (text):      content="GI: 7.2\\nDQ: 6\\n..." → discover → preg_match('/(\\w+): (\\d+)/') → паттерн
```

## План (5 work units)

### E1.1: Text atom definitions
- `preg_match(pattern, content)` → array of matches
- `extract_col(matches, col)` → numeric column
- `match_label(content, label)` → extract value after label
- Добавить в `AtomRegistry` как `isTextAtom()`

### E1.2: Text-aware task format  
- `ForagerTask`: content + target extraction
- `preg_match_is_a` уже возвращает семантику — расширить до numeric extraction

### E1.3: CV→0 over text atoms
- `discover()` пробует комбинации text atoms на сыром контенте
- CV=0 → атом добавляется в grammar_ops

### E1.4: Feedback loop
- Открытые text atoms → становятся forager-стратегиями
- Больше данных → больше открытий → больше атомов

### E1.5: Integration
- Запуск на 476 markdown-файлах
- Ожидаемый результат: ≥10 новых законов, ≥5 новых text atoms

## Bootstrap (seed atoms)
- `preg_match` — уже в PHP
- `extract_col` — обёртка над array_column
- `match_label` — regex wrapper: `/(\w+): (\d+)/`

## Статус
⬜ Backlog — после D9 завершения
