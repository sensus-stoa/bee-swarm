# Story E1.6: Text Atom → Data → Law

> 450+ text atoms открыто. Теперь извлечь данные и открыть законы.

## Spec

1. **Data extraction:** `preg_match(Состояние, content)` из 476 файлов → массив значений
2. **Task generation:** forager создаёт задачи из text atom результатов
3. **CV→0 over extracted data:** discover() ищет законы (add, mul, sq...) на извлечённых данных
4. **Law discovery:** если CV=0 → закон регистрируется

## Pipeline

```
content → preg_match(GI) → [7.2, 8.1, 6.5, ...] (данные)
данные → discover() → add(GI_t-1, K) → ЗАКОН
```

## Work Units

- [ ] E1.6.1: forager extracts data via discovered text atoms
- [ ] E1.6.2: text-atom tasks попадают в discover pipeline
- [ ] E1.6.3: law discovery из text atom данных
- [ ] E1.6.4: integration — 476 файлов → ≥5 новых законов

## Статус
⬜ Backlog
