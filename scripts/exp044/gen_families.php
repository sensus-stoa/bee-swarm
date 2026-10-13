<?php
// EXP-044 freeze: emits exp044_families.json (train D1-D8 + sealed test H1-H6)
// Seeds are hash-derived: H seeds NEVER reused by DEV. Run once at freeze; output committed.
$out = ['_comment' => 'EXP-044 freeze v1: train D1-D8, sealed test H1-H6. Generated 2026-10-13. Do not edit.',
        'train' => [], 'test' => []];
$mk = function (string $id, string $class, int $seed, array $extra = []) {
    return array_merge(['id' => $id, 'class' => $class, 'seed' => $seed], $extra);
};
// TRAIN D1-D8: classes whose WORDS must be born automatically
$out['train'][] = $mk('D1_purediff', 'purediff', 800);
$out['train'][] = $mk('D2_purediff', 'purediff', 801);
$out['train'][] = $mk('D3_ratio', 'ratio', 802);
$out['train'][] = $mk('D4_product', 'pureprod', 803);
$out['train'][] = $mk('D5_sq', 'power', 804);
$out['train'][] = $mk('D6_diffprod', 'diffprod', 805);
$out['train'][] = $mk('D7_noisydiff', 'purediff', 806, ['noise' => 0.04]);
$out['train'][] = $mk('D8_flux', 'flux', 807);
// TEST H1-H6 (sealed; seeds hash-derived from 'H' prefix + index, never used by DEV)
foreach (['H1_flux', 'H2_fluxmut', 'H3_ratio', 'H4_power', 'H5_noisyflux', 'H6_decoy'] as $i => $id) {
    $seed = hexdec(substr(md5('EXP044H' . $id), 0, 6));
    $p = ['id' => $id, 'seed' => $seed];
    switch ($i) {
        case 0: $p['class'] = 'flux'; break;                       // H1 same class, new params
        case 1: $p['class'] = 'flux'; $p['perm'] = [1,0,3,2,4];    // H2 mutated: permute + scale
                $p['scale'] = [1.0, 1.0, 1.0, 1.0, 1.0]; break;
        case 2: $p['class'] = 'ratio'; break;                      // H3 ratio (raw-solvable class)
        case 3: $p['class'] = 'power'; break;                      // H4 novel class
        case 4: $p['class'] = 'flux'; $p['noise'] = 0.04; break;   // H5 noisy flux
        case 5: $p['class'] = 'pureprod'; break;                   // H6 DECOY: no diff motif
    }
    $out['test'][] = $p;
}
file_put_contents(__DIR__ . '/exp044_families.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "exp044_families.json written\n";
