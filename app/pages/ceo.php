<?php
defined('DD') or exit;
$pdo = db(); $me = user(); $today = today();
$P = period_pick();
$month = $P['month'];
[$mS, $mE] = s_month_range($month);
$sFrom = $P['from']; $sTo = $P['to'];
$mEnd = min($mE, $today);
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$prevLabel = $P['plabel'];
$cur = ceo_sales($sFrom, $sTo);
$old = ceo_sales($P['pf'], $P['pt']);
$tot = $cur['tot']; $otot = $old['tot'];
$oldBy = []; foreach ($old['rows'] as $r) { $oldBy[$r['key']] = $r; }
// The main sale sheet has no earlier months, so its figures are never compared with last month. Lines from a person's own sheet still are.
$mainNow = (bool)array_filter($cur['rows'], fn($r) => !empty($r['src'])); $mainOld = (bool)array_filter($old['rows'], fn($r) => !empty($r['src']));
$cmp = $mainNow === $mainOld;
$view = ($_GET['view'] ?? 'day') === 'month' ? 'month' : 'day';
$day = valid_date($_GET['d'] ?? '') ? min((string)$_GET['d'], $today) : $today;

$dl = fn(string $k, string $f, string $t) => h(url('exports', ['from' => $f, 'to' => $t, 'dl' => $k]));
$chg = function (float $c, float $o): string {
    if ($o <= 0) { return $c > 0 ? '<span class="delta delta--flat">new</span>' : ''; }
    $pc = round(($c - $o) / $o * 100, 1);
    if (abs($pc) < 0.1) { return '<span class="delta delta--flat">0%</span>'; }
    return '<span class="delta delta--' . ($pc > 0 ? 'up' : 'down') . '">' . ($pc > 0 ? '▲' : '▼') . ' ' . h(rtrim(rtrim(number_format(abs($pc), 1), '0'), '.')) . '%</span>';
};
$share = fn(float $v, float $t) => $t > 0 ? round($v / $t * 100) : 0;

/* last 6 months */
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime($month . '-01 -' . $i . ' month'));
    [$a, $b] = s_month_range($m);
    $x = ($P['isMonth'] && $m === $month) ? $cur : (($P['isMonth'] && $m === $prevMonth) ? $old : ceo_sales($a, $b));
    $trend[] = ['m' => $m, 'on' => $x['tot']['online'], 'off' => $x['tot']['offline'], 'all' => $x['tot']['all'], 'orders' => $x['tot']['orders'], 'spend' => $x['tot']['spend']];
}
$daily = ceo_daily($P['isMonth'] ? $mS : $sFrom, $P['isMonth'] ? $mEnd : min($sTo, $today), $cur['web_entered']);

/* team tasks */
$people = $pdo->query("SELECT id, name, designation FROM users WHERE active = 1 AND role = 'employee' ORDER BY name")->fetchAll();
$tm = tasks_month($month);
$dayData = [];
if ($view === 'day') {
    $st = $pdo->prepare('SELECT * FROM reports WHERE report_date = ?'); $st->execute([$day]);
    $reps = []; foreach ($st as $r) { $reps[(int)$r['user_id']] = $r; }
    $st = $pdo->prepare('SELECT t.*, r.user_id FROM tasks t JOIN reports r ON r.id = t.report_id WHERE r.report_date = ? ORDER BY t.id'); $st->execute([$day]);
    $tk = []; foreach ($st as $t) { $tk[(int)$t['user_id']][] = $t; }
    foreach ($people as $p) { $dayData[] = $p + ['rep' => $reps[(int)$p['id']] ?? null, 'tasks' => $tk[(int)$p['id']] ?? []]; }
}
$filed = count(array_filter($dayData, fn($p) => $p['rep'])); $isWd = is_working_day($day);

$monthOpts = []; for ($i = 0; $i < 12; $i++) { $monthOpts[] = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -' . $i . ' month')); }
if (!in_array($month, $monthOpts, true)) { array_unshift($monthOpts, $month); }
$qBase = ['month' => $month];

layout_head('Sales overview');
?>
<div class="pagehead">
  <div><h1>Sales overview</h1><p class="muted">Every platform, online and offline · <?= h($P['label']) ?><?= $P['isMonth'] ? '' : ' (' . h(fmt_date($sFrom)) . ($sFrom === $sTo ? '' : ' to ' . h(fmt_date($sTo))) . ')' ?></p></div>
  <div class="pagehead__r">
    <?= period_bar('ceo', $P) ?>
    <a class="btn btn--ghost" href="<?= $dl('sales_all', $sFrom, $sTo) ?>"><?= icon('download') ?>Sales CSV</a>
  </div>
</div>

<div class="kpis kpis--4">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">All sales</span><span class="kpi__ic kpi__ic--ok"><?= icon('trend') ?></span></div><strong class="kpi__v"><?= h(inr_short($tot['all'])) ?> <?= $cmp ? $chg($tot['all'], $otot['all']) : '' ?></strong><span class="kpi__s"><?= $cmp ? 'vs ' . h($prevLabel) . ' ' . h(inr_short($otot['all'])) : 'From the main sale sheet' ?> · <?= number_format($tot['orders']) ?> orders</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Online</span><span class="kpi__ic"><?= icon('bag') ?></span></div><strong class="kpi__v"><?= h(inr_short($tot['online'])) ?> <?= $cmp ? $chg($tot['online'], $otot['online']) : '' ?></strong><span class="kpi__s"><?= $share($tot['online'], $tot['all']) ?>% of all sales</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Offline</span><span class="kpi__ic kpi__ic--warn"><?= icon('users') ?></span></div><strong class="kpi__v"><?= h(inr_short($tot['offline'])) ?> <?= $cmp ? $chg($tot['offline'], $otot['offline']) : '' ?></strong><span class="kpi__s"><?= $tot['offline'] > 0 ? $share($tot['offline'], $tot['all']) . '% of all sales' : 'No offline sales entered yet' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l"><?= !empty($tot['sheet_spend']) ? 'Online ad spend' : 'Marketplace ad spend' ?></span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short($tot['spend'])) ?></strong><span class="kpi__s">ROAS <?= h(ecom_roas(!empty($tot['sheet_spend']) ? $tot['online'] : $tot['mp_sales'], $tot['spend'])) ?> on <?= !empty($tot['sheet_spend']) ? 'all online sales' : 'marketplaces' ?></span></div>
</div>

<?php if ($tot['spend'] > 0):
  $dim = (int)date('t', strtotime($month . '-01'));
  if ($P['isMonth']) { $elapsed = $month < substr($today, 0, 7) ? $dim : ($month > substr($today, 0, 7) ? 0 : (int)date('j', strtotime($today))); $elapsed = max(1, min($dim, $elapsed)); }
  else { $elapsed = $P['days']; }
  $roas = $tot['online'] > 0 ? $tot['online'] / $tot['spend'] : 0.0;
  $oroas = $cmp && ($otot['spend'] ?? 0) > 0 ? $otot['online'] / $otot['spend'] : 0.0;
  $spPct = $tot['online'] > 0 ? $tot['spend'] / $tot['online'] * 100 : 0.0;
  $oPct = $cmp && ($otot['online'] ?? 0) > 0 && ($otot['spend'] ?? 0) > 0 ? $otot['spend'] / $otot['online'] * 100 : 0.0;
  $net = $tot['online'] - $tot['spend'];
  $perDay = $tot['online'] / $elapsed; $spDay = $tot['spend'] / $elapsed;
  $projS = $perDay * $dim; $projSp = $spDay * $dim; $isM = $P['isMonth'];
  $lines = array_values(array_filter($cur['rows'], fn($r) => ($r['kind'] === 'online') && ($r['in_total'] ?? true) !== false && $r['spend'] > 0));
  usort($lines, fn($a, $b) => ($b['sales'] / max(1, $b['spend'])) <=> ($a['sales'] / max(1, $a['spend'])));
  $best = $lines[0] ?? null; $worst = count($lines) > 1 ? $lines[count($lines) - 1] : null;
  $rd = function (float $c, float $o, bool $upGood = true): string {
      if ($o <= 0) { return ''; }
      $d = $c - $o; if (abs($d) < 0.005) { return ''; }
      $good = $upGood ? $d > 0 : $d < 0;
      return '<span class="delta delta--' . ($good ? 'up' : 'down') . '">' . ($d > 0 ? '▲ ' : '▼ ') . h(number_format(abs($d), 2)) . '</span>';
  };
?>
<div class="kpis kpis--4">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">ROAS (online)</span><span class="kpi__ic kpi__ic--ok"><?= icon('trend') ?></span></div><strong class="kpi__v"><?= h(number_format($roas, 2)) ?>x <?= $rd($roas, $oroas) ?></strong><span class="kpi__s">Every ₹1 of ads brought ₹<?= h(number_format($roas, 2)) ?><?= $oroas > 0 ? ' · ' . h($prevLabel) . ' ' . h(number_format($oroas, 2)) . 'x' : '' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Spend per ₹100 sold</span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v">₹<?= h(number_format($spPct, 1)) ?> <?= $rd($spPct, $oPct, false) ?></strong><span class="kpi__s">Ad cost as % of online sales<?= $oPct > 0 ? ' · ' . h($prevLabel) . ' ' . h(number_format($oPct, 1)) . '%' : '' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Sales after ad spend</span><span class="kpi__ic"><?= icon('bag') ?></span></div><strong class="kpi__v"><?= h(inr_short($net)) ?></strong><span class="kpi__s">Online sales − ad spend, before product cost</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l"><?= $isM ? 'Month-end at this pace' : 'Average a day' ?></span><span class="kpi__ic"><?= icon('trend') ?></span></div><strong class="kpi__v"><?= h(inr_short($isM ? $projS : $perDay)) ?></strong><span class="kpi__s"><?= $isM ? h(inr_short($perDay)) . ' sales and ' . h(inr_short($spDay)) . ' spend a day · spend ' . h(inr_short($projSp)) : h(inr_short($spDay)) . ' spend a day over ' . (int)$elapsed . ' day' . ($elapsed > 1 ? 's' : '') ?></span></div>
</div>
<?php
$dAsc = $daily; ksort($dAsc); $dl2 = array_map(fn($d) => date('j M', strtotime($d)), array_keys($dAsc));
$byP = array_values(array_filter($cur['rows'], fn($r) => ($r['in_total'] ?? true) !== false && $r['sales'] > 0)); usort($byP, fn($a, $b) => $b['sales'] <=> $a['sales']);
$top = array_slice($byP, 0, 7); $restS = array_sum(array_column(array_slice($byP, 7), 'sales')); $parts = array_map(fn($r) => [$r['label'], (float)$r['sales']], $top); if ($restS > 0) { $parts[] = ['Others', $restS]; }
$inrf = fn($v) => inr((float)$v);
?>
<div class="chgrid chgrid--2">
  <section class="card"><div class="card__h"><h2>Sales by day</h2><span class="muted tiny"><?= h($P['label']) ?></span></div>
    <?= ch_combo($dl2, [['name' => 'Online', 'values' => array_map(fn($v) => round($v['online']), array_values($dAsc)), 'color' => CH_PAL[0]]], [['name' => 'Offline', 'values' => array_map(fn($v) => round($v['offline']), array_values($dAsc)), 'color' => CH_PAL[1]]], ['fmt' => $inrf, 'label' => 'Sales by day']) ?></section>
  <section class="card"><div class="card__h"><h2>Last six months</h2><span class="muted tiny">online and offline</span></div>
    <?= ch_combo(array_map(fn($t) => date('M', strtotime($t['m'] . '-01')), $trend), [['name' => 'Online', 'values' => array_map(fn($t) => round($t['on']), $trend), 'color' => CH_PAL[0]]], [['name' => 'Offline', 'values' => array_map(fn($t) => round($t['off']), $trend), 'color' => CH_PAL[1]], ['name' => 'Ad spend', 'values' => array_map(fn($t) => round($t['spend']), $trend), 'color' => CH_PAL[5], 'dash' => true]], ['fmt' => $inrf, 'every' => 1, 'label' => 'Last six months']) ?></section>
</div>
<div class="chgrid">
  <section class="card"><div class="card__h"><h2>Sales by platform</h2></div><?= ch_donut($parts, inr_short((float)$tot['all']), 'all sales', $inrf) ?></section>
  <section class="card"><div class="card__h"><h2>Online or offline</h2></div><?= ch_donut([['Online', (float)$tot['online'], CH_PAL[0]], ['Offline', (float)$tot['offline'], CH_PAL[1]]], inr_short((float)$tot['all']), 'all sales', $inrf) ?></section>
  <section class="card"><div class="card__h"><h2>Ad return</h2><span class="muted tiny">online sales ÷ ad spend</span></div><div class="gauges" style="grid-template-columns:1fr"><?= ch_gauge($roas, 6, number_format($roas, 2) . 'x', 'ROAS (aim 3x+)', ['bad' => 1.5, 'warn' => 3]) ?></div></section>
</div>
<section class="card">
  <div class="card__h"><h2>Spend and return by line</h2><span class="muted tiny">Spend comes from the main sheet's monthly figure<?= $P['isMonth'] ? ($elapsed < $dim ? ' (month to date)' : '') : ', shared out by day' ?></span></div>
  <?php if ($best): ?><p class="muted" style="margin:0 0 12px">Best return: <strong><?= h($best['label']) ?></strong> at <?= h(ecom_roas((float)$best['sales'], (float)$best['spend'])) ?><?php if ($worst): ?> · weakest: <strong><?= h($worst['label']) ?></strong> at <?= h(ecom_roas((float)$worst['sales'], (float)$worst['spend'])) ?><?php endif; ?>.</p><?php endif; ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Line</th><th class="r">Sales</th><th class="r">Ad spend</th><th class="r">Share of spend</th><th class="r">ROAS</th><th class="r hm">Spend per ₹100</th><th class="r hm">Sales after spend</th><th class="r hm">Sales a day</th></tr></thead><tbody>
  <?php foreach ($lines as $r): $ro = $r['sales'] / $r['spend']; ?>
    <tr><td><strong><?= h($r['label']) ?></strong></td><td class="r"><?= h(inr($r['sales'])) ?></td><td class="r"><?= h(inr($r['spend'])) ?></td><td class="r"><?= $share($r['spend'], $tot['spend']) ?>%</td><td class="r"><strong class="<?= $ro >= 3 ? 'good' : ($ro < 1.5 ? 'bad' : '') ?>"><?= h(number_format($ro, 2)) ?>x</strong></td><td class="r hm">₹<?= h(number_format($r['spend'] / max(1, $r['sales']) * 100, 1)) ?></td><td class="r hm"><?= h(inr($r['sales'] - $r['spend'])) ?></td><td class="r hm"><?= h(inr_short($r['sales'] / $elapsed)) ?></td></tr>
  <?php endforeach; ?>
    <tr style="background:var(--soft)"><td><strong>All online</strong></td><td class="r"><strong><?= h(inr($tot['online'])) ?></strong></td><td class="r"><strong><?= h(inr($tot['spend'])) ?></strong></td><td class="r">100%</td><td class="r"><strong><?= h(number_format($roas, 2)) ?>x</strong></td><td class="r hm">₹<?= h(number_format($spPct, 1)) ?></td><td class="r hm"><strong><?= h(inr($net)) ?></strong></td><td class="r hm"><?= h(inr_short($perDay)) ?></td></tr>
  </tbody></table></div>
  <p class="muted tiny" style="margin-top:10px">ROAS = sales ÷ ad spend. Green is 3x or more, red is under 1.5x. Lines with no spend in the sheet are left out. Ritik's spend covers Blinkit, Myntra, Hyugalife and Tata 1mg together.</p>
</section>
<?php endif; ?>

<?php $td = track_load($month); if ($td['ents']): $tt = $td['tot']; ?>
<section class="card">
  <div class="card__h"><h2>Targets and pace</h2><a class="btn btn--ghost btn--sm" href="<?= h(url('track', ['month' => $month])) ?>">Open full view</a></div>
  <p class="muted" style="margin:0 0 12px"><strong><?= h(inr_short($tt['achieved'])) ?></strong> achieved of <?= h(inr_short($tt['monthly'])) ?> (<?= (int)round($tt['ach_pct']) ?>%) · should be at <strong><?= h(inr_short($tt['expected'])) ?></strong> <?= h(track_asof_text($td)) ?> · <span class="<?= $tt['gap'] >= 0 ? 'good' : 'bad' ?>"><?= $tt['gap'] >= 0 ? 'ahead by ' : 'behind by ' ?><?= h(inr_short(abs($tt['gap']))) ?></span> · needs <?= h(inr_short($tt['need_day'])) ?> a day</p>
  <?php track_team_table($td, 'person', true); track_team_table($td, 'platform', true); ?>
</section>
<?php endif; ?>

<?php $ppl = track_people_between($sFrom, min($sTo, $today)); if ($ppl):
  $ppl0 = []; foreach (track_people_between($P['pf'], $P['pt']) as $q) { $ppl0[track_key($q['name'])] = $q; }
  $pAll = array_sum(array_column($ppl, 'sales')); $pTg = array_sum(array_column($ppl, 'target'));
?>
<section class="card">
  <div class="card__h"><h2>Sales by person</h2><span class="muted tiny"><?= h($P['label']) ?> · from the main sale sheet</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Person</th><th class="r">Sales</th><th class="r">Target for these days</th><th class="r">Achieved</th><th class="r">Share of people's sales</th><th class="r">vs <?= h($prevLabel) ?></th></tr></thead><tbody>
  <?php foreach ($ppl as $q): $k = track_key($q['name']); $o0 = $ppl0[$k] ?? null; $pc = $q['target'] > 0 ? $q['sales'] / $q['target'] * 100 : 0; ?>
    <tr>
      <td><strong><?= h($q['name']) ?></strong><?= $q['filled'] === 0 ? ' <span class="chip chip--warn" title="No figure typed in the sheet for these days">not filled</span>' : '' ?></td>
      <td class="r"><strong><?= h(inr($q['sales'])) ?></strong></td>
      <td class="r"><?= $q['target'] > 0 ? h(inr($q['target'])) : '–' ?></td>
      <td class="r"><?php if ($q['target'] > 0 && $q['filled'] > 0): ?><span class="<?= $pc >= 100 ? 'good' : ($pc < 50 ? 'bad' : '') ?>"><?= (int)round($pc) ?>%</span><?php else: ?>–<?php endif; ?></td>
      <td class="r"><?= $pAll > 0 && $q['filled'] > 0 ? $share($q['sales'], $pAll) . '%' : '–' ?></td>
      <td class="r"><?= ($o0 && $o0['filled'] > 0 && $q['filled'] > 0) ? ($chg($q['sales'], (float)$o0['sales']) ?: '–') : '–' ?></td>
    </tr>
  <?php endforeach; ?>
    <tr style="background:var(--soft)"><td><strong>All people</strong></td><td class="r"><strong><?= h(inr($pAll)) ?></strong></td><td class="r"><?= $pTg > 0 ? h(inr($pTg)) : '–' ?></td><td class="r"><?= $pTg > 0 ? (int)round($pAll / $pTg * 100) . '%' : '–' ?></td><td class="r">100%</td><td class="r">–</td></tr>
  </tbody></table></div>
  <p class="muted tiny" style="margin-top:10px">Amazon, Flipkart and Shopify are in the platform table below. Ritik's line already includes Blinkit, Myntra, Hyugalife and Tata 1mg.</p>
</section>
<?php endif; ?>

<?php foreach (['online' => 'Online sales, by platform', 'offline' => 'Offline sales'] as $kind => $title): $rs = array_values(array_filter($cur['rows'], fn($r) => $r['kind'] === $kind)); $sub = $tot[$kind]; ?>
<section class="card">
  <div class="card__h"><h2><?= h($title) ?></h2><strong><?= h(inr($sub)) ?></strong></div>
  <?php if ($kind === 'offline' && $sub <= 0): ?>
    <p class="muted">No offline sales have been entered for this month. <?php if (can_enter_channels($me)): ?><a href="<?= h(url('channels')) ?>">Add offline sales</a><?php else: ?>Ask the admin or the sales team to add them under "Other sales".<?php endif; ?></p>
  <?php endif; ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Platform</th><th class="r">Sales</th><th class="r">Share</th><th class="r">Orders</th><?php if ($kind === 'online'): ?><th class="r hm">Ad spend</th><th class="r hm">ROAS</th><?php endif; ?><th class="r">vs <?= h($prevLabel) ?></th><th class="r hm">Last data</th></tr></thead><tbody>
  <?php foreach ($rs as $r): $o = $oldBy[$r['key']]['sales'] ?? 0; $isSh = $r['key'] === 'shopify'; $inc = ($r['in_total'] ?? true) === false; ?>
    <tr<?= $isSh ? ' style="background:var(--soft)"' : ($inc ? ' style="opacity:.72"' : '') ?>>
      <td><?= $r['platform'] ? '<span class="pcell">' . ecom_logo($r['platform'], 26) . h($r['label']) . '</span>' : '<strong>' . h($r['label']) . '</strong>' ?><?= !empty($r['src']) && !$inc ? ' <span class="chip" title="Total from the main sale sheet">Main sheet</span>' : '' ?><?= $inc ? ' <span class="chip" title="Shown for detail. Already inside ' . h((string)$r['part_of']) . ', so it is not added again.">Inside ' . h((string)$r['part_of']) . '</span>' : '' ?><?= isset($r['detail']) && $r['detail'] > 0 && abs($r['detail'] - $r['sales']) > 1 ? ' <span class="muted tiny" title="What the platform sheet shows for the same month">platform sheet ' . h(inr_short((float)$r['detail'])) . '</span>' : '' ?></td>
      <td class="r"><strong><?= h(inr($r['sales'])) ?></strong></td>
      <td class="r"><?= $inc ? '<span class="muted">–</span>' : $share($r['sales'], $sub) . '%' ?></td>
      <td class="r"><?= number_format($r['orders']) ?></td>
      <?php if ($kind === 'online'): ?><td class="r hm"><?= $r['spend'] > 0 ? h(inr($r['spend'])) : '–' ?></td><td class="r hm"><?= $r['spend'] > 0 ? h(ecom_roas((float)(isset($r['detail_spend']) ? $r['sales'] : ($r['detail'] ?? $r['sales'])), $r['spend'])) : '–' ?></td><?php endif; ?>
      <td class="r"><?= (empty($r['src']) === empty($oldBy[$r['key']]['src'])) ? ($chg($r['sales'], (float)$o) ?: '–') : '–' ?></td>
      <td class="r hm <?= $r['last'] ? '' : 'is-low' ?>"><?= $r['last'] ? h(fmt_date($r['last'])) : 'No data' ?></td>
    </tr>
    <?php if (!empty($r['subs'])): foreach ($r['subs'] as $sr): ?>
    <tr>
      <td style="padding-left:34px"><span class="muted">↳</span> <?= h($sr['label']) ?></td>
      <td class="r"><?= $sr['sales'] > 0 ? h(inr($sr['sales'])) : '<span class="muted">–</span>' ?></td>
      <td class="r muted"><?= $r['sales'] > 0 && $sr['sales'] > 0 ? $share($sr['sales'], $r['sales']) . '% of ' . h($r['label']) : '–' ?></td>
      <td class="r"><?= $sr['orders'] !== null ? number_format($sr['orders']) : '–' ?></td>
      <?php if ($kind === 'online'): ?><td class="r hm">–</td><td class="r hm">–</td><?php endif; ?>
      <td class="r">–</td><td class="r hm">–</td>
    </tr>
    <?php endforeach; endif; ?>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($kind === 'online' && !$cur['web_entered']): ?><p class="muted tiny" style="margin-top:10px">Shopify is showing Inside Sales orders only. Add the daily Shopify total, with Meta and influencer-code sales, under "Other sales" to see the full split.</p><?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>Last 6 months</h2>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Month</th><th class="r">Online</th><th class="r">Offline</th><th class="r">All sales</th><th class="r hm">Orders</th><th class="r hm">Ad spend</th></tr></thead><tbody>
  <?php foreach (array_reverse($trend) as $t): ?>
    <tr><td><a href="<?= h(url('ceo', ['month' => $t['m']])) ?>"><?= h(date('F Y', strtotime($t['m'] . '-01'))) ?></a></td><td class="r"><?= h(inr_short($t['on'])) ?></td><td class="r"><?= $t['off'] > 0 ? h(inr_short($t['off'])) : '–' ?></td><td class="r"><strong><?= h(inr_short($t['all'])) ?></strong></td><td class="r hm"><?= number_format($t['orders']) ?></td><td class="r hm"><?= h(inr_short($t['spend'])) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($daily): ?>
  <details style="margin-top:12px"><summary class="btn btn--ghost btn--sm" style="display:inline-flex">Day by day this month</summary>
    <div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th class="r">Online</th><th class="r">Offline</th><th class="r">Total</th></tr></thead><tbody>
    <?php foreach ($daily as $d => $v): ?><tr><td><?= h(fmt_date($d)) ?></td><td class="r"><?= h(inr($v['online'])) ?></td><td class="r"><?= $v['offline'] > 0 ? h(inr($v['offline'])) : '–' ?></td><td class="r"><strong><?= h(inr($v['online'] + $v['offline'])) ?></strong></td></tr><?php endforeach; ?></tbody></table></div>
  </details>
  <?php endif; ?>
</section>

<h2 class="sechead" id="tasks">What everyone is doing</h2>
<div class="pagehead" style="margin-bottom:12px">
  <div class="seg" role="group"><a class="pill" href="<?= h(url('ceo', $qBase + ['view' => 'day', 'd' => $day]) . '#tasks') ?>" style="<?= $view === 'day' ? 'background:var(--prisoft);color:var(--pri);font-weight:600' : '' ?>">Daily</a>
    <a class="pill" href="<?= h(url('ceo', $qBase + ['view' => 'month']) . '#tasks') ?>" style="<?= $view === 'month' ? 'background:var(--prisoft);color:var(--pri);font-weight:600' : '' ?>">Monthly</a></div>
  <div class="pagehead__r">
  <?php if ($view === 'day'): ?>
    <a class="btn btn--ghost btn--sm" href="<?= h(url('ceo', $qBase + ['view' => 'day', 'd' => add_days($day, -1)]) . '#tasks') ?>">‹ Previous</a>
    <form method="get" class="datenav"><input type="hidden" name="p" value="ceo"><input type="hidden" name="view" value="day"><input type="hidden" name="month" value="<?= h($month) ?>"><input type="date" name="d" value="<?= h($day) ?>" max="<?= h($today) ?>" data-autosubmit></form>
    <?php if ($day < $today): ?><a class="btn btn--ghost btn--sm" href="<?= h(url('ceo', $qBase + ['view' => 'day', 'd' => add_days($day, 1)]) . '#tasks') ?>">Next ›</a><?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= $dl('reports', $day, $day) ?>"><?= icon('download') ?>This day CSV</a>
  <?php else: ?>
    <a class="btn btn--ghost btn--sm" href="<?= $dl('tasks_monthly', $mS, $mE) ?>"><?= icon('download') ?>Monthly summary CSV</a>
    <a class="btn btn--ghost btn--sm" href="<?= $dl('reports', $mS, $mE) ?>"><?= icon('download') ?>All tasks this month CSV</a>
  <?php endif; ?>
  </div>
</div>

<?php if ($view === 'day'): ?>
  <p class="muted"><?= h(fmt_date($day, true)) ?> · <?= $filed ?> of <?= count($people) ?> have filed<?= $isWd ? '' : ' · not a working day' ?></p>
  <div class="list" style="margin-bottom:18px">
  <?php foreach ($dayData as $p): $rep = $p['rep']; $hrs = array_sum(array_map(fn($t) => (float)$t['hours'], $p['tasks'])); ?>
    <details class="item" <?= $p['tasks'] ? 'open' : '' ?>>
      <summary><span class="person__n"><strong><?= h($p['name']) ?></strong><small><?= h($p['designation']) ?></small></span>
        <?php if (!$rep): ?><span class="chip <?= $isWd ? 'chip--bad' : '' ?>"><?= $isWd ? 'Not filed' : 'Off day' ?></span>
        <?php elseif ($rep['type'] === 'leave'): ?><span class="chip chip--warn">On leave</span>
        <?php else: ?><span class="chip chip--ok"><?= count($p['tasks']) ?> task<?= count($p['tasks']) === 1 ? '' : 's' ?> · <?= h(fmt_hours($hrs)) ?> h</span><?php endif; ?></summary>
      <div class="item__body">
        <?php foreach ($p['tasks'] as $t): ?>
          <div class="trow"><span class="trow__t"><strong><?= h($t['title']) ?></strong><?= $t['remarks'] !== '' ? '<br><span class="muted tiny">' . h($t['remarks']) . '</span>' : '' ?></span>
            <span class="trow__m"><span class="muted tiny"><?= h($t['category']) ?></span><span><?= h(fmt_hours((float)$t['hours'])) ?> h</span><span class="chip chip--<?= h($t['status']) ?>"><?= h(status_label($t['status'])) ?></span></span></div>
        <?php endforeach; ?>
        <?php if ($rep && $rep['note'] !== ''): ?><p class="muted tiny" style="margin:10px 0 0">Day note: <?= h($rep['note']) ?></p><?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
  <?php if (!$people): ?><p class="muted">No employees added yet.</p><?php endif; ?>
  </div>
<?php else: ?>
  <section class="card">
    <h2>Monthly task summary · <?= h(date('F Y', strtotime($month . '-01'))) ?></h2>
    <div class="tblwrap"><table class="tbl"><thead><tr><th>Employee</th><th class="r">Days reported</th><th class="r hm">Leave</th><th class="r">Tasks</th><th class="r hm">Done</th><th class="r hm">Blocked</th><th class="r">Hours</th></tr></thead><tbody>
    <?php foreach ($tm['per'] as $p): ?>
      <tr><td><strong><?= h($p['name']) ?></strong><br><span class="muted tiny"><?= h($p['designation']) ?></span></td>
        <td class="r <?= $tm['wd'] && $p['work'] + $p['leave'] < $tm['wd'] * 0.8 ? 'is-low' : '' ?>"><?= $p['work'] ?> / <?= $tm['wd'] ?></td><td class="r hm"><?= $p['leave'] ?></td><td class="r"><?= $p['tasks'] ?></td><td class="r hm"><?= $p['done'] ?></td><td class="r hm <?= $p['blocked'] ? 'is-low' : '' ?>"><?= $p['blocked'] ?></td><td class="r"><?= h(fmt_hours($p['hours'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if ($tm['cat']): $mx = max($tm['cat']); ?>
    <h3 class="sub">Where the time went</h3>
    <div class="hbars"><?php foreach (array_slice($tm['cat'], 0, 8, true) as $c => $h): ?><div class="hbar"><span class="hbar__l"><?= h($c) ?></span><span class="hbar__t"><i style="width:<?= round($h / $mx * 100) ?>%"></i></span><span class="hbar__v"><?= h(fmt_hours($h)) ?> h</span></div><?php endforeach; ?></div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="card">
  <h2>Downloads</h2>
  <div class="dlrow"><div><strong>Sales, all platforms</strong><br><span class="muted tiny">Every day and every platform, online and offline, for <?= h(date('F Y', strtotime($month . '-01'))) ?>.</span></div><a class="btn btn--ghost btn--sm" href="<?= $dl('sales_all', $mS, $mE) ?>"><?= icon('download') ?>CSV</a></div>
  <div class="dlrow"><div><strong>Daily tasks of everyone</strong><br><span class="muted tiny">Each task with hours, category and status, for the month.</span></div><a class="btn btn--ghost btn--sm" href="<?= $dl('reports', $mS, $mE) ?>"><?= icon('download') ?>CSV</a></div>
  <div class="dlrow"><div><strong>Monthly task summary</strong><br><span class="muted tiny">One row per person per month: days reported, tasks, done, blocked, hours.</span></div><a class="btn btn--ghost btn--sm" href="<?= $dl('tasks_monthly', $mS, $mE) ?>"><?= icon('download') ?>CSV</a></div>
  <div class="dlrow"><div><strong>Everything else</strong><br><span class="muted tiny">Attendance, Inside Sales orders and calls, e-commerce, with custom dates.</span></div><a class="btn btn--sm" href="<?= h(url('exports')) ?>"><?= icon('download') ?>All downloads</a></div>
</section>
<?php layout_foot();
