<?php
defined('DD') or exit;

$u = user();
$today = today();
$month = s_month($_GET['month'] ?? null);
$stats = sales_stats($month);
$tg = sales_targets($month);
$members = sales_members();
$nMem = max(1, count($members));
$t = $stats['team'];
$target = (float)$tg['team']['sales'];
$pace = sales_pace($month, $target, $t['sales']);
$tPct = pct($t['sales'], $target);
$aov = $t['orders'] ? $t['sales'] / $t['orders'] : 0;
$connPct = $t['calls'] ? (int)round($t['connected'] / $t['calls'] * 100) : 0;
$label = date('F Y', strtotime($month . '-01'));
$ordTarget = (int)$tg['team']['orders'];
$avgCalls = $pace['elapsed'] > 0 ? round($t['calls'] / $pace['elapsed']) : 0;
$hasData = $t['orders'] || $t['calls'];

layout_head('Inside Sales');
?>
<div class="pagehead">
  <div><h1>Inside Sales</h1><p class="muted">View only · <?= h($label) ?> · updates when the team uploads</p></div>
  <div class="pagehead__r">
    <?php if ($u['role'] === 'admin'): ?><a class="btn btn--ghost" href="<?= h(url('salestargets', ['month' => $month])) ?>"><?= icon('target') ?>Set targets</a><?php endif; ?>
  </div>
</div>
<?php s_month_filter('salesdash', $month); ?>
<?php track_person_panel($month, 'bhanu', 'Bhanu · Inside Sales'); ?>

<section class="card tgt" aria-label="Team target">
  <?php if ($target > 0): ?>
    <div class="tgt__top">
      <div><span class="tgt__l">Team sales this month</span>
        <div class="bignum"><strong><?= h(inr($t['sales'])) ?></strong><span class="muted">of <?= h(inr($target)) ?> target</span>
          <span class="chip <?= (!$pace['live'] || $tPct >= $pace['expected_pct']) ? 'chip--ok' : 'chip--warn' ?>"><?= $tPct ?>% achieved</span></div></div>
      <?php if ($pace['live']): ?><div class="tgt__pace muted tiny">Should be at <?= (int)$pace['expected_pct'] ?>% by today<br><?= (int)$pace['elapsed'] ?> of <?= (int)$pace['total_days'] ?> working days gone</div><?php endif; ?>
    </div>
    <div class="meter" role="img" aria-label="<?= $tPct ?> percent of team target"><i style="width:<?= min(100, $tPct) ?>%"></i><?php if ($pace['live']): ?><b style="left:<?= min(100, $pace['expected_pct']) ?>%" title="Where the team should be today"></b><?php endif; ?></div>
    <div class="stat4">
      <div><b><?= h(inr_short($pace['remaining'])) ?></b><span>Left to reach target</span></div>
      <div><b><?= $pace['live'] ? h(inr_short($pace['per_day'])) : '–' ?></b><span>Needed per working day</span></div>
      <div><b><?= $pace['live'] ? (int)$pace['left'] : '–' ?></b><span>Working days left</span></div>
      <div><b class="<?= $pace['projected'] >= $target ? 'ok' : 'warn' ?>"><?= h(inr_short($pace['projected'])) ?></b><span>On pace for month end</span></div>
    </div>
  <?php else: ?>
    <div class="bignum"><strong><?= h(inr($t['sales'])) ?></strong><span class="muted">team sales in <?= h($label) ?></span></div>
    <p class="muted tiny">No monthly target is set yet.<?php if ($u['role'] === 'admin'): ?> <a href="<?= h(url('salestargets', ['month' => $month])) ?>">Set the team target</a> to see progress, pace and the amount needed per day.<?php endif; ?></p>
  <?php endif; ?>
</section>

<?php if (!$hasData): ?>
  <div class="card"><h2>No sales data for <?= h($label) ?> yet</h2><p class="muted">When the Inside Sales team uploads their sheet on the Sales desk, everything here fills in by itself.</p></div>
<?php else: ?>

<div class="kpis kpis--4">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Orders</span><span class="kpi__ic kpi__ic--ok"><?= icon('check') ?></span></div>
    <strong class="kpi__v"><?= (int)$t['orders'] ?></strong><span class="kpi__s"><?= $ordTarget > 0 ? pct($t['orders'], $ordTarget) . '% of ' . $ordTarget . ' target' : 'Average order ' . h(inr($aov)) ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Average order value</span><span class="kpi__ic"><?= icon('trend') ?></span></div>
    <strong class="kpi__v"><?= h(inr($aov)) ?></strong><span class="kpi__s">Per order, all sources</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Calls made</span><span class="kpi__ic"><?= icon('clock') ?></span></div>
    <strong class="kpi__v"><?= (int)$t['calls'] ?></strong><span class="kpi__s">About <?= $avgCalls ?> a working day<?= $tg['team']['calls_day'] > 0 ? ' · target ' . (int)$tg['team']['calls_day'] . ' per person' : '' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Connected calls</span><span class="kpi__ic kpi__ic--ok"><?= icon('users') ?></span></div>
    <strong class="kpi__v"><?= $connPct ?>%</strong><span class="kpi__s"><?= (int)$t['connected'] ?> of <?= (int)$t['calls'] ?> calls</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Call minutes</span><span class="kpi__ic kpi__ic--warn"><?= icon('timer') ?></span></div>
    <strong class="kpi__v"><?= h(fmt_hours((float)$t['minutes'])) ?></strong><span class="kpi__s"><?= $t['calls'] && $t['minutes'] ? round($t['minutes'] / max(1, $t['connected']), 1) . ' min per connected call' : 'Typed in by each person' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Orders after a call</span><span class="kpi__ic"><?= icon('trend') ?></span></div>
    <strong class="kpi__v"><?= (int)$t['fromcalls'] ?></strong><span class="kpi__s"><?= $t['orders'] ? pct($t['fromcalls'], $t['orders']) . '% of orders matched to a call' : '–' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Cash on delivery</span><span class="kpi__ic kpi__ic--warn"><?= icon('alert') ?></span></div>
    <strong class="kpi__v"><?= $t['orders'] ? pct($t['cod'], $t['orders']) : 0 ?>%</strong><span class="kpi__s"><?= (int)$t['cod'] ?> COD order(s)</span></div>
  <div class="kpi <?= $t['rto'] ? 'kpi--bad' : '' ?>"><div class="kpi__top"><span class="kpi__l">Returned (RTO)</span><span class="kpi__ic kpi__ic--bad"><?= icon('alert') ?></span></div>
    <strong class="kpi__v"><?= (int)$t['rto'] ?></strong><span class="kpi__s">Marked in the sheet</span></div>
</div>

<div class="dash">
  <div class="dash__col">
    <section class="card" aria-label="Sales against target">
      <div class="card__h"><h2>Sales against target</h2><span class="muted tiny"><?= $target > 0 ? 'Dashed line is the straight path to target' : 'Running total this month' ?></span></div>
      <?= sales_chart($month, $stats['daily'], $target) ?>
    </section>
    <section class="card" aria-label="Team">
      <div class="card__h"><h2>Team</h2><span class="muted tiny"><?= count($stats['per']) ?> people</span></div>
      <div class="tblwrap"><table class="tbl">
        <thead><tr><th>Name</th><th class="r">Sales</th><th class="r">Target</th><th class="r">Orders</th><th class="r">Calls</th><th class="r">Connected</th><th class="r">Minutes</th><th>Last upload</th></tr></thead>
        <tbody>
        <?php foreach ($stats['per'] as $id => $p):
            $pt = $tg['users'][$id] ?? ($target > 0 ? $target / $nMem : 0.0);
            $pp = pct($p['sales'], $pt);
            $cp = $p['calls'] ? (int)round($p['connected'] / $p['calls'] * 100) : 0; ?>
          <tr>
            <td><strong><?= h($p['name']) ?></strong></td>
            <td class="r"><?= h(inr($p['sales'])) ?></td>
            <td class="r"><?= $pt > 0 ? '<span class="chip ' . ($pp >= $pace['expected_pct'] ? 'chip--ok' : 'chip--warn') . '">' . $pp . '%</span>' : '–' ?></td>
            <td class="r"><?= (int)$p['orders'] ?></td><td class="r"><?= (int)$p['calls'] ?></td>
            <td class="r"><?= $p['calls'] ? $cp . '%' : '–' ?></td><td class="r"><?= $p['minutes'] ? h(fmt_hours((float)$p['minutes'])) : '–' ?></td>
            <td class="tiny"><?= $p['last'] ? h(date('j M, g:i a', strtotime($p['last']))) : '<span class="muted">Never</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  </div>
  <div class="dash__col">
    <section class="card" aria-label="Sales by source"><div class="card__h"><h2>Sales by source</h2></div><?= ch_rows($stats['source'], 'sales', 'sales') ?></section>
    <section class="card" aria-label="Coupon codes"><div class="card__h"><h2>Top coupon codes</h2><span class="muted tiny">By sales</span></div><?= ch_rows($stats['code'], 'sales', 'sales') ?></section>
    <section class="card" aria-label="Payment mode"><div class="card__h"><h2>Payment mode</h2><span class="muted tiny">Orders</span></div><?= ch_rows($stats['pay'], 'orders', 'orders', false) ?></section>
  </div>
</div>
<?php endif; ?>
<?php layout_foot();
