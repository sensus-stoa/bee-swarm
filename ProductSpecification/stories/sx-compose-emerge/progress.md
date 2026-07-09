# Story SX-Compose: Emergent Compose Capabilities

> Инспирировано: синаптические нелинейности в нейронах — не сумма входов, а нелинейная композиция создаёт новые возможности.

## Идея

`preg_match(GI)` + `extract_col` по отдельности ≠ `extract(preg_match(GI))`.
Compose создаёт **emerge capability** — способность которой нет у отдельных ops.

Это меняет смысл grammar coverage: не «сколько ops», а «какие compose-цепочки возможны».

## Спецификация

```php
// Не: count($grammar)
// А:  count(уникальных compose-цепочек, принёсших CV→0)

GrammarCoverage(bee) = {
    цепочка: count(законов, открытых через эту цепочку)
}
```

## Топология grammar как fingerprint

Вместо плоского списка ops, grammar имеет **топологию:**
- Какие ops compose с какими?
- Какие цепочки дают CV→0?
- Какие комбинации БЕСПОЛЕЗНЫ (FCI не растёт)?

```php
// Пример топологии
bee.grammar = {
    'add':       ['composes_with' => ['sub', 'mul', 'K*'], 'laws_found' => 3],
    'preg_match': ['composes_with' => ['extract_col', 'match_label'], 'laws_found' => 0],
    'lag':        ['composes_with' => ['add', 'sub'], 'laws_found' => 1],
}
```

## Применение

1. **Мутация становится умнее:** удаляем ops которые не compose (мёртвые листья), добавляем ops которые заполняют пробелы
2. **Роутинг использует топологию:** задача → какая топология grammar её решила? → следующая похожая → grammar с похожей топологией
3. **FCI учитывает compose:** не просто «решила задачу», а «решила через compose-цепочку глубины ≥ 2»

## Статус
⬜ Backlog — R&D, после S1.5
