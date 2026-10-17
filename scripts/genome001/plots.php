<?php

declare(strict_types=1);

/**
 * GENOME-001 CHECKPOINT B: SVG-графики из ЗАМОРОЖЕННЫХ сырых данных
 * (checkpoint_b_raw.json). Пост-обработка, не повторное измерение.
 */

$raw = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Benchmarks/genome001/checkpoint_b_raw.json'), true, 512, JSON_THROW_ON_ERROR);

function svgPolyline(array $pts, string $color, string $dash = ''): string
{
    $d = implode(' ', array_map(static fn (array $p): string => sprintf('%.1f,%.1f', $p[0], $p[1]), $pts));
    $dashAttr = $dash === '' ? '' : ' stroke-dasharray="' . $dash . '"';

    return '<polyline points="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="2"' . $dashAttr . '/>';
}

function svgAxis(int $ox, int $oy, int $w, int $h, string $xlabel, string $ylabel): string
{
    return '<line x1="' . $ox . '" y1="' . $oy . '" x2="' . ($ox + $w) . '" y2="' . $oy . '" stroke="#666"/>'
        . '<line x1="' . $ox . '" y1="' . $oy . '" x2="' . $ox . '" y2="' . ($oy - $h) . '" stroke="#666"/>'
        . '<text x="' . ($ox + $w / 2) . '" y="' . ($oy + 34) . '" font-size="13" fill="#888" text-anchor="middle">' . $xlabel . '</text>'
        . '<text x="' . ($ox - 42) . '" y="' . ($oy - $h / 2) . '" font-size="13" fill="#888" text-anchor="middle" transform="rotate(-90 ' . ($ox - 42) . ' ' . ($oy - $h / 2) . ')">' . $ylabel . '</text>';
}

function svgLabel(int $x, float $y, string $color, string $text): string
{
    return '<text x="' . $x . '" y="' . $y . '" font-size="13" fill="' . $color . '">' . htmlspecialchars($text) . '</text>';
}

// ── График 1: размер представления от развёрнутой сложности (SIZE-ось) ──
$panels = '';
$px = 60;
foreach (['REGULAR', 'RANDOM', 'REAL'] as $ci => $corpus) {
    $per = $raw[$corpus]['size']['per_program'];
    usort($per, static fn (array $a, array $b): int => $a['nodes'] <=> $b['nodes']);
    $maxA = max(array_column($per, 'a_bytes'));
    $w = 320;
    $h = 220;
    $ox = $px + 46;
    $oy = 250;
    $scaleX = $w / max(1, max(array_column($per, 'nodes')));
    $scaleY = $h / $maxA;
    $pa = array_map(static fn (array $p): array => [$ox + $p['nodes'] * $scaleX, $oy - $p['a_bytes'] * $scaleY], $per);
    $pd = array_map(static fn (array $p): array => [$ox + $p['nodes'] * $scaleX, $oy - $p['d_genome_bytes'] * $scaleY], $per);
    $dictY = $oy - min($raw[$corpus]['size']['totals']['d_dict_bytes'], $maxA) * $scaleY;
    $panel = '<g>' . svgAxis($ox, $oy, $w, $h, 'развернутые узлы', 'bytes')
        . svgPolyline($pa, '#e05d44') . svgPolyline($pd, '#4a9e4a')
        . '<line x1="' . $ox . '" y1="' . $dictY . '" x2="' . ($ox + $w) . '" y2="' . $dictY . '" stroke="#4477aa" stroke-dasharray="5,4"/>'
        . svgLabel($ox + 6, $oy - $h - 6, '#ddd', $corpus . ' (n=' . count($per) . ', dict=' . $raw[$corpus]['size']['totals']['d_dict_bytes'] . ' B)')
        . svgLabel($ox + $w - 130, $oy - $maxA * $scaleY * 0.12, '#e05d44', '— A (AST json)')
        . svgLabel($ox + $w - 130, $oy - $maxA * $scaleY * 0.12 + 16, '#4a9e4a', '— D геномы (без словаря)')
        . svgLabel($ox + $w - 130, $oy - $maxA * $scaleY * 0.12 + 32, '#4477aa', '-- словарь D')
        . '</g>';
    $panels .= $panel;
    $px += 380;
}
$svg1 = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="290" font-family="monospace" style="background:#141821">'
    . '<rect width="1200" height="290" fill="#141821"/>'
    . '<text x="600" y="24" font-size="15" fill="#aaa" text-anchor="middle">GENOME-001 Checkpoint B: размер представления vs развёрнутая сложность (SIZE-ось, test)</text>'
    . $panels . '</svg>';
file_put_contents(dirname(__DIR__, 2) . '/Benchmarks/genome001/checkpoint_b_size.svg', $svg1);

// ── График 2: кумулятивная стоимость от числа программ (COUNT-ось, break-even) ──
$panels = '';
$px = 60;
foreach (['REGULAR', 'RANDOM', 'REAL'] as $ci => $corpus) {
    $prefixes = $raw[$corpus]['count']['prefixes'];
    $w = 320;
    $h = 220;
    $ox = $px + 46;
    $oy = 250;
    $maxV = max(array_merge(
        array_column($prefixes, 'a'),
        array_column($prefixes, 'b'),
        array_column($prefixes, 'c'),
        array_column($prefixes, 'd')
    ));
    $scaleX = $w / max(1, max(array_column($prefixes, 'n')));
    $scaleY = $h / $maxV;
    $mk = static fn (string $k): array => array_map(static fn (array $p): array => [$ox + $p['n'] * $scaleX, $oy - $p[$k] * $scaleY], $prefixes);
    $panel = '<g>' . svgAxis($ox, $oy, $w, $h, 'программ N', 'total bytes')
        . svgPolyline($mk('a'), '#e05d44') . svgPolyline($mk('b'), '#c9a227') . svgPolyline($mk('c'), '#8844aa') . svgPolyline($mk('d'), '#4a9e4a')
        . svgLabel($ox + 6, $oy - $h - 6, '#ddd', $corpus . ' (словарь train-only, заморожен)')
        . svgLabel($ox + $w - 150, $oy - $maxV * $scaleY * 0.05, '#e05d44', '— A')
        . svgLabel($ox + $w - 150, $oy - $maxV * $scaleY * 0.05 + 15, '#c9a227', '— B (DAG)')
        . svgLabel($ox + $w - 150, $oy - $maxV * $scaleY * 0.05 + 30, '#8844aa', '— C (gzip)')
        . svgLabel($ox + $w - 150, $oy - $maxV * $scaleY * 0.05 + 45, '#4a9e4a', '— D (dict+genomes)')
        . '</g>';
    $panels .= $panel;
    $px += 380;
}
$svg2 = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="290" font-family="monospace" style="background:#141821">'
    . '<rect width="1200" height="290" fill="#141821"/>'
    . '<text x="600" y="24" font-size="15" fill="#aaa" text-anchor="middle">GENOME-001 Checkpoint B: кумулятивная стоимость от числа программ (COUNT-ось, break-even)</text>'
    . $panels . '</svg>';
file_put_contents(dirname(__DIR__, 2) . '/Benchmarks/genome001/checkpoint_b_count.svg', $svg2);

echo "written: checkpoint_b_size.svg, checkpoint_b_count.svg\n";
