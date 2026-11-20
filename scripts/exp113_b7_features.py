#!/usr/bin/env python3
"""
B7 этап 2б: KO-профили 25 видов (KEGG link/ko) + замороженные feature-группы.
Признаки независимы от исходов сообществ (только геномы).
M2: genome_size, GC, total_KO_genes, distinct_KOs.
M3 (pre-registered): purine_core_count (панель KO, проверена на Bt: 13 генов),
purine_degradation_count, butyrate_count, sulfate_count.
"""
import urllib.request, json, time, statistics

CODES = json.load(open('/tmp/clark_kegg_codes.json'))  # code -> [kegg_code, n] или null
GENOME = json.load(open('/tmp/clark_genome_stats.json'))

PURINE_CORE_PANEL = ['K00760','K01939','K00214','K01556','K00763','K01489','K00215',
                     'K02695','K03521','K00216','K02446','K00217','K00761','K00762',
                     'K00764','K00602','K00076','K00943','K00940','K01753']
PURINE_DEGRADATION = ['K00105','K03101','K03102','K07304','K01456','K01457','K01458']
BUTYRATE = ['K00929','K01034','K00174']
SULFATE = ['K00380','K00381','K11180','K11181']

def fetch(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'curl/7.88'})
    return urllib.request.urlopen(req, timeout=90).read().decode()

features = {}
for code, info in CODES.items():
    if not info:
        features[code] = None
        continue
    kegg_code, _ = info
    try:
        txt = fetch(f'https://rest.kegg.jp/link/ko/{kegg_code}')
        kos = set()
        for ln in txt.strip().splitlines():
            p = ln.split('\t')
            if len(p) == 2 and p[1].startswith('ko:'):
                kos.add(p[1][3:])
        feat = {
            'kegg_code': kegg_code,
            'total_KO_genes': len(txt.strip().splitlines()),
            'distinct_KOs': len(kos),
            'purine_core_count': sum(1 for k in PURINE_CORE_PANEL if k in kos),
            'purine_degradation_count': sum(1 for k in PURINE_DEGRADATION if k in kos),
            'butyrate_count': sum(1 for k in BUTYRATE if k in kos),
            'sulfate_count': sum(1 for k in SULFATE if k in kos),
        }
        g = GENOME.get(code, {})
        feat['genome_size_mb'] = g.get('size_mb')
        feat['gc'] = g.get('gc')
        features[code] = feat
        print(f"{code} ({kegg_code}): KO-генов {feat['total_KO_genes']}, distinct {feat['distinct_KOs']}, "
              f"purine_core {feat['purine_core_count']}/20, degr {feat['purine_degradation_count']}/7, "
              f"butyrate {feat['butyrate_count']}/3, sulfate {feat['sulfate_count']}/4, "
              f"{feat['genome_size_mb']}Mb GC={feat['gc']}")
        time.sleep(0.4)
    except Exception as e:
        features[code] = {'error': str(e)[:60]}
        print(f'{code}: ERR {str(e)[:50]}')

json.dump(features, open('/tmp/clark_features.json', 'w'), indent=1)
ok = sum(1 for v in features.values() if v and 'error' not in v)
print(f'\nготово: {ok}/25')
