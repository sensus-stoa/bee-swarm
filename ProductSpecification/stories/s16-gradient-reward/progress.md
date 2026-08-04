# Story S1.6-GRADIENT: Signal Gradient Reward

> Протокол §0.5-бис: три зоны — ОТКРЫТИЕ / СИГНАЛ / ШУМ.
> Сейчас: CV>0.01 → отброшено. Нет частичной награды.

## Spec

```
★ ОТКРЫТИЕ (CV ≤ ε_train):  rewardDiscovery +2.0  (есть)
🔥 СИГНАЛ  (ε_train < CV ≤ null_floor):  partial reward + мутация в эту сторону  ❌
☠ ШУМ     (CV > null_floor):  abandon  ❌

null_floor — минимальный CV на shuffled-данных (уже есть в NullCalibrator)
Сигнал не принимается как закон, но:
  → +0.5 к энергии (не +2.0)
  → grammar мутирует в сторону атома с лучшим CV
  → exploration priority
```

## Core

[ ] red: test_signal_reward_below_null — CV=0.05 < null=0.5 → +0.5 energy
[ ] red: test_noise_no_reward — CV=0.8 > null=0.5 → 0 energy
[ ] red: test_discovery_full_reward — CV=0.005 < ε=0.01 → +2.0
[ ] green: SignalReward in doDiscoverTick
[ ] green: null_floor from NullCalibrator
[ ] refactor + lint + review

## Work Units

[ ] red: test_signal_reward_below_null
[ ] red: test_noise_no_reward
[ ] red: test_discovery_full_reward
[ ] green: SignalReward
[ ] green: null_floor integration
[ ] tests pass + review

## Status
- Next: `red: test_signal_reward_below_null`
