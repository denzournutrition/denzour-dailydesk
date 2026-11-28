<?php
defined('DD') or exit;
/* Small inline-SVG chart helpers shared by the dashboards. No scripts, no libraries; hover text comes from <title>. */

const CH_PAL = ['#2F6F55', '#C27A12', '#3B6EA8', '#8A4F7D', '#1F8A8A', '#B42318', '#6B7F3A', '#7A6F66'];

function ch_nice(float $max, int $ticks = 4): array
{
    if ($max <= 0) { return [1.0, 1.0 / $ticks]; }
    $raw = $max / $ticks; $mag = 10 ** floor(log10($raw)); $n = $raw / $mag;
    $step = ($n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10))) * $mag;
    return [$step * $ticks, $step];
}

function ch_short(float $v): string
{
    $a = abs($v);
    if ($a >= 10000000) { return rtrim(rtrim(number_format($v / 10000000, 1), '0'), '.') . 'Cr'; }
    if ($a >= 100000) { return rtrim(rtrim(number_format($v / 100000, 1), '0'), '.') . 'L'; }
    if ($a >= 1000) { return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k'; }
    return rtrim(rtrim(number_format($v, 1), '0'), '.');
}

/**
 * Bars and lines on one scale.
 * @param array $bars  [['name'=>, 'values'=>[], 'color'=>]] (first series only is drawn as bars, others stacked side by side not supported)
 * @param array $lines [['name'=>, 'values'=>[], 'color'=>, 'dash'=>bool]]
 * @param array $o     fmt (callable for tooltips), axis (callable for y ticks), h (height), ref ([value => label]), every (label step)
 */
function ch_combo(array $labels, array $bars, array $lines, array $o = []): string
{
    static $uid = 0; $uid++;
    $n = count($labels); if ($n < 1) { return '<p class="muted tiny">No data yet.</p>'; }
    $W = 480; $H = (int)($o['h'] ?? 230); $l = 40; $r = 12; $t = 12; $b = 26;
    $fmt = $o['fmt'] ?? fn($v) => ch_short((float)$v); $axis = $o['axis'] ?? fn($v) => ch_short((float)$v);
    $all = [];
    foreach (array_merge($bars, $lines) as $s) { foreach ($s['values'] as $v) { $all[] = (float)$v; } }
    foreach (array_keys($o['ref'] ?? []) as $rv) { $all[] = (float)$rv; }
    [$top, $step] = ch_nice(max(array_merge([0.0], $all)));
    $pw = $W - $l - $r; $ph = $H - $t - $b; $cw = $pw / $n;
    $x = fn(int $i) => $l + $cw * ($i + .5); $y = fn(float $v) => $t + $ph - ($top > 0 ? $v / $top * $ph : 0);
    $s = '<svg class="chart chart--rich" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h((string)($o['label'] ?? 'Chart')) . '" preserveAspectRatio="xMidYMid meet">';
    for ($g = 0; $g <= 4; $g++) { $v = $step * $g; $yy = $y($v); $s .= '<line class="grid" x1="' . $l . '" x2="' . ($W - $r) . '" y1="' . round($yy, 1) . '" y2="' . round($yy, 1) . '"/><text class="axis" x="' . ($l - 6) . '" y="' . round($yy + 4, 1) . '" text-anchor="end">' . h($axis($v)) . '</text>'; }
    foreach ($o['ref'] ?? [] as $rv => $rl) { $yy = $y((float)$rv); $s .= '<line class="refl" x1="' . $l . '" x2="' . ($W - $r) . '" y1="' . round($yy, 1) . '" y2="' . round($yy, 1) . '"/><text class="axis refl__t" x="' . ($W - $r) . '" y="' . round($yy - 4, 1) . '" text-anchor="end">' . h((string)$rl) . '</text>'; }
    $every = (int)($o['every'] ?? max(1, (int)ceil($n / 12)));
    foreach ($labels as $i => $lb) { if ($i % $every === 0 || $i === $n - 1) { $s .= '<text class="axis" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . h((string)$lb) . '</text>'; } }
    foreach ($bars as $bs) {
        $bw = max(2.0, $cw * .62); $col = $bs['color'] ?? CH_PAL[0];
        foreach ($bs['values'] as $i => $v) { $v = (float)$v; if ($v <= 0) { continue; } $yy = $y($v); $s .= '<rect class="bar" x="' . round($x($i) - $bw / 2, 1) . '" y="' . round($yy, 1) . '" width="' . round($bw, 1) . '" height="' . round($t + $ph - $yy, 1) . '" rx="' . min(3, round($bw / 3, 1)) . '" fill="' . $col . '" opacity=".88"/>'; }
    }
    foreach ($lines as $k => $ln) {
        $col = $ln['color'] ?? CH_PAL[($k + 1) % count(CH_PAL)]; $pts = [];
        foreach ($ln['values'] as $i => $v) { if ($v === null) { continue; } $pts[] = round($x($i), 1) . ',' . round($y((float)$v), 1); }
        if (count($pts) > 1) {
            if (!empty($ln['area'])) { $gid = 'cg' . $uid . '_' . $k; $s .= '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $col . '" stop-opacity=".25"/><stop offset="1" stop-color="' . $col . '" stop-opacity="0"/></linearGradient></defs><polygon points="' . $pts[0] . ' ' . implode(' ', $pts) . ' ' . explode(',', end($pts))[0] . ',' . ($t + $ph) . ' ' . explode(',', $pts[0])[0] . ',' . ($t + $ph) . '" fill="url(#' . $gid . ')"/>'; }
            $s .= '<polyline fill="none" stroke="' . $col . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"' . (!empty($ln['dash']) ? ' stroke-dasharray="5 4"' : '') . ' points="' . implode(' ', $pts) . '"/>';
        }
        if ($n <= 31) { foreach ($ln['values'] as $i => $v) { if ($v === null) { continue; } $s .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y((float)$v), 1) . '" r="2.6" fill="#fff" stroke="' . $col . '" stroke-width="1.8"/>'; } }
    }
    foreach ($labels as $i => $lb) {
        $tip = (string)$lb; foreach (array_merge($bars, $lines) as $sr) { if (isset($sr['values'][$i]) && $sr['values'][$i] !== null) { $tip .= "\n" . $sr['name'] . ': ' . $fmt($sr['values'][$i]); } }
        $s .= '<rect class="ch-hit" x="' . round($l + $cw * $i, 1) . '" y="' . $t . '" width="' . round($cw, 1) . '" height="' . $ph . '"><title>' . h($tip) . '</title></rect>';
    }
    $s .= '</svg>';
    $lg = '';
    foreach (array_merge($bars, $lines) as $k => $sr) { $lg .= '<span class="lgi"><i style="background:' . ($sr['color'] ?? CH_PAL[$k % count(CH_PAL)]) . '"></i>' . h($sr['name']) . '</span>'; }
    return $s . '<div class="legend">' . $lg . '</div>';
}

/** Ring chart with a total in the middle and a legend beside it. $parts = [[label, value, color?]] */
function ch_donut(array $parts, string $big = '', string $small = '', ?callable $fmt = null): string
{
    $fmt = $fmt ?? fn($v) => number_format((float)$v);
    $parts = array_values(array_filter($parts, fn($p) => (float)$p[1] > 0));
    $tot = array_sum(array_map(fn($p) => (float)$p[1], $parts));
    if ($tot <= 0) { return '<p class="muted tiny" style="margin:0">Nothing to show yet.</p>'; }
    $s = '<div class="donutw"><svg class="donut" viewBox="0 0 42 42" role="img"><circle cx="21" cy="21" r="15.9155" fill="none" stroke="var(--soft)" stroke-width="5.4"/>';
    $off = 25.0; $lg = '';
    foreach ($parts as $i => $p) {
        $v = (float)$p[1]; $pc = $v / $tot * 100; $col = $p[2] ?? CH_PAL[$i % count(CH_PAL)];
        $s .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="' . $col . '" stroke-width="5.4" stroke-dasharray="' . round(max(0, $pc - (count($parts) > 1 ? .6 : 0)), 2) . ' ' . round(100 - max(0, $pc - (count($parts) > 1 ? .6 : 0)), 2) . '" stroke-dashoffset="' . round($off, 2) . '"><title>' . h($p[0] . ': ' . $fmt($v) . ' (' . round($pc) . '%)') . '</title></circle>';
        $off -= $pc;
        $lg .= '<li><i style="background:' . $col . '"></i><span class="dl">' . h((string)($p[0] !== '' ? $p[0] : 'Not filled')) . '</span><span class="dv">' . h($fmt($v)) . ' <em>' . round($pc) . '%</em></span></li>';
    }
    if ($big !== '') { $s .= '<text x="21" y="' . ($small !== '' ? 21.5 : 23.5) . '" text-anchor="middle" class="d-big">' . h($big) . '</text>' . ($small !== '' ? '<text x="21" y="27" text-anchor="middle" class="d-small">' . h($small) . '</text>' : ''); }
    return $s . '</svg><ul class="dlegend">' . $lg . '</ul></div>';
}

/** Half-circle gauge. $bands = ['bad' => upper bound of red, 'warn' => upper bound of amber]; above is green. */
function ch_gauge(float $v, float $max, string $text, string $label, array $bands = []): string
{
    $p = $max > 0 ? max(0, min(1, $v / $max)) : 0; $len = 125.66;
    if (!empty($bands['invert'])) { $col = $v >= ($bands['bad'] ?? 1e9) ? 'var(--bad)' : ($v >= ($bands['warn'] ?? 1e9) ? 'var(--warn)' : 'var(--ok)'); }
    else { $col = isset($bands['bad']) && $v < $bands['bad'] ? 'var(--bad)' : (isset($bands['warn']) && $v < $bands['warn'] ? 'var(--warn)' : 'var(--ok)'); }
    return '<div class="gaugew"><svg class="gsvg" viewBox="0 0 100 62" role="img" aria-label="' . h($label . ' ' . $text) . '"><path d="M10 52 A40 40 0 0 1 90 52" fill="none" stroke="var(--soft)" stroke-width="9" stroke-linecap="round"/><path d="M10 52 A40 40 0 0 1 90 52" fill="none" stroke="' . $col . '" stroke-width="9" stroke-linecap="round" stroke-dasharray="' . round($len * $p, 2) . ' ' . $len . '"/><text x="50" y="46" text-anchor="middle" class="g-big">' . h($text) . '</text></svg><span class="tiny muted">' . h($label) . '</span></div>';
}

/** Ring chart from a keyed rows array like ['Source' => ['sales' => 1, 'orders' => 2]]; top 7 plus "Others". */
function ch_rows(array $rows, string $field = 'sales', string $small = '', bool $money = true): string
{
    $parts = []; $tot = 0.0;
    foreach ($rows as $k => $v) { $parts[] = [(string)$k, (float)$v[$field]]; $tot += (float)$v[$field]; }
    usort($parts, fn($a, $b) => $b[1] <=> $a[1]);
    if (count($parts) > 7) { $rest = array_sum(array_column(array_slice($parts, 7), 1)); $parts = array_slice($parts, 0, 7); if ($rest > 0) { $parts[] = ['Others', $rest]; } }
    return ch_donut($parts, $money ? inr_short($tot) : number_format($tot), $small, $money ? fn($v) => inr((float)$v) : fn($v) => number_format((float)$v));
}
