<?php
defined('DD') or exit;
/* Performance marketing dashboard: Meta and Google from the daily Performance Report sheet. */
$u = user();
if (!can_view_dashboard($u) && !conn_can($u, 'ads')) { http_response_code(403); layout_head('Not allowed'); echo '<div class="card"><h1>Not allowed</h1></div>'; layout_foot(); exit; }
$pdo = db(); $today = today();
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); })();
$months = array_column($q('SELECT ym FROM perf_daily GROUP BY ym ORDER BY ym DESC'), 'ym');
$ym = isset($_GET['month']) && in_array($_GET['month'], $months, true) ? $_GET['month'] : ($months[0] ?? substr($today, 0, 7));
$prev = date('Y-m', strtotime($ym . '-01 -1 month'));
$dim = (int)date('t', strtotime($ym . '-01'));
$kpi = fn(string $l, string $v, string $s = '', string $ic = 'trend', string $cls = '') => '<div class="kpi"><div class="kpi__top"><span class="kpi__l">' . h($l) . '</span><span class="kpi__ic ' . $cls . '">' . icon($ic) . '</span></div><strong class="kpi__v">' . $v . '</strong><span class="kpi__s">' . $s . '</span></div>';
$x = fn(float $n, int $d = 2) => number_format($n, $d) . 'x';
$div = fn(float $a, float $b) => $b > 0 ? $a / $b : 0.0;
$tot = function (string $m) use ($q): array {
    $r = $q('SELECT COUNT(*) n, MAX(day) last, SUM(m_spend) ms, SUM(m_sales) mr, SUM(g_spend) gs, SUM(g_sales) gr, SUM(shop) shop, SUM(net) net, SUM(organic) org, SUM(sessions) ses, SUM(carts) car, SUM(checkouts) chk, SUM(orders) ord,
        SUM(bounce * sessions) bsum, SUM(m_impr) mi, SUM(m_orders) mo, SUM(m_visits) mv, SUM(g_impr) gi, SUM(g_orders) go, SUM(g_visits) gv FROM perf_daily WHERE ym = ?', [$m]);
    return array_map('floatval', $r[0] ?? []);
};
$M = $tot($ym);
$meta = $q("SELECT label, last_run, last_ok, last_msg FROM gsync_links WHERE kind = 'perf' ORDER BY id");
layout_head('Performance');
?>
<div class="pagehead">
  <div><h1>Performance marketing</h1><p class="muted">Meta and Google spend, sales and ROAS from the daily Performance Report sheet<?php if ($meta && $meta[0]['last_ok']): ?> · read <?= h(fmt_date(substr($meta[0]['last_run'], 0, 10))) ?> <?= h(substr($meta[0]['last_run'], 11, 5)) ?><?php endif; ?></p></div>
  <div class="pagehead__r"><a class="btn btn--ghost btn--sm" href="<?= h(url('ads')) ?>">Campaigns and creatives</a></div>
</div>
<?php foreach ($meta as $m): if (!$m['last_ok'] && $m['last_msg'] !== ''): ?><p class="tiny" style="color:var(--bad,#c0392b)"><?= h($m['label']) ?>: <?= h($m['last_msg']) ?></p><?php endif; endforeach; ?>
<?php if (!$months): ?>
<section class="card"><p class="muted">Nothing read yet. The Performance Report sheet is linked under Admin, Google Sheets ("Performance report") and is read every 30 minutes. Press Sync now there to read it immediately. It must be shared as "Anyone with the link: Viewer".</p></section>
<?php layout_foot(); return; endif; ?>
<?php
$lastDt = $ym . '-' . sprintf('%02d', (int)$M['last']); $age = (int)round((strtotime($today) - strtotime($lastDt)) / 86400);
if ($age > 3): ?><section class="card" style="border-left:4px solid var(--bad,#c0392b)"><p style="margin:0"><strong>The sheet has not been filled since <?= h(fmt_date($lastDt, true)) ?></strong> (<?= $age ?> days ago). The numbers below are as of that day.</p></section><?php endif; ?>
<div class="filters" style="margin:0 0 12px"><form method="get" class="filters"><input type="hidden" name="p" value="perfdash"><label class="field"><span>Month</span><select name="month" data-autosubmit><?php foreach ($months as $m): ?><option value="<?= h($m) ?>"<?= $m === $ym ? ' selected' : '' ?>><?= h(date('F Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?></select></label></form></div>
<?php
$spend = $M['ms'] + $M['gs']; $adSales = $M['mr'] + $M['gr'];
$roas = $div($adSales, $spend); $mer = $div($M['shop'], $spend); $cpa = $div($spend, $M['ord']);
$n = max(1, (int)$M['n']); $pace = $spend / $n * $dim; $paceSales = $M['shop'] / $n * $dim;
$P = $tot($prev); $pSame = $q('SELECT SUM(m_spend + g_spend) sp, SUM(shop) sh FROM perf_daily WHERE ym = ? AND day <= ?', [$prev, (int)$M['last']])[0] ?? [];
$dSp = !empty($pSame['sp']) ? round(($spend - (float)$pSame['sp']) / (float)$pSame['sp'] * 100) : null;
$dSh = !empty($pSame['sh']) ? round(($M['shop'] - (float)$pSame['sh']) / (float)$pSame['sh'] * 100) : null;
$sgn = fn($v) => ($v >= 0 ? '+' : '') . $v . '%';
?>
<div class="kpis kpis--4">
  <?= $kpi('Ad spend', inr_short($spend), 'Meta ' . inr_short($M['ms']) . ' · Google ' . inr_short($M['gs']) . ($dSp === null ? '' : ' · ' . $sgn($dSp) . ' vs ' . date('M', strtotime($prev . '-01')) . ' same days'), 'trend') ?>
  <?= $kpi('Sales credited to ads', inr_short($adSales), 'as shown in the ad dashboards', 'bag') ?>
  <?= $kpi('ROAS (ads)', $x($roas), $roas >= 2 ? 'healthy' : ($roas >= 1 ? 'thin margin' : 'losing money'), 'target', $roas >= 2 ? 'kpi__ic--ok' : 'kpi__ic--warn') ?>
  <?= $kpi('Shopify sales', inr_short($M['shop']), 'all orders, ads and organic' . ($dSh === null ? '' : ' · ' . $sgn($dSh) . ' vs ' . date('M', strtotime($prev . '-01'))), 'bag') ?>
  <?= $kpi('MER', $x($mer), 'Shopify sales ÷ ad spend', 'trend', $mer >= 3 ? 'kpi__ic--ok' : ($mer < 2 ? 'kpi__ic--warn' : '')) ?>
  <?= $kpi('Cost per order', inr($cpa), (int)$M['ord'] . ' Shopify orders', 'users') ?>
  <?= $kpi('Spend pace', inr_short($pace), 'month-end estimate at this rate', 'clock') ?>
  <?= $kpi('Sales pace', inr_short($paceSales), 'month-end Shopify estimate', 'clock') ?>
</div>

<?php
$asc = array_reverse($q('SELECT * FROM perf_daily WHERE ym = ? ORDER BY day DESC', [$ym]));
$lab = array_map(fn($r) => (string)$r['day'], $asc);
$spd = array_map(fn($r) => round($r['m_spend'] + $r['g_spend']), $asc);
$ads = array_map(fn($r) => round($r['m_sales'] + $r['g_sales']), $asc);
$shp = array_map(fn($r) => round($r['shop']), $asc);
$mroS = array_map(fn($r) => $r['m_spend'] > 0 ? round($r['m_sales'] / $r['m_spend'], 2) : null, $asc);
$merS = array_map(fn($r) => ($r['m_spend'] + $r['g_spend']) > 0 ? round($r['shop'] / ($r['m_spend'] + $r['g_spend']), 2) : null, $asc);
$inrf = fn($v) => inr((float)$v); $xf = fn($v) => number_format((float)$v, 2) . 'x';
$wd = []; foreach ($asc as $r) { $w = (int)date('N', strtotime($ym . '-' . sprintf('%02d', $r['day']))) - 1; $wd[$w]['n'] = ($wd[$w]['n'] ?? 0) + 1; $wd[$w]['shop'] = ($wd[$w]['shop'] ?? 0) + $r['shop']; $wd[$w]['sp'] = ($wd[$w]['sp'] ?? 0) + $r['m_spend'] + $r['g_spend']; $wd[$w]['ms'] = ($wd[$w]['ms'] ?? 0) + $r['m_sales']; }
$wl = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; $wb = []; $wsp = [];
foreach ($wl as $i => $_) { $wb[] = isset($wd[$i]) ? round($wd[$i]['shop'] / $wd[$i]['n']) : 0; $wsp[] = isset($wd[$i]) ? round($wd[$i]['sp'] / $wd[$i]['n']) : 0; }
?>
<div class="chgrid chgrid--2">
  <section class="card"><div class="card__h"><h2>Spend and sales by day</h2><span class="muted tiny">hover a day for the figures</span></div>
    <?= ch_combo($lab, [['name' => 'Ad spend', 'values' => $spd, 'color' => CH_PAL[1]]], [['name' => 'Sales credited to ads', 'values' => $ads, 'color' => CH_PAL[0], 'area' => true], ['name' => 'Shopify sales', 'values' => $shp, 'color' => CH_PAL[2], 'dash' => true]], ['fmt' => $inrf, 'label' => 'Spend and sales by day']) ?></section>
  <section class="card"><div class="card__h"><h2>ROAS and MER by day</h2><span class="muted tiny">below the 1x line you lose money on the ad</span></div>
    <?= ch_combo($lab, [], [['name' => 'Meta ROAS', 'values' => $mroS, 'color' => CH_PAL[0], 'area' => true], ['name' => 'MER (Shopify ÷ spend)', 'values' => $merS, 'color' => CH_PAL[2]]], ['fmt' => $xf, 'axis' => fn($v) => number_format($v, 1) . 'x', 'ref' => [1 => '1x break-even', 2 => '2x'], 'label' => 'ROAS and MER by day']) ?></section>
</div>
<div class="chgrid">
  <section class="card"><div class="card__h"><h2>Where the spend went</h2></div><?= ch_donut([['Meta', $M['ms'], CH_PAL[2]], ['Google', $M['gs'], CH_PAL[1]]], inr_short($spend), 'ad spend', $inrf) ?></section>
  <section class="card"><div class="card__h"><h2>Where the sales came from</h2></div><?= ch_donut([['Meta ads', $M['mr'], CH_PAL[2]], ['Google ads', $M['gr'], CH_PAL[1]], ['Other sales', max(0, $M['shop'] - $adSales), CH_PAL[0]]], inr_short($M['shop']), 'Shopify sales', $inrf) ?></section>
  <section class="card" style="grid-column:1/-1"><div class="card__h"><h2>Health check</h2><span class="muted tiny">month so far</span></div>
    <div class="gauges">
      <?= ch_gauge($roas, 4, $x($roas), 'Ad ROAS (target 2x)', ['bad' => 1, 'warn' => 2]) ?>
      <?= ch_gauge($mer, 6, $x($mer), 'MER (target 3x)', ['bad' => 2, 'warn' => 3]) ?>
      <?= ch_gauge($M['ses'] > 0 ? $M['ord'] / $M['ses'] * 100 : 0, 2, ($M['ses'] > 0 ? number_format($M['ord'] / $M['ses'] * 100, 2) : '0') . '%', 'Store conversion (target 1%)', ['bad' => .5, 'warn' => 1]) ?>
    </div></section>
</div>
<div class="chgrid chgrid--2">
  <section class="card"><div class="card__h"><h2>Which weekday sells most</h2><span class="muted tiny">average per day</span></div>
    <?= ch_combo($wl, [['name' => 'Shopify sales', 'values' => $wb, 'color' => CH_PAL[0]]], [['name' => 'Ad spend', 'values' => $wsp, 'color' => CH_PAL[1]]], ['fmt' => $inrf, 'h' => 200, 'every' => 1, 'label' => 'Average sales and spend by weekday']) ?></section>
  <?php if (count($months) > 1): $mAsc = array_reverse($months); $mt = array_map($tot, $mAsc); ?>
  <section class="card"><div class="card__h"><h2>Month by month</h2></div>
    <?= ch_combo(array_map(fn($m) => date('M', strtotime($m . '-01')), $mAsc), [['name' => 'Ad spend', 'values' => array_map(fn($t) => round($t['ms'] + $t['gs']), $mt), 'color' => CH_PAL[1]]], [['name' => 'Shopify sales', 'values' => array_map(fn($t) => round($t['shop']), $mt), 'color' => CH_PAL[0], 'area' => true]], ['fmt' => $inrf, 'h' => 200, 'every' => 1, 'label' => 'Spend and sales by month']) ?></section>
  <?php else: ?>
  <section class="card"><div class="card__h"><h2>Orders by day</h2><span class="muted tiny">Shopify orders, with the ones Meta got credit for</span></div>
    <?= ch_combo($lab, [['name' => 'Shopify orders', 'values' => array_map(fn($r) => (int)$r['orders'], $asc), 'color' => CH_PAL[0]]], [['name' => 'Meta orders', 'values' => array_map(fn($r) => (int)$r['m_orders'], $asc), 'color' => CH_PAL[2]]], ['fmt' => fn($v) => number_format((float)$v), 'h' => 200, 'label' => 'Orders by day']) ?></section>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
<?php foreach ([['Meta', 'ms', 'mr', 'mi', 'mv', 'mo'], ['Google', 'gs', 'gr', 'gi', 'gv', 'go']] as [$nm, $ks, $kr, $ki, $kv, $ko]):
  $s = $M[$ks]; $r = $M[$kr]; $im = $M[$ki]; $vi = $M[$kv]; $od = $M[$ko]; ?>
<section class="card"><div class="card__h"><h2><?= h($nm) ?> ads</h2><span class="muted tiny"><?= $spend > 0 ? round($s / $spend * 100) . '% of spend' : '' ?></span></div>
<?php if ($s <= 0): ?><p class="muted" style="margin:0">No <?= h($nm) ?> spend recorded for <?= h(date('F Y', strtotime($ym . '-01'))) ?>.</p><?php else: ?>
  <?php $st = fn(string $l, string $v, string $sub = '') => '<div><span class="tiny muted">' . h($l) . '</span><div style="font-size:19px;font-weight:700;line-height:1.25">' . $v . '</div>' . ($sub !== '' ? '<span class="tiny muted">' . h($sub) . '</span>' : '') . '</div>'; ?>
  <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px">
    <?= $st('Spend', inr_short($s)) ?><?= $st('Sales', inr_short($r)) ?>
    <?= $st('ROAS', $x($div($r, $s)), $div($r, $s) >= 2 ? 'healthy' : 'below 2x') ?><?= $st('Cost per order', $od > 0 ? inr($s / $od) : '–', (int)$od . ' orders') ?>
    <?= $st('Impressions', $im >= 100000 ? number_format($im / 100000, 1) . ' L' : number_format($im)) ?><?= $st('Visits', number_format($vi), $im > 0 ? 'CTR ' . number_format($vi / $im * 100, 2) . '%' : '') ?>
    <?= $st('Cost per visit', $vi > 0 ? inr($s / $vi) : '–') ?><?= $st('Visit to order', $vi > 0 ? number_format($od / $vi * 100, 2) . '%' : '–') ?>
  </div>
<?php endif; ?></section>
<?php endforeach; ?>
</div>

<section class="card"><div class="card__h"><h2>Day by day</h2><span class="muted tiny"><?= h(date('F Y', strtotime($ym . '-01'))) ?> · ROAS under 1x in red, 3x and above in green</span></div>
<?php $rows = $q('SELECT * FROM perf_daily WHERE ym = ? ORDER BY day DESC', [$ym]); $mxSp = max(1.0, ...array_map(fn($r) => (float)$r['m_spend'] + (float)$r['g_spend'], $rows ?: [['m_spend' => 1, 'g_spend' => 0]])); ?>
<div class="tblwrap"><table class="tbl"><thead><tr><th>Day</th><th class="r">Meta spend</th><th class="r">Meta sales</th><th class="r">Meta ROAS</th><th class="r hm">Google spend</th><th class="r hm">Google sales</th><th class="r">Shopify sales</th><th class="r">MER</th><th class="hm" style="width:18%">Spend</th></tr></thead><tbody>
<?php foreach ($rows as $r): $sp = $r['m_spend'] + $r['g_spend']; $mr = $div($r['m_sales'], $r['m_spend']); $mm = $div($r['shop'], $sp); ?>
<tr><td style="white-space:nowrap"><strong><?= h(date('D, j M', strtotime($ym . '-' . sprintf('%02d', $r['day'])))) ?></strong></td><td class="r"><?= h(inr($r['m_spend'])) ?></td><td class="r"><?= h(inr($r['m_sales'])) ?></td>
<td class="r"><span class="chip <?= $r['m_spend'] > 0 && $mr < 1 ? 'chip--warn' : '' ?>" style="<?= $mr >= 3 ? 'background:#d8f0dd;color:#1d6b34' : '' ?>"><?= $r['m_spend'] > 0 ? h($x($mr)) : '–' ?></span></td>
<td class="r hm"><?= $r['g_spend'] > 0 ? h(inr($r['g_spend'])) : '–' ?></td><td class="r hm"><?= $r['g_spend'] > 0 ? h(inr($r['g_sales'])) : '–' ?></td><td class="r"><?= h(inr($r['shop'])) ?></td><td class="r"><?= $sp > 0 ? h($x($mm)) : '–' ?></td>
<td class="hm"><span class="hbar__t"><i style="width:<?= round($sp / $mxSp * 100) ?>%"></i></span></td></tr>
<?php endforeach; ?></tbody></table></div></section>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
<section class="card"><div class="card__h"><h2>Store funnel</h2><span class="muted tiny">all traffic, from the sheet</span></div>
<?php $fun = [['Sessions', $M['ses'], ''], ['Added to cart', $M['car'], $M['ses'] > 0 ? number_format($M['car'] / $M['ses'] * 100, 1) . '% of sessions' : ''], ['Checkout started', $M['chk'], $M['car'] > 0 ? number_format($M['chk'] / $M['car'] * 100, 1) . '% of carts' : ''], ['Orders', $M['ord'], $M['ses'] > 0 ? number_format($M['ord'] / $M['ses'] * 100, 2) . '% of sessions' : '']]; $fm = max(1, $M['ses']); ?>
<div class="hbars"><?php foreach ($fun as [$l, $v, $s]): ?><div class="hbar hbar--w"><span class="hbar__l"><?= h($l) ?></span><span class="hbar__t"><i style="width:<?= round(min(100, $v / $fm * 100)) ?>%"></i></span><span class="hbar__v"><?= number_format($v) ?><?= $s !== '' ? ' · ' . h($s) : '' ?></span></div><?php endforeach; ?></div>
<p class="tiny muted" style="margin:10px 0 0">Average order <?= h(inr($div($M['shop'], $M['ord']))) ?> · bounce rate <?= $M['ses'] > 0 ? number_format($M['bsum'] / $M['ses'] * 100, 1) . '%' : '–' ?> · organic sales <?= h(inr_short($M['org'])) ?> (<?= $M['shop'] > 0 ? round($M['org'] / $M['shop'] * 100) : 0 ?>% of Shopify)</p></section>

<?php $best = array_filter($rows, fn($r) => $r['m_spend'] >= 5000); usort($best, fn($a, $b) => $div($b['m_sales'], $b['m_spend']) <=> $div($a['m_sales'], $a['m_spend'])); ?>
<section class="card"><div class="card__h"><h2>Best and worst Meta days</h2><span class="muted tiny">days with spend of ₹5,000 or more</span></div>
<?php if (count($best) < 2): ?><p class="muted" style="margin:0">Not enough days yet.</p><?php else: ?>
<div class="tblwrap"><table class="tbl"><thead><tr><th>Day</th><th class="r">Spend</th><th class="r">Sales</th><th class="r">ROAS</th></tr></thead><tbody>
<?php $pick = array_merge(array_slice($best, 0, 3), array_slice($best, -3)); $seen = []; foreach ($pick as $r): if (isset($seen[$r['day']])) { continue; } $seen[$r['day']] = 1; $mr = $div($r['m_sales'], $r['m_spend']); ?>
<tr><td style="white-space:nowrap"><strong><?= h(date('D, j M', strtotime($ym . '-' . sprintf('%02d', $r['day'])))) ?></strong></td><td class="r"><?= h(inr($r['m_spend'])) ?></td><td class="r"><?= h(inr($r['m_sales'])) ?></td><td class="r"><span class="chip <?= $mr < 1 ? 'chip--warn' : '' ?>"><?= h($x($mr)) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
</div>

<section class="card"><div class="card__h"><h2>Month by month</h2><span class="muted tiny">every month the sheet has held</span></div>
<div class="tblwrap"><table class="tbl"><thead><tr><th>Month</th><th class="r">Ad spend</th><th class="r">Ads sales</th><th class="r">ROAS</th><th class="r">Shopify sales</th><th class="r">MER</th><th class="r">Orders</th><th class="r">Cost per order</th></tr></thead><tbody>
<?php foreach ($months as $m): $t = $tot($m); $sp = $t['ms'] + $t['gs']; ?>
<tr><td><strong><a href="<?= h(url('perfdash', ['month' => $m])) ?>"><?= h(date('M Y', strtotime($m . '-01'))) ?></a></strong></td><td class="r"><?= h(inr_short($sp)) ?></td><td class="r"><?= h(inr_short($t['mr'] + $t['gr'])) ?></td><td class="r"><?= h($x($div($t['mr'] + $t['gr'], $sp))) ?></td><td class="r"><?= h(inr_short($t['shop'])) ?></td><td class="r"><?= h($x($div($t['shop'], $sp))) ?></td><td class="r"><?= (int)$t['ord'] ?></td><td class="r"><?= h(inr($div($sp, $t['ord']))) ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<p class="tiny muted">Sales credited to ads are the figures shown in Meta and Google, so they are usually lower than actual sales. MER uses all Shopify sales.</p>
<?php layout_foot();
