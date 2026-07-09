# Story S2.3: Contradiction → Paradigm

> Protocol 2.5-quater: противоречие между пчёлами → задача-разрешение → новая операция.

## Спецификация
- Две пчёлы нашли РАЗНЫЕ формулы для одной задачи, обе CV≤0.01
- D_diff = подмножество где |f_A(x) − f_B(x)| > δ
- T_contradiction: предсказать y на D_diff с признаком f_A−f_B
- verify_1_5d: contradiction решена с CV≤0.01, формула содержит новую операцию

## Статус
⬜ Backlog — требует: S1-WIRE (≥2 пчёл с разными grammar), S1.5 (diversity)
