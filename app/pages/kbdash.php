<?php
defined('DD') or exit;
/* Khushboo's order desk: Shopify order confirmations, offline sale, online sale, B2B COD, samples, RTO. */
$pdo = db(); $today = today();
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); })();
$ym = s_month($_GET['month'] ?? null);
$curYm = substr($today, 0, 7); $isCur = $ym === $curYm;
$dim = (int)date('t', strtotime($ym . '-01')); $upto = $isCur ? (int)substr($today, 8, 2) : $dim;
$prev = date('Y-m', strtotime($ym . '-01 -1 month'));
$tabs = ['overview' => 'Orders overview', 'orders' => 'All orders', 'offline' => 'Offline and online sale', 'b2b' => 'B2B COD', 'samples' => 'Samples and RTO'];
$tabNow = isset($tabs[$_GET['t'] ?? '']) ? $_GET['t'] : 'overview';
$pct = fn(float $v, float $t) => $t > 0 ? round($v / $t * 100) : 0;
$kpi = fn(string $l, string $v, string $s = '', string $ic = 'trend', string $cls = '') => '<div class="kpi"><div class="kpi__top"><span class="kpi__l">' . h($l) . '</span><span class="kpi__ic ' . $cls . '">' . icon($ic) . '</span></div><strong class="kpi__v">' . $v . '</strong><span class="kpi__s">' . $s . '</span></div>';
$SC = ['del' => 'Delivered', 'tra' => 'In transit', 'ofd' => 'Out for delivery', 'rto' => 'RTO', 'can' => 'Cancelled', 'oth' => 'Other'];
$chip = fn(string $sc, string $txt = '') => '<span class="chip ' . (in_array($sc, ['rto', 'can'], true) ? 'chip--warn' : '') . '">' . h($txt !== '' ? $txt : ($SC[$sc] ?? $sc)) . '</span>';
$dchip = fn(string $s) => '<span class="chip ' . (preg_match('/rto|return|cancel|lost/i', $s) ? 'chip--warn' : '') . '">' . h($s !== '' ? $s : '–') . '</span>';
$dayLbl = fn(string $d) => $d === '' ? '–' : fmt_date($d);
$sum = function (string $m) use ($q): array {
    $r = $q("SELECT COUNT(*) n, SUM(sc != 'can') valid, SUM(CASE WHEN sc != 'can' THEN amt ELSE 0 END) val, SUM(sc = 'del') del, SUM(sc = 'rto') rto, SUM(sc = 'can') can, SUM(sc IN ('tra','ofd')) tra, SUM(sc = 'oth') oth,
        SUM(lower(pay) LIKE 'partial%') part, SUM(lower(pay) LIKE 'cod%') cod FROM kb_conf WHERE ym = ?", [$m]);
    return array_map('floatval', $r[0] ?? []);
};
$M = $sum($ym); $P = $sum($prev);
$meta = $q("SELECT label, kind, last_run, last_ok, last_msg FROM gsync_links WHERE kind IN ('kbconf','kbdet') ORDER BY id");
$latest = ''; foreach ($meta as $m) { if ($m['last_ok'] && $m['last_run'] > $latest) { $latest = $m['last_run']; } }

layout_head('Khushboo');
?>
<div class="pagehead">
  <div><h1>Khushboo · Order desk</h1><p class="muted">Orders she confirms and dispatches, her offline and online sale, B2B COD and samples<?= $latest !== '' ? ' · sheets read ' . h(fmt_date(substr($latest, 0, 10))) . ' ' . h(substr($latest, 11, 5)) : '' ?></p></div>
  <div class="pagehead__r"><a class="btn btn--ghost btn--sm" href="<?= h(url('track', ['month' => $ym, 'who' => 'Khushboo'])) ?>">Her target</a></div>
</div>
<?php foreach ($meta as $m): if (!$m['last_ok'] && $m['last_msg'] !== ''): ?><p class="tiny" style="color:var(--bad,#c0392b)"><?= h($m['label']) ?>: <?= h($m['last_msg']) ?></p><?php endif; endforeach; ?>
<?php if (!$q('SELECT 1 FROM kb_conf LIMIT 1') && !$q('SELECT 1 FROM kb_off LIMIT 1')): ?>
<section class="card"><p class="muted">Nothing read yet. Both sheets are linked under Admin, Google Sheets ("Khushboo · Order confirmation" and "Khushboo · Order details") and are read every 10 minutes. Press Sync now there to read them immediately. Each must be shared as "Anyone with the link: Viewer".</p></section>
<?php layout_foot(); return; endif; ?>

<div class="pagehead__r" style="margin:-4px 0 14px;flex-wrap:wrap"><?php foreach ($tabs as $tk => $tl): ?><a class="btn btn--sm <?= $tk === $tabNow ? '' : 'btn--ghost' ?>" href="<?= h(url('kbdash', ['t' => $tk, 'month' => $ym])) ?>"><?= h($tl) ?></a> <?php endforeach; ?></div>
<?php if (in_array($tabNow, ['overview', 'orders'], true)) { s_month_filter('kbdash', $ym, '<input type="hidden" name="t" value="' . h($tabNow) . '">'); } ?>

<?php if ($tabNow === 'overview'):
  $closed = $M['del'] + $M['rto']; $perDay = $upto > 0 ? $M['valid'] / $upto : 0;
  $pm = $q("SELECT COUNT(*) n FROM kb_conf WHERE ym = ? AND day BETWEEN 1 AND ?", [$prev, $upto]); $pSame = (float)($pm[0]['n'] ?? 0);
  $delta = $pSame > 0 ? round(($M['n'] - $pSame) / $pSame * 100) : null;
  $aov = $M['valid'] > 0 ? $M['val'] / $M['valid'] : 0;
?>
<?php if (!$M['n']): ?><section class="card"><p class="muted">No confirmed orders for <?= h(date('F Y', strtotime($ym . '-01'))) ?> in her sheet.</p></section><?php else: ?>
<div class="kpis kpis--4">
  <?= $kpi('Orders confirmed', (string)(int)$M['n'], $delta === null ? '' : ($delta >= 0 ? '+' : '') . $delta . '% vs ' . date('M', strtotime($prev . '-01')) . ' same days', 'bag', $delta !== null && $delta < 0 ? 'kpi__ic--warn' : 'kpi__ic--ok') ?>
  <?= $kpi('Order value', inr_short($M['val']), 'average ' . inr($aov) . ' per order', 'trend') ?>
  <?= $kpi('Delivered', (string)(int)$M['del'], $pct($M['del'], $closed) . '% of delivered + RTO', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('RTO', (string)(int)$M['rto'], $pct($M['rto'], $closed) . '% of delivered + RTO', 'target', $M['rto'] > 0 && $pct($M['rto'], $closed) >= 6 ? 'kpi__ic--warn' : '') ?>
  <?= $kpi('On the way', (string)(int)($M['tra']), 'in transit or out for delivery', 'users') ?>
  <?= $kpi('Cancelled', (string)(int)$M['can'], $pct($M['can'], $M['n']) . '% of confirmed', 'target') ?>
  <?= $kpi('Partial paid (COD part)', (string)(int)$M['part'], $pct($M['part'], $M['n']) . '% of orders', 'bag') ?>
  <?= $kpi($isCur ? 'Pace per day' : 'Average per day', number_format($perDay, 1), $isCur ? 'month-end estimate about ' . (int)round($perDay * $dim) . ' orders' : 'orders a day', 'trend') ?>
</div>

<section class="card"><div class="card__h"><h2>Day by day</h2><span class="muted tiny">orders she confirmed each day</span></div>
  <?php $days = []; foreach ($q("SELECT day, COUNT(*) n, SUM(CASE WHEN sc != 'can' THEN amt ELSE 0 END) v, SUM(sc = 'rto') rto FROM kb_conf WHERE ym = ? AND day > 0 GROUP BY day", [$ym]) as $r) { $days[(int)$r['day']] = $r; }
        $mx = max(1, max(array_merge([1], array_map(fn($r) => (int)$r['n'], $days)))); ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Day</th><th class="r">Orders</th><th class="r">Value</th><th class="r">RTO</th><th style="width:40%"></th></tr></thead><tbody>
  <?php for ($d = $upto; $d >= 1; $d--): $r = $days[$d] ?? null; $dt = $ym . '-' . sprintf('%02d', $d); ?>
    <tr><td><strong><?= h(date('D, j M', strtotime($dt))) ?></strong></td><td class="r"><?= $r ? (int)$r['n'] : '<span class="muted">–</span>' ?></td><td class="r"><?= $r ? h(inr((float)$r['v'])) : '–' ?></td><td class="r"><?= $r && $r['rto'] ? (int)$r['rto'] : '' ?></td><td><span class="hbar__t"><i style="width:<?= $r ? round($r['n'] / $mx * 100) : 0 ?>%"></i></span></td></tr>
  <?php endfor; ?></tbody></table></div></section>

<div class="grid2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">
<?php
$mix = function (string $title, string $sql) use ($q, $ym, $pct, $M) {
    $rows = $q($sql, [$ym]); $mx = max(1, max(array_merge([1], array_map(fn($r) => (int)$r['n'], $rows))));
    echo '<section class="card"><div class="card__h"><h2>' . h($title) . '</h2></div><div class="hbars">';
    foreach ($rows as $r) { echo '<div class="hbar hbar--w"><span class="hbar__l">' . h($r['k'] !== '' ? $r['k'] : 'Not filled') . '</span><span class="hbar__t"><i style="width:' . round($r['n'] / $mx * 100) . '%"></i></span><span class="hbar__v">' . (int)$r['n'] . ' · ' . $pct((float)$r['n'], (float)$M['n']) . '%</span></div>'; }
    echo '</div></section>';
};
$mix('Payment type', "SELECT pay k, COUNT(*) n FROM kb_conf WHERE ym = ? GROUP BY pay ORDER BY n DESC");
$mix('Courier', "SELECT courier k, COUNT(*) n FROM kb_conf WHERE ym = ? GROUP BY courier ORDER BY n DESC");
$mix('Order status', "SELECT status k, COUNT(*) n FROM kb_conf WHERE ym = ? GROUP BY status ORDER BY n DESC");
?></div>

<?php $att = $q("SELECT * FROM kb_conf WHERE ym = ? AND (sc = 'rto' OR (sc IN ('tra','ofd') AND day > 0 AND day <= ?)) ORDER BY sc = 'rto' DESC, day, oid", [$ym, max(0, (int)substr($today, 8, 2) - 7)]);
$att = $isCur ? array_filter($att, fn($r) => $r['sc'] === 'rto' || (int)$r['day'] <= (int)substr($today, 8, 2) - 7) : array_filter($att, fn($r) => $r['sc'] === 'rto' || $r['sc'] === 'tra' || $r['sc'] === 'ofd'); ?>
<?php if ($att): ?><section class="card"><div class="card__h"><h2>Needs attention</h2><span class="muted tiny">RTO orders and parcels still on the way after 7 days</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Order</th><th>Day</th><th>Status</th><th>Courier</th><th>Payment</th><th class="r">Amount</th><th class="hm">Reason</th></tr></thead><tbody>
  <?php foreach (array_slice($att, 0, 40) as $r): ?><tr><td><strong>#<?= h($r['oid']) ?></strong></td><td><?= $r['day'] ? h(date('j M', strtotime($ym . '-' . sprintf('%02d', $r['day'])))) : '–' ?></td><td><?= $chip($r['sc'], $r['status']) ?></td><td><?= h($r['courier']) ?></td><td><?= h($r['pay']) ?></td><td class="r"><?= h(inr((float)$r['amt'])) ?></td><td class="hm tiny"><?= h($r['reason']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php endif; ?>

<section class="card"><div class="card__h"><h2>Month by month</h2><span class="muted tiny">every month in her order sheet</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Month</th><th class="r">Orders</th><th class="r">Value</th><th class="r">Avg order</th><th class="r">Delivered</th><th class="r">RTO %</th><th class="r">Cancelled</th><th class="r">Partial paid</th></tr></thead><tbody>
  <?php $mm = $q("SELECT ym FROM kb_conf GROUP BY ym ORDER BY ym DESC"); foreach ($mm as $r): $x = $sum($r['ym']); $cl = $x['del'] + $x['rto']; ?>
    <tr><td><strong><a href="<?= h(url('kbdash', ['t' => 'overview', 'month' => $r['ym']])) ?>"><?= h(date('M Y', strtotime($r['ym'] . '-01'))) ?></a></strong></td><td class="r"><?= (int)$x['n'] ?></td><td class="r"><?= h(inr_short($x['val'])) ?></td><td class="r"><?= $x['valid'] > 0 ? h(inr($x['val'] / $x['valid'])) : '–' ?></td><td class="r"><?= $pct($x['del'], $cl) ?>%</td><td class="r"><?= $pct($x['rto'], $cl) ?>%</td><td class="r"><?= (int)$x['can'] ?></td><td class="r"><?= $pct($x['part'], $x['n']) ?>%</td></tr>
  <?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'orders'):
  $sf = isset($SC[$_GET['s'] ?? '']) ? $_GET['s'] : 'all';
  $rows = $q('SELECT * FROM kb_conf WHERE ym = ?' . ($sf !== 'all' ? ' AND sc = ?' : '') . ' ORDER BY day DESC, oid DESC LIMIT 600', $sf !== 'all' ? [$ym, $sf] : [$ym]);
?>
<div class="pagehead__r" style="margin:0 0 12px;flex-wrap:wrap"><a class="btn btn--sm <?= $sf === 'all' ? '' : 'btn--ghost' ?>" href="<?= h(url('kbdash', ['t' => 'orders', 'month' => $ym])) ?>">All</a> <?php foreach ($SC as $sk => $sl): ?><a class="btn btn--sm <?= $sf === $sk ? '' : 'btn--ghost' ?>" href="<?= h(url('kbdash', ['t' => 'orders', 'month' => $ym, 's' => $sk])) ?>"><?= h($sl) ?></a> <?php endforeach; ?></div>
<section class="card"><div class="card__h"><h2><?= count($rows) ?> orders</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Day</th><th>Order</th><th>Status</th><th>Courier</th><th>Payment</th><th class="r">Amount</th><th class="hm">Reason</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= $r['day'] ? h(date('j M', strtotime($ym . '-' . sprintf('%02d', $r['day'])))) : '–' ?></td><td><strong>#<?= h($r['oid']) ?></strong></td><td><?= $chip($r['sc'], $r['status']) ?></td><td><?= h($r['courier']) ?></td><td><?= h($r['pay']) ?></td><td class="r"><?= h(inr((float)$r['amt'])) ?></td><td class="hm tiny"><?= h($r['reason']) ?></td></tr><?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">No orders.</td></tr><?php endif; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'offline'):
  $off = $q("SELECT base, MAX(party) party, MIN(day) day, COUNT(*) parcels, MAX(total) total, SUM(adv) adv, MAX(pay) pay,
        SUM(CASE WHEN lower(pstat) LIKE 'received%' THEN 0 ELSE cod END) pend, SUM(lower(pstat) LIKE 'received%') got FROM kb_off GROUP BY base ORDER BY day DESC, base DESC");
  $oc = $q("SELECT day, oid, total, dstatus, pay, track, courier FROM kb_on ORDER BY day DESC, oid DESC");
  $offTot = array_sum(array_column($off, 'total')); $offAdv = array_sum(array_column($off, 'adv')); $offPend = array_sum(array_column($off, 'pend'));
  $offM = array_sum(array_map(fn($r) => substr((string)$r['day'], 0, 7) === $curYm ? (float)$r['total'] : 0, $off));
  $onTot = array_sum(array_column($oc, 'total'));
?>
<div class="kpis kpis--4">
  <?= $kpi('Offline orders', (string)count($off), array_sum(array_column($off, 'parcels')) . ' parcels', 'bag') ?>
  <?= $kpi('Offline sale', inr_short($offTot), inr_short($offM) . ' this month', 'trend', 'kpi__ic--ok') ?>
  <?= $kpi('Advance received', inr_short($offAdv), $pct($offAdv, $offTot) . '% of sale', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('COD still to collect', inr_short($offPend), 'parcels not marked Received', 'target', $offPend > 0 ? 'kpi__ic--warn' : 'kpi__ic--ok') ?>
</div>
<section class="card"><div class="card__h"><h2>Offline sale</h2><span class="muted tiny">sheet tab "Khushboo Orders_Offline_Sale"</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Party</th><th>Date</th><th>Order</th><th class="r">Parcels</th><th>Payment</th><th class="r">Total</th><th class="r">Advance</th><th class="r">COD pending</th></tr></thead><tbody>
  <?php foreach ($off as $r): ?><tr><td><strong><?= h($r['party']) ?></strong></td><td><?= h($dayLbl((string)$r['day'])) ?></td><td><?= h($r['base']) ?></td><td class="r"><?= (int)$r['parcels'] ?><br><span class="muted tiny"><?= (int)$r['got'] ?> received</span></td><td><?= h($r['pay']) ?></td><td class="r"><strong><?= h(inr((float)$r['total'])) ?></strong></td><td class="r"><?= h(inr((float)$r['adv'])) ?></td><td class="r"><?= $r['pend'] > 0 ? '<strong style="color:var(--bad,#c0392b)">' . h(inr((float)$r['pend'])) . '</strong>' : '–' ?></td></tr><?php endforeach; if (!$off): ?><tr><td colspan="8" class="muted">No offline sales yet.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><div class="card__h"><h2>Online sale (her own sheet)</h2><span class="muted tiny"><?= count($oc) ?> orders · <?= h(inr($onTot)) ?></span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th>Order</th><th>Courier</th><th>Delivery</th><th>Payment</th><th class="r">Total</th></tr></thead><tbody>
  <?php foreach ($oc as $r): ?><tr><td><?= h($dayLbl((string)$r['day'])) ?></td><td><strong>#<?= h($r['oid']) ?></strong></td><td><?= h($r['courier']) ?></td><td><?= $dchip((string)$r['dstatus']) ?></td><td><?= h($r['pay']) ?></td><td class="r"><?= h(inr((float)$r['total'])) ?></td></tr><?php endforeach; if (!$oc): ?><tr><td colspan="6" class="muted">None yet.</td></tr><?php endif; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'b2b'):
  $b = $q("SELECT who, oid, party, day, track, status, pay, pend, total, contact, addr, courier FROM kb_b2b ORDER BY day DESC");
  $g = [];
  foreach ($b as $r) { $k = $r['who'] . '|' . preg_replace('/-\d+$/', '', $r['oid']); if (!isset($g[$k])) { $g[$k] = $r + ['parcels' => 0, 'tr' => []]; } if ($r['track'] !== '' && !in_array($r['track'], $g[$k]['tr'], true)) { $g[$k]['tr'][] = $r['track']; } $g[$k]['total'] = max($g[$k]['total'], $r['total']); $g[$k]['pend'] = max($g[$k]['pend'], $r['pend']); }
  $cod = $q('SELECT * FROM kb_cod ORDER BY rowid DESC');
  $bt = array_sum(array_column($g, 'total')); $bp = array_sum(array_column($g, 'pend')); $bd = count(array_filter($g, fn($r) => stripos($r['status'], 'deliver') === 0));
?>
<div class="kpis kpis--4">
  <?= $kpi('B2B orders', (string)count($g), 'Bhanu and Shivam sheets', 'bag') ?>
  <?= $kpi('B2B value', inr_short($bt), 'one count per order, not per parcel', 'trend', 'kpi__ic--ok') ?>
  <?= $kpi('Delivered', (string)$bd, $pct($bd, count($g)) . '% of orders', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('COD pending', inr_short($bp), 'balance to collect', 'target', $bp > 0 ? 'kpi__ic--warn' : 'kpi__ic--ok') ?>
</div>
<section class="card"><div class="card__h"><h2>B2B COD orders</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Sales person</th><th>Party</th><th>Date</th><th>Order</th><th class="r">Parcels</th><th>Status</th><th>Payment</th><th class="r">Total</th><th class="r">Pending</th></tr></thead><tbody>
  <?php foreach ($g as $r): ?><tr><td><?= h($r['who']) ?></td><td><strong><?= h($r['party']) ?></strong><?= $r['contact'] !== '' ? '<br><span class="muted tiny"><a href="tel:+91' . h(substr(preg_replace('/\D/', '', $r['contact']), -10)) . '">' . h($r['contact']) . '</a></span>' : '' ?></td><td><?= h($dayLbl((string)$r['day'])) ?></td><td><?= h(preg_replace('/-\d+$/', '', $r['oid'])) ?></td><td class="r"><?= max(1, count($r['tr'])) ?></td><td><?= $dchip((string)$r['status']) ?></td><td><?= h($r['pay']) ?></td><td class="r"><strong><?= h(inr((float)$r['total'])) ?></strong></td><td class="r"><?= $r['pend'] > 0 ? '<strong style="color:var(--bad,#c0392b)">' . h(inr((float)$r['pend'])) . '</strong>' : '–' ?></td></tr><?php endforeach; if (!$g): ?><tr><td colspan="9" class="muted">No B2B orders yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if ($cod): ?><section class="card"><div class="card__h"><h2>COD, all orders</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Customer</th><th>Sales person</th><th>Order</th><th>Sent</th><th>Status</th><th class="r">Advance</th><th class="r">COD</th><th class="r">Total</th></tr></thead><tbody>
  <?php foreach ($cod as $r): ?><tr><td><strong><?= h($r['cust']) ?></strong></td><td><?= h($r['sales']) ?></td><td><?= h($r['oid']) ?></td><td><?= h($r['sent']) ?></td><td><?= $dchip((string)$r['status']) ?></td><td class="r"><?= h(inr((float)$r['adv'])) ?></td><td class="r"><?= h(inr((float)$r['cod'])) ?></td><td class="r"><?= h(inr((float)$r['total'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>

<?php else:
  $sp = $q('SELECT * FROM kb_samp ORDER BY rowid DESC'); $by = [];
  foreach ($sp as $r) { $k = $r['sender'] !== '' ? $r['sender'] : 'Not noted'; $by[$k]['n'] = ($by[$k]['n'] ?? 0) + 1; $by[$k]['d'] = ($by[$k]['d'] ?? 0) + (stripos($r['status'], 'deliver') === 0 ? 1 : 0); $by[$k]['c'] = ($by[$k]['c'] ?? 0) + (float)$r['total']; }
  arsort($by); $rto = $q('SELECT * FROM kb_rto ORDER BY rowid DESC');
  $sd = count(array_filter($sp, fn($r) => stripos($r['status'], 'deliver') === 0));
?>
<div class="kpis kpis--4">
  <?= $kpi('Samples sent', (string)count($sp), 'party samples dispatched', 'bag') ?>
  <?= $kpi('Delivered', (string)$sd, $pct($sd, count($sp)) . '% of samples', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('Still on the way', (string)(count($sp) - $sd), 'in transit or not updated', 'users') ?>
  <?= $kpi('RTO / re-dispatch', (string)count($rto), 'orders sent again', 'target', count($rto) ? 'kpi__ic--warn' : '') ?>
</div>
<?php if ($by): ?><section class="card"><div class="card__h"><h2>Samples by sales person</h2><span class="muted tiny">from the Remark column</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Sales person</th><th class="r">Samples</th><th class="r">Delivered</th><th class="r">Shipping paid</th></tr></thead><tbody>
  <?php foreach ($by as $k => $x): ?><tr><td><strong><?= h($k) ?></strong></td><td class="r"><?= (int)$x['n'] ?></td><td class="r"><?= (int)$x['d'] ?></td><td class="r"><?= h(inr((float)$x['c'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<section class="card"><div class="card__h"><h2>Samples dispatch</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Party</th><th>Sales person</th><th>Courier</th><th>Status</th><th>Tracking</th><th class="hm">Address</th></tr></thead><tbody>
  <?php foreach ($sp as $r): ?><tr><td><strong><?= h($r['party']) ?></strong><?= $r['contact'] !== '' ? '<br><span class="muted tiny">' . h(mb_substr($r['contact'], 0, 14)) . '</span>' : '' ?></td><td><?= h($r['sender']) ?></td><td><?= h($r['courier']) ?></td><td><?= $dchip((string)$r['status']) ?></td><td class="tiny"><?= h($r['track']) ?></td><td class="hm tiny"><?= h(mb_substr($r['addr'], 0, 80)) ?></td></tr><?php endforeach; if (!$sp): ?><tr><td colspan="6" class="muted">None.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><div class="card__h"><h2>RTO and re-dispatch</h2><span class="muted tiny">tab "RTO&amp;Re-dispatch"</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th>Order</th><th>First tracking</th><th>Second tracking</th></tr></thead><tbody>
  <?php foreach ($rto as $r): ?><tr><td><?= h($r['day'] !== '' ? $r['day'] : '–') ?></td><td><strong>#<?= h($r['oid']) ?></strong></td><td class="tiny"><?= h($r['t1'] !== '' ? $r['t1'] : '–') ?></td><td class="tiny"><?= $r['t2'] !== '' ? h($r['t2']) : '<span class="muted">not re-sent yet</span>' ?></td></tr><?php endforeach; if (!$rto): ?><tr><td colspan="4" class="muted">None.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif;
layout_foot();
