#!/usr/bin/env python3
"""Скачивание всех i_Investigation.txt с MetaboLights FTP в Benchmarks/metabolights_inv/."""
import re, os, sys, time, urllib.request, concurrent.futures as cf

ROOT = 'https://ftp.ebi.ac.uk/pub/databases/metabolights/studies/public'
OUT = os.path.expanduser('~/.bee_swarm/Benchmarks/metabolights_inv')
os.makedirs(OUT, exist_ok=True)

html = open('/tmp/ebi_root.html').read()
ids = sorted(set('MTBLS' + i for i in re.findall(r'MTBLS(\d+)', html)), key=lambda x: int(x[5:]))
print('всего исследований в листинге:', len(ids))

todo = [m for m in ids if not os.path.exists(f'{OUT}/{m}.txt')]
print('к скачиванию:', len(todo))

def get(mid):
    url = f'{ROOT}/{mid}/i_Investigation.txt'
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        txt = urllib.request.urlopen(req, timeout=25).read().decode(errors='ignore')
        with open(f'{OUT}/{mid}.txt', 'w') as f:
            f.write(txt)
        return mid, True
    except Exception as e:
        return mid, False

n_ok = n_err = 0
errs = []
with cf.ThreadPoolExecutor(max_workers=32) as ex:
    for mid, ok in ex.map(get, todo):
        if ok: n_ok += 1
        else:
            n_err += 1
            errs.append(mid)
        if (n_ok + n_err) % 500 == 0:
            print(f'  {n_ok + n_err}/{len(todo)}...')
print(f'скачано: {n_ok}, ошибок: {n_err}')
if errs:
    print('примеры ошибок:', errs[:10])
