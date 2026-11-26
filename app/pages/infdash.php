<?php
defined('DD') or exit;
/* Influencers: Sourabh (brand manager). Influencer-code sales are already inside Shopify, so nothing here is added to the Sales overview total. */
$pdo = db(); $today = today();
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); })();
$one = fn(string $sql, array $a = []) => ($q($sql, $a)[0] ?? []);
$has = inf_has_data();
$months = array_column($q('SELECT DISTINCT month m FROM inf_order ORDER BY m DESC'), 'm');
$curM = substr($today, 0, 7);
$m = (string)($_GET['month'] ?? ''); if ($m !== 'all' && !in_array($m, $months, true)) { $m = in_array($curM, $months, true) ? $curM : ($months[0] ?? $curM); }
$all = $m === 'all';
$mLabel = $all ? 'All months' : date('F Y', strtotime($m . '-01'));
$mw = $all ? '1=1' : "month = '$m'";
$tabs = ['overview' => 'Overview', 'codes' => 'Influencers', 'daily' => 'Day by day', 'products' => 'Products and cost', 'payouts' => 'Payouts', 'barter' => 'Barter and stacks', 'orders' => 'All orders'];
$tabNow = isset($tabs[$_GET['t'] ?? '']) ? $_GET['t'] : 'overview';
$keep = ['month' => $m];
$pct = fn(float $v, float $t) => $t > 0 ? round($v / $t * 100) : 0;
$kpi = fn(string $l, string $v, string $s = '', string $ic = 'trend', string $cls = '') => '<div class="kpi"><div class="kpi__top"><span class="kpi__l">' . h($l) . '</span><span class="kpi__ic ' . $cls . '">' . icon($ic) . '</span></div><strong class="kpi__v">' . $v . '</strong><span class="kpi__s">' . $s . '</span></div>';
$disp = []; foreach ($q('SELECT code, disp, name, insta, stack, comm FROM inf_code') as $r) { $disp[$r['code']] = $r; }
$dn = fn(string $c) => $disp[$c]['disp'] ?? $c;
$daysAgo = fn(string $d) => (int)floor((strtotime($today) - strtotime($d)) / 86400);
$meta = []; foreach ($q('SELECT k, v FROM inf_meta') as $r) { $meta[$r['k']] = $r['v']; }

layout_head('Influencers');
?>
<div class="pagehead">
  <div><h1>Influencers</h1><p class="muted">Sourabh, brand manager: influencer-code sales, cost, payouts and barter · <?= h($mLabel) ?><?= isset($meta['synced_at']) ? ' · sheet read ' . h(fmt_date(substr($meta['synced_at'], 0, 10))) . ' ' . h(substr($meta['synced_at'], 11, 5)) : '' ?></p></div>
  <div class="pagehead__r">
    <form method="get" class="inline"><input type="hidden" name="p" value="infdash"><input type="hidden" name="t" value="<?= h($tabNow) ?>">
      <select name="month" onchange="this.form.submit()" aria-label="Month"><option value="all" <?= $all ? 'selected' : '' ?>>All months</option><?php foreach ($months as $mo): ?><option value="<?= h($mo) ?>" <?= $mo === $m ? 'selected' : '' ?>><?= h(date('F Y', strtotime($mo . '-01'))) ?></option><?php endforeach; ?></select></form></div>
</div>
<?php if (!$has): ?>
<section class="card"><p class="muted">No influencer data yet. The workbook is linked under Admin, Google Sheets ("Influencers (Sourabh)") and is read every 5 minutes; press Sync now there to read it immediately.</p></section>
<?php layout_foot(); return; endif; ?>
<div class="pagehead__r" style="margin:-4px 0 14px;flex-wrap:wrap"><?php foreach ($tabs as $tk => $tl): ?><a class="btn btn--sm <?= $tk === $tabNow ? '' : 'btn--ghost' ?>" href="<?= h(url('infdash', ['t' => $tk] + $keep)) ?>"><?= h($tl) ?></a> <?php endforeach; ?></div>

<?php
$S = $one("SELECT COALESCE(SUM(amount),0) s, COUNT(*) n, COUNT(DISTINCT code) c FROM inf_order WHERE kind='sale' AND $mw");
$L = $one("SELECT COUNT(*) n, COALESCE(SUM(value),0) v FROM inf_order WHERE kind='lost' AND $mw");
$P = $one("SELECT COUNT(*) n, COALESCE(SUM(value),0) v FROM inf_order WHERE kind='pending' AND $mw");
$C = $one("SELECT COALESCE(SUM(amount),0) a, COALESCE(SUM(cogs+ship),0) c, COUNT(*) n FROM inf_order WHERE kind='sale' AND cogs > 0 AND $mw");
$sales = (float)$S['s']; $nOrd = (int)$S['n']; $aov = $nOrd ? $sales / $nOrd : 0;
$allOrd = $nOrd + (int)$L['n'] + (int)$P['n'];
$first = []; foreach ($q("SELECT code, MIN(month) f, MIN(day) fd, MAX(day) ld, SUM(amount) s, COUNT(*) n FROM inf_order WHERE kind='sale' GROUP BY code") as $r) { $first[$r['code']] = $r; }
$newC = 0; if (!$all) { foreach ($q("SELECT DISTINCT code FROM inf_order WHERE kind='sale' AND month = ?", [$m]) as $r) { if (($first[$r['code']]['f'] ?? '') === $m) { $newC++; } } }
$perf = []; foreach ($q('SELECT * FROM inf_perf') as $r) { $perf[$r['month']] = $r; }
$spend = $all ? array_sum(array_column($perf, 'spend')) : (float)($perf[$m]['spend'] ?? 0);
$spendSales = $all ? array_sum(array_map(fn($p) => $p['spend'] > 0 ? $p['sales'] : 0, $perf)) : (float)($perf[$m]['sales'] ?? 0);
$prevM = !$all ? date('Y-m', strtotime($m . '-01 -1 month')) : ''; $prevS = $prevM !== '' ? (float)$one("SELECT COALESCE(SUM(amount),0) s FROM inf_order WHERE kind='sale' AND month = ?", [$prevM])['s'] : 0;
$estComm = round($sales * 0.95 * 0.10);
?>

<?php if ($tabNow === 'overview'): ?>
<div class="kpis kpis--4">
  <?= $kpi('Influencer sales · ' . $mLabel, h(inr_short($sales)), $nOrd . ' orders' . ($prevS > 0 ? ' · ' . ($sales >= $prevS ? '+' : '') . $pct($sales - $prevS, $prevS) . '% vs ' . date('M', strtotime($prevM . '-01')) : ''), 'trend', 'kpi__ic--ok') ?>
  <?= $kpi('Average order', h(inr($aov)), 'amount received per order', 'bag') ?>
  <?= $kpi('Active codes', (string)(int)$S['c'], !$all ? $newC . ' used for the first time' : 'codes with at least one order', 'users') ?>
  <?= $kpi('Lost orders', (string)(int)$L['n'], $pct((float)$L['n'], (float)$allOrd) . '% of ' . $allOrd . ' (RTO, cancelled, return) · ' . h(inr_short((float)$L['v'])), 'target', 'kpi__ic--warn') ?>
  <?= $kpi('Pending payment', (string)(int)$P['n'], 'partially paid orders · ' . h(inr_short((float)$P['v'])), 'target') ?>
  <?php if ((int)$C['n'] > 0): $real = (float)$C['a'] - (float)$C['c']; ?>
  <?= $kpi('Product cost and shipping', h(inr_short((float)$C['c'])), 'on ' . (int)$C['n'] . ' orders with costs filled in', 'bag', 'kpi__ic--warn') ?>
  <?= $kpi('Realisation', h(inr_short($real)), $pct($real, (float)$C['a']) . '% of ' . h(inr_short((float)$C['a'])) . ' received', 'check', 'kpi__ic--ok') ?>
  <?php else: ?>
  <?= $kpi('Product cost and shipping', '–', 'not filled in for this month', 'bag') ?>
  <?= $kpi('Realisation', '–', 'cost columns start in September 2026', 'check') ?>
  <?php endif; ?>
  <?= $kpi('Spend and ROAS', $spend > 0 ? h(inr_short($spend)) . ' · ' . round($spendSales / $spend, 1) . 'x' : '–', $spend > 0 ? 'sales ÷ spend, from the performance tab' : 'no spend entered in the performance tab', 'trend') ?>
</div>

<section class="card"><div class="card__h"><h2>Top influencers · <?= h($mLabel) ?></h2><a class="muted tiny" href="<?= h(url('infdash', ['t' => 'codes'] + $keep)) ?>">See all</a></div>
  <?php $top = $q("SELECT code, SUM(amount) s, COUNT(*) n FROM inf_order WHERE kind='sale' AND $mw GROUP BY code ORDER BY s DESC LIMIT 12"); $mx = max(1.0, (float)($top[0]['s'] ?? 1)); ?>
  <div class="hbars"><?php foreach ($top as $t): ?><div class="hbar hbar--w"><span class="hbar__l"><?= h($dn($t['code'])) ?></span><span class="hbar__t"><i style="width:<?= round($t['s'] / $mx * 100) ?>%"></i></span><span class="hbar__v"><?= h(inr_short((float)$t['s'])) ?></span></div><?php endforeach; ?></div>
  <p class="muted tiny" style="margin-top:8px"><?= $pct((float)array_sum(array_column(array_slice($top, 0, 5), 's')), $sales) ?>% of the sales come from the top 5 codes.</p></section>

<section class="card"><div class="card__h"><h2>Month by month</h2><span class="muted tiny">spend and ROAS come from the "performance" tab; costs from the order tabs</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Month</th><th class="r">Sales</th><th class="r">Orders</th><th class="r">Avg order</th><th class="r">Codes</th><th class="r">Lost</th><th class="r hm">Spend</th><th class="r hm">ROAS</th><th class="r hm">Realisation</th></tr></thead><tbody>
  <?php foreach ($months as $mo):
    $a = $one("SELECT COALESCE(SUM(amount),0) s, COUNT(*) n, COUNT(DISTINCT code) c FROM inf_order WHERE kind='sale' AND month = ?", [$mo]);
    $lo = (int)$one("SELECT COUNT(*) n FROM inf_order WHERE kind='lost' AND month = ?", [$mo])['n'];
    $cc = $one("SELECT COALESCE(SUM(amount),0) a, COALESCE(SUM(cogs+ship),0) c, COUNT(*) n FROM inf_order WHERE kind='sale' AND cogs > 0 AND month = ?", [$mo]);
    $sp = (float)($perf[$mo]['spend'] ?? 0); ?>
    <tr><td><strong><a href="<?= h(url('infdash', ['t' => 'overview', 'month' => $mo])) ?>"><?= h(date('M Y', strtotime($mo . '-01'))) ?></a></strong></td><td class="r"><?= h(inr((float)$a['s'])) ?></td><td class="r"><?= (int)$a['n'] ?></td><td class="r"><?= $a['n'] ? h(inr($a['s'] / $a['n'])) : '–' ?></td><td class="r"><?= (int)$a['c'] ?></td><td class="r"><?= $lo ?> <span class="muted tiny">(<?= $pct((float)$lo, (float)($a['n'] + $lo)) ?>%)</span></td>
      <td class="r hm"><?= $sp > 0 ? h(inr($sp)) : '–' ?></td><td class="r hm"><?= $sp > 0 ? round(($perf[$mo]['sales'] ?: $a['s']) / $sp, 1) . 'x' : '–' ?></td><td class="r hm"><?= (int)$cc['n'] > 0 ? h(inr((float)$cc['a'] - (float)$cc['c'])) . ' <span class="muted tiny">(' . $pct((float)$cc['a'] - (float)$cc['c'], (float)$cc['a']) . '%)</span>' : '–' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'codes'):
  $qs = trim((string)($_GET['q'] ?? '')); $flt = (string)($_GET['f'] ?? '');
  $rows = $q("SELECT code, SUM(amount) s, COUNT(*) n, MAX(day) ld FROM inf_order WHERE kind='sale' AND $mw GROUP BY code ORDER BY s DESC");
  $lostBy = []; foreach ($q("SELECT code, COUNT(*) n FROM inf_order WHERE kind IN ('lost') AND $mw GROUP BY code") as $r) { $lostBy[$r['code']] = (int)$r['n']; }
  $stat = function (string $code) use ($first, $daysAgo) { $ld = $first[$code]['ld'] ?? ''; if ($ld === '') { return ['–', '']; } $d = $daysAgo($ld); return $d <= 14 ? ['Active', ''] : ($d <= 45 ? ['Slowing', 'chip--warn'] : ['Dormant', 'chip--bad']); };
  $list = array_values(array_filter($rows, function ($r) use ($qs, $flt, $stat, $disp) {
    if ($qs !== '' && stripos($r['code'] . ' ' . ($disp[$r['code']]['name'] ?? '') . ' ' . ($disp[$r['code']]['insta'] ?? ''), $qs) === false) { return false; }
    return $flt === '' || $stat($r['code'])[0] === $flt; }));
  $cnt = ['Active' => 0, 'Slowing' => 0, 'Dormant' => 0]; foreach ($rows as $r) { $s2 = $stat($r['code'])[0]; if (isset($cnt[$s2])) { $cnt[$s2]++; } }
  $pk = ['t' => 'codes'] + $keep; ?>
<div class="kpis kpis--4">
  <?= $kpi('Codes with sales', (string)count($rows), $mLabel, 'users') ?>
  <?= $kpi('Active (order in 14 days)', (string)$cnt['Active'], 'of the codes shown', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('Slowing (15 to 45 days)', (string)$cnt['Slowing'], 'worth a nudge', 'target', 'kpi__ic--warn') ?>
  <?= $kpi('Dormant (45+ days)', (string)$cnt['Dormant'], 'no order for a month and a half', 'users', 'kpi__ic--warn') ?>
</div>
<section class="card"><div class="card__h"><h2>Influencer codes · <?= h($mLabel) ?></h2><span class="muted tiny"><?= count($list) ?> shown</span></div>
  <div style="margin-bottom:10px"><a class="chip <?= $flt === '' ? '' : 'chip--warn' ?>" href="<?= h(url('infdash', $pk)) ?>">All</a> <?php foreach ($cnt as $cn => $cv): ?><a class="chip <?= $flt === $cn ? '' : 'chip--warn' ?>" href="<?= h(url('infdash', $pk + ['f' => $cn])) ?>"><?= h($cn) ?> <?= $cv ?></a> <?php endforeach; ?></div>
  <form method="get" class="formrow"><input type="hidden" name="p" value="infdash"><input type="hidden" name="t" value="codes"><input type="hidden" name="month" value="<?= h($m) ?>"><input type="hidden" name="f" value="<?= h($flt) ?>">
    <label class="field grow"><span>Search</span><input name="q" value="<?= h($qs) ?>" placeholder="Code, name or Instagram"></label><button class="btn btn--ghost" type="submit">Search</button></form>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Code</th><th class="r">Sales</th><th class="r">Orders</th><th class="r">Avg order</th><th class="r hm">Share</th><th class="r hm">Lost</th><th>Last order</th><th class="r hm">Lifetime</th><th>Status</th></tr></thead><tbody>
  <?php foreach (array_slice($list, 0, 200) as $r): $d = $disp[$r['code']] ?? []; [$sl, $sc] = $stat($r['code']); $f0 = $first[$r['code']] ?? []; ?>
    <tr><td><strong><?= h($dn($r['code'])) ?></strong><?= ($d['name'] ?? '') !== '' ? '<br><span class="muted tiny">' . h($d['name']) . '</span>' : '' ?><?= ($d['insta'] ?? '') !== '' ? '<br><a class="tiny" target="_blank" rel="noopener" href="' . h(preg_match('~^https?://~', $d['insta']) ? $d['insta'] : 'https://' . $d['insta']) . '">Instagram</a>' : '' ?><?= ($d['stack'] ?? '') === 'yes' ? ' <span class="chip">Stack sent</span>' : '' ?></td>
      <td class="r"><strong><?= h(inr((float)$r['s'])) ?></strong></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= h(inr($r['s'] / max(1, $r['n']))) ?></td><td class="r hm"><?= $pct((float)$r['s'], $sales) ?>%</td><td class="r hm"><?= $lostBy[$r['code']] ?? 0 ?></td>
      <td><?= h(fmt_date($f0['ld'] ?? $r['ld'])) ?> <span class="muted tiny">(<?= $daysAgo($f0['ld'] ?? $r['ld']) ?> d)</span></td><td class="r hm"><?= h(inr_short((float)($f0['s'] ?? 0))) ?> <span class="muted tiny">· <?= (int)($f0['n'] ?? 0) ?> orders</span></td><td><span class="chip <?= $sc ?>"><?= h($sl) ?></span></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted tiny" style="margin-top:8px">Status uses the last order date over all months. Lifetime is every order since October 2025. Names and Instagram links come from the "Affiliate 12M Analysis", "code orders" and "payments" tabs.</p></section>

<?php elseif ($tabNow === 'daily'):
  $days = $q("SELECT day, SUM(amount) s, COUNT(*) n, COUNT(DISTINCT code) c FROM inf_order WHERE kind='sale' AND $mw GROUP BY day ORDER BY day DESC");
  $lostD = []; foreach ($q("SELECT day, COUNT(*) n FROM inf_order WHERE kind='lost' AND $mw GROUP BY day") as $r) { $lostD[$r['day']] = (int)$r['n']; }
  $mxd = max(1.0, (float)max(array_merge([1], array_map('floatval', array_column($days, 's')))));
  $best = $days ? array_reduce($days, fn($c, $r) => $c === null || $r['s'] > $c['s'] ? $r : $c) : null; ?>
<div class="kpis kpis--4">
  <?= $kpi('Days with orders', (string)count($days), $mLabel, 'calendar') ?>
  <?= $kpi('Average per day', h(inr_short(count($days) ? $sales / count($days) : 0)), 'on days with an order', 'trend') ?>
  <?= $kpi('Best day', $best ? h(inr_short((float)$best['s'])) : '–', $best ? h(fmt_date($best['day'])) . ' · ' . (int)$best['n'] . ' orders' : '', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('Orders per day', count($days) ? (string)round($nOrd / count($days), 1) : '–', 'average', 'bag') ?>
</div>
<section class="card"><div class="card__h"><h2>Day by day · <?= h($mLabel) ?></h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Day</th><th class="r">Sales</th><th></th><th class="r">Orders</th><th class="r">Codes</th><th class="r hm">Lost</th></tr></thead><tbody>
  <?php foreach ($days as $d): ?><tr><td><?= h(fmt_date($d['day'])) ?> <span class="muted tiny"><?= h(date('D', strtotime($d['day']))) ?></span></td><td class="r"><strong><?= h(inr((float)$d['s'])) ?></strong></td><td style="width:34%"><span class="hbar__t" style="display:block"><i style="width:<?= round($d['s'] / $mxd * 100) ?>%"></i></span></td><td class="r"><?= (int)$d['n'] ?></td><td class="r"><?= (int)$d['c'] ?></td><td class="r hm"><?= $lostD[$d['day']] ?? 0 ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'products'):
  $prod = $q("SELECT product, COUNT(*) n, SUM(cogs) c FROM inf_line WHERE $mw GROUP BY product ORDER BY n DESC");
  $gift = fn(string $p) => (bool)preg_match('/earbud|gym bag|food scale|hoodie|t-?shirt|shaker|bottle|\bcap\b|towel|sipper/', $p);
  $gN = 0; $gC = 0.0; foreach ($prod as $p) { if ($gift($p['product'])) { $gN += (int)$p['n']; $gC += (float)$p['c']; } }
  $costOrders = $one("SELECT COALESCE(SUM(cogs),0) c, COALESCE(SUM(ship),0) s FROM inf_order WHERE kind='sale' AND cogs > 0 AND $mw"); ?>
<div class="kpis kpis--4">
  <?= $kpi('Items sent', (string)array_sum(array_column($prod, 'n')), 'product lines on the orders', 'bag') ?>
  <?= $kpi('Product cost', h(inr_short((float)$costOrders['c'])), 'COGS on orders with costs filled in', 'bag', 'kpi__ic--warn') ?>
  <?= $kpi('Shipping', h(inr_short((float)$costOrders['s'])), 'shipping charge column', 'bag', 'kpi__ic--warn') ?>
  <?= $kpi('Gifts and merch', (string)$gN, $gC > 0 ? 'cost ' . h(inr_short($gC)) . ' (earbuds, gym bags, scales, hoodies)' : 'earbuds, gym bags, scales, hoodies', 'users') ?>
</div>
<section class="card"><div class="card__h"><h2>Products on influencer orders · <?= h($mLabel) ?></h2><span class="muted tiny"><?= count($prod) ?> products</span></div>
  <?php if (!$prod): ?><p class="muted">No product names for this period.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Product</th><th class="r">Times sent</th><th class="r">Cost (COGS)</th><th></th></tr></thead><tbody>
  <?php foreach (array_slice($prod, 0, 80) as $p): ?><tr><td><?= h($p['product']) ?></td><td class="r"><?= (int)$p['n'] ?></td><td class="r"><?= $p['c'] > 0 ? h(inr((float)$p['c'])) : '–' ?></td><td><?= $gift($p['product']) ? '<span class="chip chip--warn">Gift</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <p class="muted tiny" style="margin-top:8px">Cost columns are filled in from September 2026. Product names before that come from the "Products" column of each month, so they carry no cost.</p></section>
<?php $hi = $q("SELECT code, SUM(amount) a, SUM(cogs+ship) c, COUNT(*) n FROM inf_order WHERE kind='sale' AND cogs > 0 AND $mw GROUP BY code HAVING c > 0 ORDER BY (SUM(amount) - SUM(cogs+ship)) ASC LIMIT 15"); ?>
<?php if ($hi): ?><section class="card"><div class="card__h"><h2>Lowest realisation by influencer</h2><span class="muted tiny">amount received minus product cost and shipping</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Code</th><th class="r">Orders</th><th class="r">Received</th><th class="r">Cost</th><th class="r">Realisation</th></tr></thead><tbody>
  <?php foreach ($hi as $r): $rl = $r['a'] - $r['c']; ?><tr><td><strong><?= h($dn($r['code'])) ?></strong></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= h(inr((float)$r['a'])) ?></td><td class="r"><?= h(inr((float)$r['c'])) ?></td><td class="r"><?= $rl < 0 ? '<span class="chip chip--bad">' . h(inr($rl)) . '</span>' : h(inr($rl)) . ' <span class="muted tiny">(' . $pct($rl, (float)$r['a']) . '%)</span>' ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>

<?php elseif ($tabNow === 'payouts'):
  $pay = $q('SELECT * FROM inf_pay ORDER BY block, paid, total DESC');
  $paidT = 0.0; $dueT = 0.0; foreach ($pay as $p) { if ($p['paid']) { $paidT += $p['comm']; } else { $dueT += $p['total'] * 0.95 * 0.10; } }
  $est = $q("SELECT code, SUM(amount) s, COUNT(*) n FROM inf_order WHERE kind='sale' AND $mw GROUP BY code ORDER BY s DESC"); ?>
<div class="kpis kpis--4">
  <?= $kpi('Commission paid (payments tab)', h(inr_short($paidT)), count(array_filter($pay, fn($p) => $p['paid'])) . ' payouts marked done', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('Waiting to be paid', h(inr_short($dueT)), count(array_filter($pay, fn($p) => !$p['paid'])) . ' payouts, 10% of 95% of sales', 'target', 'kpi__ic--warn') ?>
  <?= $kpi('Sales in ' . $mLabel, h(inr_short($sales)), $nOrd . ' orders', 'trend') ?>
  <?= $kpi('Commission at 10%', h(inr_short((float)$estComm)), 'if every code earned 10% of net (95%) sales', 'bag') ?>
</div>
<section class="card"><div class="card__h"><h2>Payouts listed in the sheet</h2><span class="muted tiny">bank, UPI and PAN details are never read</span></div>
  <?php if (!$pay): ?><p class="muted">The payments tab has no rows.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Influencer</th><th>List</th><th class="r">Total sales</th><th class="r">Net (95%)</th><th class="r">Commission (10%)</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($pay as $p): $net = $p['net'] > 0 ? $p['net'] : $p['total'] * 0.95; $cm = $p['comm'] > 0 ? $p['comm'] : $net * 0.10; ?><tr><td><strong><?= h($p['name']) ?></strong><br><span class="muted tiny"><?= h($dn($p['code'])) ?></span></td><td><?= h($p['block']) ?></td><td class="r"><?= h(inr((float)$p['total'])) ?></td><td class="r"><?= h(inr((float)$net)) ?></td><td class="r"><?= h(inr((float)$cm)) ?></td><td><span class="chip <?= $p['paid'] ? 'chip--ok' : 'chip--warn' ?>"><?= $p['paid'] ? 'Paid' : 'To pay' ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="card"><div class="card__h"><h2>Commission estimate by code · <?= h($mLabel) ?></h2><span class="muted tiny">an estimate, not what was agreed per person</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Code</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">10% of net</th><th class="hm">Stack</th><th class="hm">Commission flag</th></tr></thead><tbody>
  <?php foreach (array_slice($est, 0, 80) as $r): $d = $disp[$r['code']] ?? []; ?><tr><td><strong><?= h($dn($r['code'])) ?></strong></td><td class="r"><?= (int)$r['n'] ?></td><td class="r"><?= h(inr((float)$r['s'])) ?></td><td class="r"><?= h(inr(round($r['s'] * 0.95 * 0.10))) ?></td><td class="hm"><?= h($d['stack'] ?? '') ?: '–' ?></td><td class="hm"><?= h($d['comm'] ?? '') ?: '–' ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'barter'):
  $bt = $q('SELECT * FROM inf_barter ORDER BY rowid');
  $sold = []; foreach ($q("SELECT code, SUM(amount) s, COUNT(*) n FROM inf_order WHERE kind='sale' GROUP BY code") as $r) { $sold[$r['code']] = $r; }
  $withSales = count(array_filter($bt, fn($b) => isset($sold[strtolower(preg_replace('/\s+/', '', $b['code']))]))); ?>
<div class="kpis kpis--4">
  <?= $kpi('Barter influencers', (string)count($bt), 'free product sent for a reel', 'users') ?>
  <?= $kpi('With a reel link', (string)count(array_filter($bt, fn($b) => $b['reel'] !== '')), 'reel or post URL filled in', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('Reel link missing', (string)count(array_filter($bt, fn($b) => $b['reel'] === '')), 'follow up for the content', 'target', 'kpi__ic--warn') ?>
  <?= $kpi('Drove sales with their code', (string)$withSales, h(inr_short((float)array_sum(array_map(fn($b) => (float)($sold[strtolower(preg_replace('/\s+/', '', $b['code']))]['s'] ?? 0), $bt)))) . ' lifetime sales', 'trend') ?>
</div>
<section class="card"><div class="card__h"><h2>Barter and stacks</h2><span class="muted tiny">phone numbers and addresses are never read</span></div>
  <?php if (!$bt): ?><p class="muted">The barter tab has no rows.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Influencer</th><th>Code</th><th>Product sent</th><th>Reel</th><th class="r">Sales with code</th></tr></thead><tbody>
  <?php foreach ($bt as $b): $ck = strtolower(preg_replace('/\s+/', '', $b['code'])); $sd = $sold[$ck] ?? null; ?><tr><td><strong><?= h($b['name']) ?></strong><?= $b['grp'] !== '' ? '<br><span class="muted tiny">' . h($b['grp']) . '</span>' : '' ?><?= $b['insta'] !== '' ? '<br><a class="tiny" target="_blank" rel="noopener" href="' . h(preg_match('~^https?://~', $b['insta']) ? $b['insta'] : 'https://' . $b['insta']) . '">Instagram</a>' : '' ?></td><td><?= h($b['code']) ?></td><td><?= h($b['product']) ?></td>
    <td><?= $b['reel'] !== '' ? '<a target="_blank" rel="noopener" href="' . h(preg_match('~^https?://~', $b['reel']) ? $b['reel'] : 'https://' . $b['reel']) . '">Reel</a>' : '<span class="chip chip--warn">Missing</span>' ?></td><td class="r"><?= $sd ? h(inr((float)$sd['s'])) . ' <span class="muted tiny">· ' . (int)$sd['n'] . ' orders</span>' : '–' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<?php else: // all orders
  $qs = trim((string)($_GET['q'] ?? '')); $kf = (string)($_GET['k'] ?? '');
  $kc = []; foreach ($q("SELECT kind, COUNT(*) n FROM inf_order WHERE $mw GROUP BY kind") as $r) { $kc[$r['kind']] = (int)$r['n']; }
  $sql = "SELECT * FROM inf_order WHERE $mw"; $args = [];
  if (isset($kc[$kf])) { $sql .= ' AND kind = ?'; $args[] = $kf; }
  if ($qs !== '') { $sql .= ' AND (code LIKE ? OR oid LIKE ? OR products LIKE ?)'; array_push($args, "%$qs%", "%$qs%", "%$qs%"); }
  $ord = $q($sql . ' ORDER BY day DESC, rowid DESC LIMIT 400', $args); $pk = ['t' => 'orders'] + $keep; $kn = ['sale' => 'Sales', 'lost' => 'Lost', 'pending' => 'Pending']; ?>
<section class="card"><div class="card__h"><h2>All orders · <?= h($mLabel) ?></h2><span class="muted tiny"><?= count($ord) ?> shown, newest first</span></div>
  <div style="margin-bottom:10px"><a class="chip <?= $kf === '' ? '' : 'chip--warn' ?>" href="<?= h(url('infdash', $pk)) ?>">All <?= array_sum($kc) ?></a> <?php foreach ($kn as $kk => $kl): if (!isset($kc[$kk])) { continue; } ?><a class="chip <?= $kf === $kk ? '' : 'chip--warn' ?>" href="<?= h(url('infdash', $pk + ['k' => $kk])) ?>"><?= h($kl) ?> <?= $kc[$kk] ?></a> <?php endforeach; ?></div>
  <form method="get" class="formrow"><input type="hidden" name="p" value="infdash"><input type="hidden" name="t" value="orders"><input type="hidden" name="month" value="<?= h($m) ?>"><input type="hidden" name="k" value="<?= h($kf) ?>">
    <label class="field grow"><span>Search</span><input name="q" value="<?= h($qs) ?>" placeholder="Code, order number or product"></label><button class="btn btn--ghost" type="submit">Search</button></form>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th>Order</th><th>Code</th><th class="r">Amount</th><th>Status</th><th class="hm">Products</th><th class="r hm">Cost</th><th class="r hm">Realisation</th></tr></thead><tbody>
  <?php foreach ($ord as $o): $cost = $o['cogs'] + $o['ship']; ?><tr><td><?= h(fmt_date($o['day'])) ?></td><td><?= h($o['oid']) ?></td><td><strong><?= h($dn($o['code'])) ?></strong></td><td class="r"><?= $o['kind'] === 'sale' ? h(inr((float)$o['amount'])) : ($o['value'] > 0 ? '<span class="muted">' . h(inr((float)$o['value'])) . '</span>' : '–') ?></td>
    <td><span class="chip <?= $o['kind'] === 'sale' ? 'chip--ok' : ($o['kind'] === 'lost' ? 'chip--bad' : 'chip--warn') ?>"><?= h(ucfirst($o['status'])) ?></span></td><td class="hm"><span class="tiny"><?= h(mb_substr($o['products'], 0, 90)) ?></span></td><td class="r hm"><?= $o['cogs'] > 0 ? h(inr((float)$cost)) : '–' ?></td><td class="r hm"><?= $o['cogs'] > 0 && $o['kind'] === 'sale' ? h(inr((float)($o['amount'] - $cost))) : '–' ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php if (count($ord) >= 400): ?><p class="muted tiny">Showing the latest 400. Use search to narrow down.</p><?php endif; ?></section>
<?php endif; ?>
<?php layout_foot();
