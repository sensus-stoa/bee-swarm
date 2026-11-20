#!/usr/bin/env python3
"""
B7 этап 2а (v3): genome stats через assembly_stats.txt (NCBI FTP).
Feature: genome size (Mb), GC%, total genes, protein-coding genes.
"""
import urllib.request, json, urllib.parse, time, re, gzip, io

SPECIES = {
    'AC': 'Anaerostipes caccae', 'BF': 'Bacteroides fragilis',
    'BP': 'Bifidobacterium pseudocatenulatum', 'BC': 'Bacteroides caccae',
    'BH': 'Blautia hydrogenotrophica', 'BL': 'Bifidobacterium longum',
    'BO': 'Bacteroides ovatus', 'BT': 'Bacteroides thetaiotaomicron',
    'BU': 'Bacteroides uniformis', 'BV': 'Phocaeicola vulgatus',
    'BY': 'Bacteroides cellulosilyticus', 'CA': 'Collinsella aerofaciens',
    'CC': 'Coprococcus comes', 'CG': 'Clostridium asparagiforme',
    'CH': 'Clostridium hiranonis', 'DF': 'Dorea formicigenerans',
    'DL': 'Dorea longicatena', 'DP': 'Desulfovibrio piger',
    'EL': 'Eggerthella lenta', 'ER': 'Agathobacter rectalis',
    'FP': 'Faecalibacterium duncaniae', 'HB': 'Holdemanella biformis',
    'PC': 'Segatella copri', 'RI': 'Roseburia intestinalis',
    'PJ': 'Parabacteroides johnsonii',
}

def eutils(db, term, retmax=3):
    q = urllib.parse.quote(term)
    url = f'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db={db}&term={q}&retmode=json&retmax={retmax}'
    req = urllib.request.Request(url, headers={'User-Agent': 'bee-swarm/1.0'})
    return json.load(urllib.request.urlopen(req, timeout=40))['esearchresult']['idlist']

def esummary_assembly(uid):
    url = f'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=assembly&id={uid}&retmode=json'
    req = urllib.request.Request(url, headers={'User-Agent': 'bee-swarm/1.0'})
    return json.load(urllib.request.urlopen(req, timeout=40))['result'][uid]

def fetch_stats_rpt(ftp_path):
    https = ftp_path.replace('ftp://ftp.ncbi.nlm.nih.gov', 'https://ftp.ncbi.nlm.nih.gov')
    req = urllib.request.Request(https, headers={'User-Agent': 'bee-swarm/1.0'})
    txt = urllib.request.urlopen(req, timeout=60).read().decode(errors='ignore')
    size = gc = genes = prots = None
    for line in txt.splitlines():
        p = line.rstrip().split('\t')
        if len(p) >= 6 and p[0] == 'all' and p[3] == 'all':
            stat, val = p[4], p[5]
            if stat == 'total-length':
                size = float(val) / 1e6
            elif stat == 'gc-perc':
                gc = float(val)
    return size, gc, genes, prots

stats = {}
for code, taxon in SPECIES.items():
    try:
        ids = eutils('assembly', f'{taxon}[ORGN] AND latest[filter]')
        if not ids:
            stats[code] = {'taxon': taxon, 'error': 'not found'}
            print(f'{code}: не найдено')
            continue
        doc = esummary_assembly(ids[0])
        rp = doc.get('ftppath_stats_rpt', '')
        size = gc = genes = prots = None
        if rp:
            size, gc, genes, prots = fetch_stats_rpt(rp)
        stats[code] = {'taxon': taxon, 'organism': doc.get('organism', ''),
                       'accession': doc.get('assemblyaccession', ''),
                       'size_mb': size, 'gc': gc, 'genes': genes, 'proteins': prots}
        print(f"{code}: {doc.get('organism','')[:42]:42.42} {size}Mb GC={gc}% genes={genes}")
        time.sleep(0.4)
    except Exception as e:
        stats[code] = {'taxon': taxon, 'error': str(e)[:70]}
        print(f'{code}: ERR {str(e)[:55]}')
        time.sleep(0.4)

json.dump(stats, open('/tmp/clark_genome_stats.json', 'w'), indent=1)
ok = sum(1 for v in stats.values() if 'error' not in v and v.get('size_mb'))
print(f'\nготово: {ok}/25')
