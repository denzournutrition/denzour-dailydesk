<?php
defined('DD') or exit;
$u = user();
if (!conn_can($u, 'ads')) { http_response_code(403); layout_head('Not allowed'); echo '<div class="card"><h1>Not allowed</h1></div>'; layout_foot(); exit; }
$P = period_pick(); $month = $P['month'];
$from = $P['from']; $to = min($P['to'], today());
$d = ads_summary($from, $to);
$months = []; for ($i = 0; $i < 12; $i++) { $months[] = date('Y-m', strtotime(substr(today(), 0, 7) . '-01 -' . $i . ' month')); }
$spend = array_sum(array_column($d['by'], 'spend')); $sales = array_sum(array_column($d['by'], 'sales')); $pur = array_sum(array_column($d['by'], 'pur')); $clk = array_sum(array_column($d['by'], 'clk'));
$roas = fn($s, $sp) => $sp > 0 ? number_format($s / $sp, 2) . 'x' : '–';
$connected = (int)db()->query('SELECT COUNT(*) FROM conn WHERE kind IN ("meta_ads","google_ads")')->fetchColumn();
layout_head('Ads and creatives');
?>
<div class="pagehead"><div><h1>Ads and creatives</h1><p class="muted">Meta and Google ad spend, sales and ROAS · <?= h($P['label']) ?></p></div>
  <div class="pagehead__r"><?= period_bar('ads', $P) ?></div></div>
<?php if (!$d['by']): ?>
<section class="card"><p class="muted" style="margin:0"><?= $connected ? 'No ad data for this period yet.' : 'Meta Ads and Google Ads are not connected yet.' ?> <?php if ($u['role'] === 'admin'): ?><a href="<?= h(url('conn')) ?>">Open Connections</a><?php else: ?>Ask your admin to connect them.<?php endif; ?></p></section>
<?php else: ?>
<div class="kpis kpis--4">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Ad spend</span></div><strong class="kpi__v"><?= h(inr_short($spend)) ?></strong></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Sales credited to ads</span></div><strong class="kpi__v"><?= h(inr_short($sales)) ?></strong><span class="kpi__s">Inside Shopify, never added again</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">ROAS</span></div><strong class="kpi__v"><?= h($roas($sales, $spend)) ?></strong></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Purchases</span></div><strong class="kpi__v"><?= number_format($pur) ?></strong><span class="kpi__s"><?= $pur > 0 ? 'Cost each ' . h(inr($spend / $pur)) : '' ?></span></div>
</div>
<section class="card"><div class="card__h"><h2>By platform</h2></div><div class="tblwrap"><table class="tbl"><thead><tr><th>Platform</th><th class="r">Spend</th><th class="r">Sales</th><th class="r">ROAS</th><th class="r">Purchases</th><th class="r">Cost each</th><th class="r hm">Clicks</th><th class="r hm">Cost per click</th></tr></thead><tbody>
<?php foreach ($d['by'] as $r): ?><tr><td><strong><?= h($r['platform']) ?></strong></td><td class="r"><?= h(inr($r['spend'])) ?></td><td class="r"><?= h(inr($r['sales'])) ?></td><td class="r"><?= h($roas($r['sales'], $r['spend'])) ?></td><td class="r"><?= number_format($r['pur']) ?></td><td class="r"><?= $r['pur'] > 0 ? h(inr($r['spend'] / $r['pur'])) : '–' ?></td><td class="r hm"><?= number_format($r['clk']) ?></td><td class="r hm"><?= $r['clk'] > 0 ? h(inr($r['spend'] / $r['clk'])) : '–' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php if ($d['ads']): ?>
<section class="card"><div class="card__h"><h2>Creatives (Meta ads)</h2><span class="muted tiny">Best ROAS first among ads that spent</span></div><div class="tblwrap"><table class="tbl"><thead><tr><th>Ad</th><th class="hm">Campaign</th><th class="r">Spend</th><th class="r">Sales</th><th class="r">ROAS</th><th class="r">Purchases</th><th class="r hm">CTR</th></tr></thead><tbody>
<?php usort($d['ads'], fn($a, $b) => ($b['sales'] / max($b['spend'], 1)) <=> ($a['sales'] / max($a['spend'], 1))); foreach ($d['ads'] as $r): ?>
<tr><td><strong><?= h($r['ad']) ?></strong></td><td class="hm muted"><?= h($r['campaign']) ?></td><td class="r"><?= h(inr($r['spend'])) ?></td><td class="r"><?= h(inr($r['sales'])) ?></td><td class="r"><strong><?= h($roas($r['sales'], $r['spend'])) ?></strong></td><td class="r"><?= number_format($r['pur']) ?></td><td class="r hm"><?= $r['imp'] > 0 ? h(number_format($r['clk'] / $r['imp'] * 100, 2)) . '%' : '–' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<section class="card"><div class="card__h"><h2>Campaigns</h2></div><div class="tblwrap"><table class="tbl"><thead><tr><th>Campaign</th><th>Platform</th><th class="r">Spend</th><th class="r">Sales</th><th class="r">ROAS</th><th class="r">Purchases</th></tr></thead><tbody>
<?php foreach ($d['camp'] as $r): ?><tr><td><strong><?= h($r['campaign']) ?></strong></td><td><?= h($r['platform']) ?></td><td class="r"><?= h(inr($r['spend'])) ?></td><td class="r"><?= h(inr($r['sales'])) ?></td><td class="r"><?= h($roas($r['sales'], $r['spend'])) ?></td><td class="r"><?= number_format($r['pur']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php layout_foot();
