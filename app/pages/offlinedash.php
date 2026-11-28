<?php
defined('DD') or exit;
$pdo = db(); $me = user(); $today = today();
$bh = ($_GET['v'] ?? '') === 'bhanu'; $stream = $bh ? 'bhanu' : 'offline'; $title = OFFLINE_STREAMS[$stream];
$canUp = can_enter_channels($me);

$canTarget = in_array($me['role'], ['admin', 'ceo'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canTarget && ($_POST['do'] ?? '') === 'targets') {
    csrf_verify();
    $tt = (float)preg_replace('/[^0-9.]/', '', (string)($_POST['total'] ?? '')); $te = (float)preg_replace('/[^0-9.]/', '', (string)($_POST['each'] ?? ''));
    if ($tt > 0) { set_setting('off_target_total', (string)round($tt)); }
    if ($te > 0) { set_setting('off_target_each', (string)round($te)); }
    flash('ok', 'Offline targets saved.');
    redirect(url('offlinedash', array_filter(['range' => $_GET['range'] ?? null, 'month' => $_GET['month'] ?? null])));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canUp) {
    csrf_verify();
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) > 5000000) { flash('error', 'Choose the invoice CSV file (up to 5 MB).'); }
    else {
        try { $x = offline_import($f['tmp_name'], $stream); flash('ok', 'Imported ' . $x['rows'] . ' invoices, ' . inr($x['sales']) . ', ' . fmt_date($x['from']) . ' to ' . fmt_date($x['to']) . '.' . ($x['skipped'] ? ' Skipped ' . $x['skipped'] . ' rows without a date or amount.' : '')); }
        catch (Throwable $e) { flash('error', $e->getMessage()); }
    }
    redirect(url('offlinedash', array_filter(['v' => $stream === 'bhanu' ? 'bhanu' : null, 'range' => $_GET['range'] ?? null, 'month' => $_GET['month'] ?? null])));
}

$hasB2b = b2b_has_data();
$tabNow = (string)($_GET['t'] ?? ($hasB2b ? 'overview' : 'invoices'));
if ($bh && $hasB2b && $tabNow !== 'invoices') {
    $tabNav = fn(string $v) => '<a class="btn ' . (($v === 'bhanu') === $bh ? '' : 'btn--ghost') . '" href="' . h(url('offlinedash', $v === 'bhanu' ? ['v' => 'bhanu'] : [])) . '">' . h(OFFLINE_STREAMS[$v]) . '</a>';
    require __DIR__ . '/b2bdash.php';
    return;
}
$P = period_pick(); $from = $P['from']; $to = $P['to'];
$rows = offline_orders_between($from, $to, $stream);
$prev = offline_orders_between($P['pf'], $P['pt'], $stream);
$sum = fn(array $a, string $k) => array_sum(array_map(fn($r) => (float)$r[$k], $a));
$sales = $sum($rows, 'total'); $adv = $sum($rows, 'advance'); $bal = $sum($rows, 'balance'); $n = count($rows);
$pSales = $sum($prev, 'total');
$custKey = fn($r) => $r['phone'] !== '' ? substr($r['phone'], -10) : strtolower(trim($r['customer']));
$cust = [];
foreach ($rows as $r) {
    $k = $custKey($r);
    $cust[$k] ??= ['name' => trim($r['customer']), 'n' => 0, 'sales' => 0.0, 'adv' => 0.0, 'bal' => 0.0, 'first' => $r['day'], 'oldDue' => null];
    $c = &$cust[$k]; $c['n']++; $c['sales'] += $r['total']; $c['adv'] += $r['advance']; $c['bal'] += $r['balance']; $c['first'] = min($c['first'], $r['day']);
    if ($r['balance'] > 0.5) { $c['oldDue'] = $c['oldDue'] === null ? $r['day'] : min($c['oldDue'], $r['day']); }
    unset($c);
}
$repeat = count(array_filter($cust, fn($c) => $c['n'] > 1));
$owing = array_filter($cust, fn($c) => $c['bal'] > 0.5);
$daysAgo = fn(string $d) => (int)floor((strtotime($today) - strtotime($d)) / 86400);
$oldest = null; foreach ($rows as $r) { if ($r['balance'] > 0.5 && ($oldest === null || $r['day'] < $oldest)) { $oldest = $r['day']; } }
$sellers = offline_by_seller($from, $to, $stream);
$status = []; foreach ($rows as $r) { $k = $r['status'] !== '' ? $r['status'] : 'No status'; $status[$k] ??= ['n' => 0, 't' => 0.0, 'b' => 0.0]; $status[$k]['n']++; $status[$k]['t'] += $r['total']; $status[$k]['b'] += $r['balance']; }
uasort($status, fn($a, $b) => $b['t'] <=> $a['t']);
$daily = []; foreach ($rows as $r) { $daily[$r['day']] ??= ['n' => 0, 't' => 0.0, 'a' => 0.0]; $daily[$r['day']]['n']++; $daily[$r['day']]['t'] += $r['total']; $daily[$r['day']]['a'] += $r['advance']; }
krsort($daily); $maxDay = $daily ? max(array_column($daily, 't')) : 0;
$aging = ['0 to 7 days' => [0, 7, 0.0, 0], '8 to 15 days' => [8, 15, 0.0, 0], '16 to 30 days' => [16, 30, 0.0, 0], 'Over 30 days' => [31, 99999, 0.0, 0]];
foreach ($rows as $r) { if ($r['balance'] <= 0.5) { continue; } $a = $daysAgo($r['day']); foreach ($aging as $k => &$g) { if ($a >= $g[0] && $a <= $g[1]) { $g[2] += $r['balance']; $g[3]++; break; } } unset($g); }
$chg = function (float $c, float $o): string {
    if ($o <= 0) { return $c > 0 ? '<span class="delta delta--flat">new</span>' : ''; }
    $pc = round(($c - $o) / $o * 100, 1);
    if (abs($pc) < 0.1) { return '<span class="delta delta--flat">0%</span>'; }
    return '<span class="delta delta--' . ($pc > 0 ? 'up' : 'down') . '">' . ($pc > 0 ? '▲' : '▼') . ' ' . h(rtrim(rtrim(number_format(abs($pc), 1), '0'), '.')) . '%</span>';
};
$pct = fn(float $v, float $t) => $t > 0 ? round($v / $t * 100) : 0;
$keep = array_filter(['range' => $_GET['range'] ?? null, 'month' => $_GET['month'] ?? null]);
$tab = fn(string $v, string $l) => '<a class="btn ' . (($v === 'bhanu') === $bh ? '' : 'btn--ghost') . '" href="' . h(url('offlinedash', $keep + ($v === 'bhanu' ? ['v' => 'bhanu'] : []))) . '">' . h($l) . '</a>';

layout_head($title . ' dashboard');
?>
<div class="pagehead">
  <div><h1><?= h($title) ?></h1><p class="muted"><?= $bh ? 'Credit sales: what was given, what has come back and who still owes' : 'Invoices from the offline team: sellers, collections and pending balance' ?> · <?= h($P['label']) ?><?= $P['isMonth'] ? '' : ' (' . h(fmt_date($from)) . ($from === $to ? '' : ' to ' . h(fmt_date($to))) . ')' ?></p></div>
  <div class="pagehead__r"><?= $tab('offline', 'Offline') ?> <?= $tab('bhanu', 'Bhanu credit offline') ?> <?= period_bar('offlinedash', $P, $bh ? ['v' => 'bhanu'] : []) ?></div>
</div>

<?php if (!$bh && ($sn = setting('off_sync_note')) !== ''): ?><p class="muted tiny" style="margin:-6px 0 10px">Google sheet: <?= h(substr($sn, 0, 16)) ?> · <?= h(substr($sn, 17)) ?></p><?php endif; ?>
<?php if ($bh && $hasB2b): ?><div class="pagehead__r" style="margin:-4px 0 14px;flex-wrap:wrap"><?php foreach (['overview' => 'Overview', 'reminders' => 'Reminders', 'distributors' => 'Distributors', 'followups' => 'Follow-ups', 'legal' => 'Legal and blocked', 'pipeline' => 'Pipeline', 'invoices' => 'Invoices (CSV)'] as $tk => $tl): ?><a class="btn btn--sm <?= $tk === 'invoices' ? '' : 'btn--ghost' ?>" href="<?= h(url('offlinedash', ['v' => 'bhanu', 't' => $tk])) ?>"><?= h($tl) ?></a> <?php endforeach; ?></div><?php endif; ?>
<?php if (!$rows): ?>
<section class="card"><p class="muted">No <?= h($title) ?> invoices in this period.<?= $bh ? ' Until you upload Bhanu\'s file, his figure comes from the main sale sheet.' : '' ?></p></section>
<?php else: ?>
<div class="kpis kpis--4">
<?php if (!$bh): ?>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Offline sales</span><span class="kpi__ic kpi__ic--ok"><?= icon('trend') ?></span></div><strong class="kpi__v"><?= h(inr_short($sales)) ?> <?= $chg($sales, $pSales) ?></strong><span class="kpi__s">vs <?= h($P['plabel']) ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Invoices</span><span class="kpi__ic"><?= icon('bag') ?></span></div><strong class="kpi__v"><?= $n ?></strong><span class="kpi__s">Average <?= h(inr_short($sales / max(1, $n))) ?> each</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Advance received</span><span class="kpi__ic kpi__ic--ok"><?= icon('check') ?></span></div><strong class="kpi__v"><?= h(inr_short($adv)) ?></strong><span class="kpi__s"><?= $pct($adv, $sales) ?>% of sales</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Balance due</span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short($bal)) ?></strong><span class="kpi__s"><?= $pct($bal, $sales) ?>% still to collect</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Customers</span><span class="kpi__ic"><?= icon('users') ?></span></div><strong class="kpi__v"><?= count($cust) ?></strong><span class="kpi__s"><?= $repeat ?> ordered more than once</span></div>
  <?php $pend = $status['Placed']['t'] ?? 0; $late = $status['Courier Delayed']['t'] ?? 0; ?>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Placed, not shipped</span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short((float)$pend)) ?></strong><span class="kpi__s"><?= (int)($status['Placed']['n'] ?? 0) ?> invoices</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Courier delayed</span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short((float)$late)) ?></strong><span class="kpi__s"><?= (int)($status['Courier Delayed']['n'] ?? 0) ?> invoices</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Top seller</span><span class="kpi__ic"><?= icon('users') ?></span></div><strong class="kpi__v"><?= h($sellers[0]['seller'] ?? '–') ?></strong><span class="kpi__s"><?= isset($sellers[0]) ? h(inr_short((float)$sellers[0]['sales'])) . ' · ' . $pct((float)$sellers[0]['sales'], $sales) . '% of sales' : '' ?></span></div>
<?php else: ?>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Credit given</span><span class="kpi__ic kpi__ic--ok"><?= icon('trend') ?></span></div><strong class="kpi__v"><?= h(inr_short($sales)) ?> <?= $chg($sales, $pSales) ?></strong><span class="kpi__s"><?= $n ?> invoices · vs <?= h($P['plabel']) ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Collected</span><span class="kpi__ic kpi__ic--ok"><?= icon('check') ?></span></div><strong class="kpi__v"><?= h(inr_short($adv)) ?></strong><span class="kpi__s"><?= $pct($adv, $sales) ?>% of credit given</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Outstanding</span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short($bal)) ?></strong><span class="kpi__s"><?= $pct($bal, $sales) ?>% not yet received</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Customers owing</span><span class="kpi__ic"><?= icon('users') ?></span></div><strong class="kpi__v"><?= count($owing) ?> <span class="muted tiny">of <?= count($cust) ?></span></strong><span class="kpi__s">Oldest unpaid: <?= $oldest ? h(fmt_date($oldest)) . ' (' . $daysAgo($oldest) . ' days)' : '–' ?></span></div>
<?php endif; ?>
</div>

<?php if ($rows && ($tabNow === 'overview' || !$hasB2b || $tabNow === 'invoices')):
  $dA = $daily; ksort($dA); $inrf = fn($v) => inr((float)$v);
  $agC = [CH_PAL[0], CH_PAL[4], CH_PAL[1], CH_PAL[5], '#7A1B12']; $agParts = []; $ai = 0; foreach ($aging as $k => $g) { $agParts[] = [$k, (float)$g[2], $agC[$ai++ % 5]]; } ?>
<div class="chgrid chgrid--2">
  <section class="card"><div class="card__h"><h2>Sales and money received by day</h2><span class="muted tiny">invoice value against advance collected</span></div>
    <?= ch_combo(array_map(fn($d) => date('j M', strtotime($d)), array_keys($dA)), [['name' => 'Invoice value', 'values' => array_map(fn($v) => round($v['t']), array_values($dA)), 'color' => CH_PAL[0]]], [['name' => 'Advance received', 'values' => array_map(fn($v) => round($v['a']), array_values($dA)), 'color' => CH_PAL[1]]], ['fmt' => $inrf, 'label' => 'Offline sales by day']) ?></section>
  <section class="card"><div class="card__h"><h2>Money still to collect</h2><span class="muted tiny">by how old the invoice is</span></div><?= ch_donut($agParts, inr_short((float)$bal), 'outstanding', $inrf) ?></section>
</div>
<div class="chgrid">
  <section class="card"><div class="card__h"><h2>Sales by seller</h2></div><?= ch_donut(array_map(fn($x) => [$x['seller'] !== '' ? $x['seller'] : 'No seller', (float)$x['sales']], $sellers), inr_short((float)$sales), 'sales', $inrf) ?></section>
  <section class="card"><div class="card__h"><h2>By order status</h2></div><?= ch_donut(array_map(fn($k, $x) => [(string)$k, (float)$x['t']], array_keys($status), array_values($status)), inr_short((float)$sales), 'sales', $inrf) ?></section>
</div>
<?php endif; ?>

<?php if (!$bh && $P['isMonth']):
  $tgtTotal = (float)setting('off_target_total', '2500000'); $tgtEach = (float)setting('off_target_each', '500000');
  $mStart = $P['month'] . '-01'; $dim = (int)date('t', strtotime($mStart)); $isCur = $P['month'] === substr($today, 0, 7);
  $elapsed = $isCur ? (int)substr($today, 8, 2) : ($P['month'] < substr($today, 0, 7) ? $dim : 0); $left = $isCur ? $dim - $elapsed : 0;
  $proj = fn(float $v) => $elapsed > 0 ? $v / $elapsed * $dim : 0.0;
  $custBy = []; $bigBy = []; foreach ($rows as $r) { $custBy[$r['seller']][strtolower(trim($r['customer']))] = 1; $bigBy[$r['seller']] = max($bigBy[$r['seller']] ?? 0, (float)$r['total']); }
  $paceNow = $tgtTotal * ($elapsed / max(1, $dim)); $needDay = $left > 0 ? max(0.0, $tgtTotal - $sales) / $left : 0.0; $pj = $proj($sales); $big = $rows ? max(array_column($rows, 'total')) : 0; ?>
<section class="card"><div class="card__h"><h2>Target · <?= h($P['label']) ?></h2><span class="muted tiny">team <?= h(inr_short($tgtTotal)) ?> · <?= h(inr_short($tgtEach)) ?> each</span></div>
  <div class="kpis kpis--4" style="margin-bottom:12px">
    <div class="kpi"><div class="kpi__top"><span class="kpi__l">Achieved</span><span class="kpi__ic kpi__ic--ok"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short($sales)) ?></strong><span class="kpi__s"><?= $pct($sales, $tgtTotal) ?>% of <?= h(inr_short($tgtTotal)) ?></span></div>
    <div class="kpi"><div class="kpi__top"><span class="kpi__l">Still to make</span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short(max(0.0, $tgtTotal - $sales))) ?></strong><span class="kpi__s"><?= $left ?> days left after today<?= $left > 0 ? ' · ' . h(inr_short($needDay)) . ' a day' : '' ?></span></div>
    <div class="kpi"><div class="kpi__top"><span class="kpi__l">Month-end at this pace</span><span class="kpi__ic"><?= icon('trend') ?></span></div><strong class="kpi__v"><?= h(inr_short($pj)) ?></strong><span class="kpi__s"><?= $pj >= $tgtTotal ? 'on track for the target' : h(inr_short($tgtTotal - $pj)) . ' short at this pace' ?></span></div>
    <div class="kpi"><div class="kpi__top"><span class="kpi__l">Ahead or behind today</span><span class="kpi__ic"><?= icon('check') ?></span></div><strong class="kpi__v"><?= $sales >= $paceNow ? '+' : '-' ?><?= h(inr_short(abs($sales - $paceNow))) ?></strong><span class="kpi__s">against an even pace of <?= h(inr_short($paceNow)) ?> by today</span></div>
  </div>
  <div class="meter"><i style="width:<?= min(100, $pct($sales, $tgtTotal)) ?>%"></i><b style="left:<?= min(100, round($elapsed / max(1, $dim) * 100)) ?>%" title="Where an even pace would be today"></b></div>
  <p class="muted tiny" style="margin:2px 0 12px">The marker shows where an even daily pace would be by today.</p>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Seller</th><th class="r">Achieved</th><th class="r">Target</th><th style="width:22%">Progress</th><th class="r">Still to make</th><th class="r hm">A day needed</th><th class="r hm">At this pace</th><th class="r hm">Invoices</th><th class="r hm">Customers</th><th class="r hm">Biggest</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($sellers as $sl): $st = (float)$sl['sales']; $pp = $proj($st); $rem = max(0.0, $tgtEach - $st); $ok = $st >= $tgtEach; $okp = $pp >= $tgtEach; ?>
    <tr><td><strong><?= h($sl['seller'] ?: 'No seller') ?></strong></td><td class="r"><strong><?= h(inr($st)) ?></strong></td><td class="r"><?= h(inr($tgtEach)) ?></td>
      <td><div class="hbar__t"><i style="width:<?= min(100, $pct($st, $tgtEach)) ?>%"></i></div><span class="muted tiny"><?= $pct($st, $tgtEach) ?>%</span></td><td class="r"><?= $ok ? '–' : h(inr($rem)) ?></td><td class="r hm"><?= !$ok && $left > 0 ? h(inr($rem / $left)) : '–' ?></td><td class="r hm"><?= h(inr_short($pp)) ?></td><td class="r hm"><?= (int)$sl['orders'] ?></td><td class="r hm"><?= count($custBy[$sl['seller']] ?? []) ?></td><td class="r hm"><?= h(inr_short((float)($bigBy[$sl['seller']] ?? 0))) ?></td>
      <td><span class="chip <?= $ok ? 'chip--ok' : ($okp ? '' : 'chip--warn') ?>"><?= $ok ? 'Target hit' : ($okp ? 'On track' : 'Behind') ?></span></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($canTarget): ?><form method="post" class="formrow" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="do" value="targets">
    <label class="field"><span>Team target (₹)</span><input name="total" value="<?= h((string)(int)$tgtTotal) ?>" inputmode="numeric"></label><label class="field"><span>Each seller (₹)</span><input name="each" value="<?= h((string)(int)$tgtEach) ?>" inputmode="numeric"></label><button class="btn btn--ghost" type="submit">Save targets</button></form><?php endif; ?></section>
<?php endif; ?>

<?php if ($bh): ?>
<section class="card"><div class="card__h"><h2>Outstanding by age</h2><strong><?= h(inr($bal)) ?></strong></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Since invoice date</th><th class="r">Invoices</th><th class="r">Outstanding</th><th class="r">Share</th></tr></thead><tbody>
  <?php foreach ($aging as $k => $g): ?><tr><td><?= h($k) ?></td><td class="r"><?= $g[3] ?></td><td class="r"><?= h(inr($g[2])) ?></td><td class="r"><?= $pct($g[2], $bal) ?>%</td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><div class="card__h"><h2>Who owes</h2><span class="muted tiny">biggest balance first</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Customer</th><th class="r">Invoices</th><th class="r">Credit given</th><th class="r">Collected</th><th class="r">Outstanding</th><th class="r hm">Oldest unpaid</th></tr></thead><tbody>
  <?php $o = $owing; uasort($o, fn($a, $b) => $b['bal'] <=> $a['bal']); foreach ($o as $c): ?>
    <tr><td><strong><?= h($c['name']) ?></strong></td><td class="r"><?= $c['n'] ?></td><td class="r"><?= h(inr($c['sales'])) ?></td><td class="r"><?= h(inr($c['adv'])) ?></td><td class="r"><strong><?= h(inr($c['bal'])) ?></strong></td><td class="r hm"><?= h(fmt_date($c['oldDue'])) ?> <span class="muted tiny">(<?= $daysAgo($c['oldDue']) ?> d)</span></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php else: ?>
<section class="card"><div class="card__h"><h2>By seller</h2><strong><?= h(inr($sales)) ?></strong></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Seller</th><th class="r">Sales</th><th class="r">Share</th><th class="r">Invoices</th><th class="r hm">Average</th><th class="r hm">Advance</th><th class="r">Balance due</th><th class="r hm">Collected</th></tr></thead><tbody>
  <?php foreach ($sellers as $s): $st = (float)$s['sales']; ?>
    <tr><td><strong><?= h($s['seller'] ?: 'No seller') ?></strong></td><td class="r"><strong><?= h(inr($st)) ?></strong></td><td class="r"><?= $pct($st, $sales) ?>%</td><td class="r"><?= (int)$s['orders'] ?></td><td class="r hm"><?= h(inr($st / max(1, (int)$s['orders']))) ?></td><td class="r hm"><?= h(inr((float)$s['advance'])) ?></td><td class="r"><?= h(inr((float)$s['balance'])) ?></td><td class="r hm"><?= $pct((float)$s['advance'], $st) ?>%</td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<section class="card"><div class="card__h"><h2>Top customers</h2><span class="muted tiny"><?= count($cust) ?> customers</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Customer</th><th class="r">Invoices</th><th class="r">Sales</th><th class="r">Balance due</th></tr></thead><tbody>
  <?php $o = $cust; uasort($o, fn($a, $b) => $b['sales'] <=> $a['sales']); foreach (array_slice($o, 0, 10) as $c): ?>
    <tr><td><strong><?= h($c['name']) ?></strong><?= $c['n'] > 1 ? ' <span class="chip">repeat</span>' : '' ?></td><td class="r"><?= $c['n'] ?></td><td class="r"><?= h(inr($c['sales'])) ?></td><td class="r"><?= h(inr($c['bal'])) ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<?php if (array_keys($status) !== ['No status']): ?>
<section class="card"><div class="card__h"><h2>By order status</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Status</th><th class="r">Invoices</th><th class="r">Sales</th><th class="r">Share</th><th class="r">Balance due</th></tr></thead><tbody>
  <?php foreach ($status as $k => $x): ?><tr><td><?= h($k) ?></td><td class="r"><?= $x['n'] ?></td><td class="r"><?= h(inr($x['t'])) ?></td><td class="r"><?= $pct($x['t'], $sales) ?>%</td><td class="r"><?= h(inr($x['b'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<section class="card"><div class="card__h"><h2>Day by day</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th class="r">Invoices</th><th class="r">Sales</th><th class="r hm">Received</th><th style="width:34%"></th></tr></thead><tbody>
  <?php foreach ($daily as $d => $x): ?><tr><td><?= h(fmt_date($d)) ?></td><td class="r"><?= $x['n'] ?></td><td class="r"><strong><?= h(inr($x['t'])) ?></strong></td><td class="r hm"><?= h(inr($x['a'])) ?></td><td><div class="hbar__t"><i style="width:<?= $maxDay > 0 ? round($x['t'] / $maxDay * 100) : 0 ?>%"></i></div></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="card"><div class="card__h"><h2>All invoices</h2><span class="muted tiny"><?= $n ?> invoices</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>No</th><th>Date</th><th>Customer</th><?php if (!$bh): ?><th>Seller</th><th>Status</th><?php endif; ?><th class="r">Total</th><th class="r">Received</th><th class="r">Balance</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="muted tiny"><?= h(preg_replace('/^B:/', '', $r['no'])) ?></td><td><?= h(fmt_date($r['day'])) ?></td><td><?= h($r['customer']) ?></td><?php if (!$bh): ?><td><?= h($r['seller']) ?></td><td><?= h($r['status']) ?></td><?php endif; ?><td class="r"><?= h(inr((float)$r['total'])) ?></td><td class="r"><?= h(inr((float)$r['advance'])) ?></td><td class="r"><?= h(inr((float)$r['balance'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<?php if ($canUp): ?>
<section class="card"><h2>Upload <?= h($title) ?> invoices</h2>
  <p class="muted tiny">Upload the latest CSV any time. Invoices on the same dates are replaced, so nothing is counted twice. The total is added to <strong>Offline</strong> on the Sales overview<?= $bh ? ' as its own line, replacing Bhanu\'s figure from the main sale sheet for the dates covered' : '' ?>. Needs a Date column and a Total (or Amount) column; Customer, Phone, Seller, Status, Advance (or Received) and Balance are used when present.</p>
  <form method="post" enctype="multipart/form-data" class="formrow"><?= csrf_field() ?><label class="field grow"><span>CSV file</span><input type="file" name="csv" accept=".csv,text/csv" required></label><button class="btn" type="submit">Import</button></form>
</section>
<?php endif; ?>
<?php layout_foot();
