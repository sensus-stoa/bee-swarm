#!/usr/bin/env python3
"""
EXP-028: генератор Feynman-данных (6 задач) + UCI-скачивание.

Формулы (Feynman Symbolic Regression Database):
  3. I.9.18  Newton gravity: F = G*m1*m2 / ((x2-x1)^2 + (y2-y1)^2 + (z2-z1)^2)
  4. I.13.4  Kinetic energy: K = 0.5*m*(v^2 + u^2 + w^2)
  5. I.11.19 Dot product:    A = x1*y1 + x2*y2 + x3*y3
  6. II.2.42 Heat conduction: P = kappa*(T2-T1)*A/d
  7. I.10.7  Relativistic mass: m = m0 / sqrt(1 - v^2/c^2)
  9. Noisy kinetic energy: I.13.4 + 5% Gaussian noise
 10. Noisy Coulomb: F = q1*q2 / (4*pi*eps*r^2) + 15% noise

Выход: data/feynman_{slug}.csv (первые колонки = входы, последняя = target)
"""
import csv
import math
import random
import os

OUT = os.path.join(os.path.dirname(__file__), '..', 'data')
os.makedirs(OUT, exist_ok=True)

def write_csv(slug, rows, target_idx):
    """rows: list of lists (входы + target в конце)"""
    path = os.path.join(OUT, f'feynman_{slug}.csv')
    with open(path, 'w', newline='') as f:
        w = csv.writer(f)
        for r in rows:
            w.writerow(r)
    print(f'  {slug}: {len(rows)} rows -> {path}')
    return path

rng = random.Random(42)

# 3. Newton gravity (9 входов: x1,y1,z1,x2,y2,z2,m1,m2,G)
rows = []
for _ in range(600):
    x1, y1, z1 = rng.uniform(-5, 5), rng.uniform(-5, 5), rng.uniform(-5, 5)
    x2, y2, z2 = rng.uniform(-5, 5), rng.uniform(-5, 5), rng.uniform(-5, 5)
    m1, m2 = rng.uniform(1, 10), rng.uniform(1, 10)
    G = 6.674e-11
    d2 = (x2-x1)**2 + (y2-y1)**2 + (z2-z1)**2
    F = G * m1 * m2 / d2
    rows.append([x1, y1, z1, x2, y2, z2, m1, m2, G, F])
write_csv('gravity', rows, 9)

# 4. Kinetic energy (3 входа: m, v, u; w фикс? нет — w третий вход)
rows = []
for _ in range(400):
    m = rng.uniform(0.5, 10)
    v = rng.uniform(-10, 10)
    u = rng.uniform(-10, 10)
    w = rng.uniform(-10, 10)
    K = 0.5 * m * (v**2 + u**2 + w**2)
    rows.append([m, v, u, w, K])
write_csv('kinetic_energy', rows, 4)

# 5. Dot product (6 входов: x1,x2,x3,y1,y2,y3)
rows = []
for _ in range(400):
    x1, x2, x3 = rng.uniform(-10, 10), rng.uniform(-10, 10), rng.uniform(-10, 10)
    y1, y2, y3 = rng.uniform(-10, 10), rng.uniform(-10, 10), rng.uniform(-10, 10)
    A = x1*y1 + x2*y2 + x3*y3
    rows.append([x1, x2, x3, y1, y2, y3, A])
write_csv('dot_product', rows, 6)

# 6. Heat conduction (5 входов: kappa, T2, T1, A, d)
rows = []
for _ in range(400):
    kappa = rng.uniform(0.1, 10)
    T2, T1 = rng.uniform(280, 350), rng.uniform(250, 340)
    A = rng.uniform(0.5, 5)
    d = rng.uniform(0.1, 2)
    P = kappa * (T2 - T1) * A / d
    rows.append([kappa, T2, T1, A, d, P])
write_csv('heat_conduction', rows, 5)

# 7. Relativistic mass (2 входа: m0, v; c фикс)
rows = []
c = 299792458.0
for _ in range(400):
    m0 = rng.uniform(1, 10)
    v = rng.uniform(0, 0.8 * c)
    m = m0 / math.sqrt(1 - v**2 / c**2)
    rows.append([m0, v, c, m])
write_csv('relativistic_mass', rows, 3)

# 9. Noisy kinetic energy (5% шум)
rows = []
for _ in range(400):
    m = rng.uniform(0.5, 10)
    v, u, w = rng.uniform(-10, 10), rng.uniform(-10, 10), rng.uniform(-10, 10)
    K = 0.5 * m * (v**2 + u**2 + w**2)
    K_noisy = K * (1 + rng.gauss(0, 0.05))
    rows.append([m, v, u, w, K_noisy])
write_csv('kinetic_energy_noise5', rows, 4)

# 10. Noisy Coulomb (15% шум; 3 входа: q1, q2, r)
rows = []
k = 8.99e9
for _ in range(400):
    q1, q2 = rng.uniform(1e-9, 1e-6), rng.uniform(1e-9, 1e-6)
    r = rng.uniform(0.01, 1.0)
    F = k * q1 * q2 / r**2
    F_noisy = F * (1 + rng.gauss(0, 0.15))
    rows.append([q1, q2, r, F_noisy])
write_csv('coulomb_noise15', rows, 3)

print('Feynman-данные готовы.')
