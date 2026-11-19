#!/usr/bin/env python3
"""B6.5-F: PubChem-формулы + SMILES для всех 97 EV4-метаболитов.
Реальные атомные составы вместо угадывания по именам."""
import csv, json, time, urllib.request, urllib.parse, re

VEN = '/home/ninjacat/.bee_swarm/Benchmarks/venturelli'
names = []
with open(f'{VEN}/ev4_metabolites.csv') as f:
    r = csv.reader(f)
    header = next(r)
    for row in r:
        name = row[0].strip().strip("'").strip('\r')
        # чистим http_метacyc-мусор
        clean = re.sub(r'^http_metacycporg_META_NEW-IMAGE_type_COMPOUND_object_', '', name)
        clean = re.sub(r'_(positive|negative)_\d+p\d+$', '', clean).replace('_', ' ').replace('-', ' ').strip()
        names.append((name, clean))

def pubchem(query: str):
    url = f'https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/name/{urllib.parse.quote(query)}/property/MolecularFormula,CanonicalSMILES/JSON'
    try:
        with urllib.request.urlopen(url, timeout=15) as resp:
            d = json.load(resp)
            p = d['PropertyTable']['Properties'][0]
            return {'formula': p.get('MolecularFormula'), 'smiles': p.get('ConnectivitySMILES'), 'cid': p.get('CID'), 'query': query}
    except Exception as e:
        return {'error': str(e)[:60], 'query': query}

out = {}
ok = 0
for orig, clean in names:
    res = pubchem(clean)
    if 'formula' not in res and ' ' in clean:
        # вторая попытка: первое слово + короткие формы
        res2 = pubchem(clean.replace(' L ', ' L-'))
        if 'formula' not in res2:
            res2 = pubchem(clean.split()[0])
        res = res2 if 'formula' in res2 else res
    out[orig] = res
    ok += 'formula' in res
    time.sleep(0.25)  # rate limit

json.dump(out, open('/tmp/ev4_chem.json', 'w'), indent=1, ensure_ascii=False)
print(f'found: {ok}/{len(names)}')
miss = [orig for orig, r in out.items() if 'formula' not in r]
print('missed:', len(miss))
for m in miss[:15]:
    print('  ', m[:70])
