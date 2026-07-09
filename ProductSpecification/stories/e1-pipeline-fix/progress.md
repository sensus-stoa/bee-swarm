# Story E1-FIX: Text Atom Pipeline — Single-Column Data → X/y

> 895 text atoms открыто, 0 text-законов. Pipeline разорван: single-column data → empty X → CV→0 бесполезен.

## Диагноз

```
StreamingAccumulator: match_label('GI') → [7.2]  (одно число)
                      match_label('DQ') → [6.0]
                      
Hive::tick() line 187: $X = array_slice([7.2], 0, -1) = []  ← ПУСТО
                       $y = 7.2
                       
Search::find: nFeat = count($X[0]) = 0
              tMin = max(10, 0×5) = 10
              CV→0(∅, y) → нечего искать
```

## Fix

Text atom данные должны формировать пары для CV→0:

```
Подход: cross-pairing text atoms из одного файла

Файл содержит: GI:7.2, DQ:6, Sleep:5h
→ match_label(GI) → [7.2, 8.1, 6.5] (из нескольких файлов)
→ match_label(DQ) → [6.0, 5.5, 7.0]
→ cross-pair: X=[match_label(DQ)], y=match_label(GI)
→ Search::find([6.0, 5.5, 7.0], [7.2, 8.1, 6.5]) → закон!
```

## Phases

### Phase 1: Cross-pairing в StreamingAccumulator
- [ ] Вместо txt_ одиночных значений → txt_pair_ с парами метрик
- [ ] Группировка: все text atom значения из ОДНОГО файла → cross-product пар
- [ ] tMin=10 применяется к количеству пар

### Phase 2: Verify — text laws found
- [ ] Запустить на 476 markdown-файлах
- [ ] ≥1 новый закон в домене ≠ arithmetic

## Статус
⬜ Backlog
