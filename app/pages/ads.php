<?php
defined('DD') or exit;
$u = user();
if (!conn_can($u, 'ads')) { http_response_code(403); layout_head('Not allowed'); echo '<div class="card"><h1>Not allowed</h1></div>'; layout_foot(); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adupload') {
    csrf_verify();
    $f = $_FILES['report'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) > 15000000) { flash('error', 'Choose the exported report, CSV or Excel, up to 15 MB.'); }
    else {
        try {
            $x = ads_import_file($f['tmp_name'], (string)$f['name'], (string)($_POST['platform'] ?? 'auto'));
            flash('ok', $x['platform'] . ' report read: ' . $x['rows'] . ' rows, ' . fmt_date($x['from']) . ' to ' . fmt_date($x['to']) . ', spend ' . inr($x['spend']) . ', sales ' . inr($x['sales']) . '.' . ($x['skipped'] ? ' Skipped ' . $x['skipped'] . ' total or blank rows.' : ''));
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
    }
    redirect(url('ads'));
}
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
<section class="card"><div class="card__h"><h2>Upload an ad report</h2><span class="muted tiny">Meta or Google, CSV or Excel, broken down by day</span></div>
  <form method="post" enctype="multipart/form-data" class="formrow"><?= csrf_field() ?><input type="hidden" name="action" value="adupload">
    <label class="field"><span>Platform</span><select name="platform"><option value="auto">Auto detect</option><option value="Meta">Meta</option><option value="Google">Google</option></select></label>
    <label class="field grow"><span>Report file</span><input type="file" name="report" accept=".csv,.xlsx,.tsv,.txt" required></label>
    <button class="btn" type="submit">Upload</button>
  </form>
  <p class="tiny muted" style="margin:8px 0 0">Meta: Ads Manager, set Breakdown to Time, Day, pick Campaign level, then Reports, Export. Include Amount spent, Impressions, Link clicks, Purchases and Purchases conversion value. Google: add Segment, Time, Day, then Download as CSV. Uploading days that were already read replaces them, so nothing is counted twice.</p>
</section>
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
