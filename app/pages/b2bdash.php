<?php
defined('DD') or exit;
/* Bhanu credit offline: the distributors workbook. Included by offlinedash.php. Expects $pdo, $today, $tabNow. */
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); })();
$months = array_column($q("SELECT month m FROM b2b_order UNION SELECT substr(day,1,7) FROM b2b_coll ORDER BY 1 DESC"), 'm');
$curM = substr($today, 0, 7);
$m = (string)($_GET['month'] ?? ''); if ($m !== 'all' && !in_array($m, $months, true)) { $m = in_array($curM, $months, true) ? $curM : ($months[0] ?? $curM); }
$all = $m === 'all';
$mLabel = $all ? 'All months' : date('F Y', strtotime($m . '-01'));
$mw = $all ? '1=1' : "month = '$m'"; $cw = $all ? '1=1' : "substr(day,1,7) = '$m'";
$dist = $q('SELECT * FROM b2b_dist ORDER BY outstanding DESC, name');
$ord = []; foreach ($q("SELECT k, SUM(amount) s, COUNT(*) n FROM b2b_order WHERE $mw GROUP BY k") as $r) { $ord[$r['k']] = $r; }
$ordAll = []; foreach ($q('SELECT k, SUM(amount) s, MAX(month) last, MIN(month) first FROM b2b_order GROUP BY k') as $r) { $ordAll[$r['k']] = $r; }
$col = []; foreach ($q("SELECT k, SUM(amount) s, MAX(day) last FROM b2b_coll WHERE $cw GROUP BY k") as $r) { $col[$r['k']] = $r; }
$meta = []; foreach ($q('SELECT k, v FROM b2b_meta') as $r) { $meta[$r['k']] = $r['v']; }
$totOut = array_sum(array_column($dist, 'outstanding'));
$owing = array_values(array_filter($dist, fn($d) => $d['outstanding'] > 0));
$legal = array_values(array_filter($dist, fn($d) => preg_match('/MSME|Sec 138/', $d['flags'])));
$blocked = array_values(array_filter($dist, fn($d) => str_contains($d['flags'], 'Blocked')));
$legalOut = array_sum(array_column($legal, 'outstanding')); $blockedOut = array_sum(array_column($blocked, 'outstanding'));
$mOrders = array_sum(array_column($ord, 's')); $mColl = array_sum(array_column($col, 's'));
$newD = 0; if (!$all) { foreach ($ord as $k => $_) { if (($ordAll[$k]['first'] ?? '') === $m) { $newD++; } } }
$daysAgo = fn(string $d) => (int)floor((strtotime($today) - strtotime($d)) / 86400);
$dueList = array_values(array_filter($dist, fn($d) => $d['next_date'] !== '' && $d['next_date'] <= $today && $d['status'] !== 'Inactive'));
usort($dueList, fn($a, $b) => strcmp($a['next_date'], $b['next_date']));
$silent = array_values(array_filter($dist, fn($d) => ($d['outstanding'] > 0 || in_array($d['status'], ['Active', 'Interested'], true)) && $d['last_contact'] !== '' && $daysAgo($d['last_contact']) >= 30 && !str_contains($d['flags'], 'Blocked')));
usort($silent, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);
$tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
$remDist = array_values(array_filter($dist, fn($d) => $d['next_date'] !== '' && $d['next_date'] <= $tomorrow && $d['status'] !== 'Inactive' && !str_contains($d['flags'], 'Blocked')));
usort($remDist, fn($a, $b) => strcmp($a['next_date'], $b['next_date']));
$remLeads = $q("SELECT * FROM b2b_lead WHERE src IN ('calling','prospect') AND status IN ('No answer','Follow up') ORDER BY rowid");
foreach ($remLeads as &$rl) { $rl['tried'] = ''; if (preg_match('~(\d{1,2})/(\d{1,2})/(\d{2,4})~', $rl['remark'], $mm)) { $yy = (int)$mm[3] < 100 ? 2000 + (int)$mm[3] : (int)$mm[3]; if (checkdate((int)$mm[2], (int)$mm[1], $yy)) { $rl['tried'] = sprintf('%04d-%02d-%02d', $yy, $mm[2], $mm[1]); } } } unset($rl);
usort($remLeads, fn($a, $b) => [$a['status'] === 'Follow up' ? 0 : 1, $a['tried'] === '' ? 1 : 0, $a['tried']] <=> [$b['status'] === 'Follow up' ? 0 : 1, $b['tried'] === '' ? 1 : 0, $b['tried']]);
$remCount = count($remDist) + count($remLeads);
$pct = fn(float $v, float $t) => $t > 0 ? round($v / $t * 100) : 0;
$keep = $all ? ['month' => 'all'] : ['month' => $m];
$tabs = ['overview' => 'Overview', 'distributors' => 'Distributors', 'reminders' => 'Reminders (' . $remCount . ')', 'followups' => 'Follow-ups', 'legal' => 'Legal and blocked', 'pipeline' => 'Pipeline', 'invoices' => 'Invoices (CSV)'];
$flagChips = fn(string $f) => implode(' ', array_map(fn($x) => '<span class="chip ' . ($x === 'Blocked' ? 'chip--bad' : 'chip--warn') . '">' . h($x) . '</span>', array_filter(explode(',', $f))));
$kpi = fn(string $l, string $v, string $s = '', string $ic = 'trend', string $cls = '') => '<div class="kpi"><div class="kpi__top"><span class="kpi__l">' . h($l) . '</span><span class="kpi__ic ' . $cls . '">' . icon($ic) . '</span></div><strong class="kpi__v">' . $v . '</strong><span class="kpi__s">' . $s . '</span></div>';
$nameCell = fn(array $d) => '<strong>' . h($d['name']) . '</strong>' . ($d['flags'] !== '' ? ' ' . $flagChips($d['flags']) : '');

layout_head('Bhanu credit offline');
?>
<div class="pagehead">
  <div><h1>Bhanu credit offline</h1><p class="muted">Distributors on credit: orders, collections, outstanding and follow-ups · <?= h($mLabel) ?><?= isset($meta['synced_at']) ? ' · sheet read ' . h(fmt_date(substr($meta['synced_at'], 0, 10))) . ' ' . h(substr($meta['synced_at'], 11, 5)) : '' ?></p></div>
  <div class="pagehead__r"><?= $tabNav('offline') ?> <?= $tabNav('bhanu') ?>
    <form method="get" class="inline"><input type="hidden" name="p" value="offlinedash"><input type="hidden" name="v" value="bhanu"><input type="hidden" name="t" value="<?= h($tabNow) ?>">
      <select name="month" onchange="this.form.submit()" aria-label="Month"><option value="all" <?= $all ? 'selected' : '' ?>>All months</option><?php foreach ($months as $mo): ?><option value="<?= h($mo) ?>" <?= $mo === $m ? 'selected' : '' ?>><?= h(date('F Y', strtotime($mo . '-01'))) ?></option><?php endforeach; ?></select></form></div>
</div>
<div class="pagehead__r" style="margin:-4px 0 14px;flex-wrap:wrap"><?php foreach ($tabs as $tk => $tl): ?><a class="btn btn--sm <?= $tk === $tabNow ? '' : 'btn--ghost' ?>" href="<?= h(url('offlinedash', ['v' => 'bhanu', 't' => $tk] + $keep)) ?>"><?= h($tl) ?></a> <?php endforeach; ?></div>

<?php if ($tabNow === 'overview'): ?>
<div class="kpis kpis--4">
  <?= $kpi('Outstanding (all time)', h(inr_short($totOut)), count($owing) . ' distributors owe', 'target', 'kpi__ic--warn') ?>
  <?= $kpi('Orders · ' . $mLabel, h(inr_short($mOrders)), count($ord) . ' distributors ordered' . (!$all ? ', ' . $newD . ' new' : ''), 'trend', 'kpi__ic--ok') ?>
  <?= $kpi('Collected · ' . $mLabel, h(inr_short($mColl)), $pct($mColl, $mOrders) . '% of the month\'s orders', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('In legal cases', h(inr_short($legalOut)), count($legal) . ' parties (MSME or Sec 138)', 'target', 'kpi__ic--warn') ?>
  <?= $kpi('Blocked parties', (string)count($blocked), 'Outstanding with them: ' . h(inr_short($blockedOut)), 'users', 'kpi__ic--warn') ?>
  <?= $kpi('Follow-ups due', (string)count($dueList), 'next follow-up date is today or earlier', 'target') ?>
  <?= $kpi('Not contacted in 30+ days', (string)count($silent), 'active or owing distributors', 'users') ?>
  <?= $kpi('New prospects', (string)($meta['prospects'] ?? 0), ($meta['hot'] ?? 0) . ' hot, ' . ($meta['samples'] ?? 0) . ' samples to send', 'users') ?>
</div>

<section class="card"><div class="card__h"><h2>Month by month</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Month</th><th class="r">Orders</th><th class="r">Collected</th><th class="r">Collected vs orders</th><th class="r">Distributors ordered</th><th class="r">New distributors</th></tr></thead><tbody>
  <?php foreach ($months as $mo):
    $o = $q('SELECT SUM(amount) s, COUNT(DISTINCT k) n FROM b2b_order WHERE month = ?', [$mo])[0]; $c = $q("SELECT SUM(amount) s FROM b2b_coll WHERE substr(day,1,7) = ?", [$mo])[0];
    $nw = 0; foreach ($q('SELECT DISTINCT k FROM b2b_order WHERE month = ?', [$mo]) as $r) { if (($ordAll[$r['k']]['first'] ?? '') === $mo) { $nw++; } } ?>
    <tr><td><strong><?= h(date('F Y', strtotime($mo . '-01'))) ?></strong></td><td class="r"><?= h(inr((float)$o['s'])) ?></td><td class="r"><?= h(inr((float)$c['s'])) ?></td><td class="r"><?= $pct((float)$c['s'], (float)$o['s']) ?>%</td><td class="r"><?= (int)$o['n'] ?></td><td class="r"><?= $nw ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted tiny" style="margin-top:8px">Orders come from the "Order Sheet" tab (by month), collections from "Monthly B2B Performance" (by date). "New" means the first month a distributor appears in the order sheet. The Sales overview counts these order values as "Bhanu credit offline"; the sheet has no order dates, so a month's value is spread evenly over its days. Bhanu Verma in the main sale sheet is the Inside Sales Bhanu and is a separate line.</p></section>

<section class="card"><div class="card__h"><h2>Biggest outstanding</h2><strong><?= h(inr($totOut)) ?></strong></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Distributor</th><th class="r">Outstanding</th><th class="r">Share</th><th class="r hm">Last contact</th><th class="hm">Status</th></tr></thead><tbody>
  <?php foreach (array_slice($owing, 0, 12) as $d): ?><tr><td><?= $nameCell($d) ?></td><td class="r"><strong><?= h(inr((float)$d['outstanding'])) ?></strong></td><td class="r"><?= $pct((float)$d['outstanding'], $totOut) ?>%</td><td class="r hm"><?= $d['last_contact'] ? h(fmt_date($d['last_contact'])) . ' <span class="muted tiny">(' . $daysAgo($d['last_contact']) . ' d)</span>' : '–' ?></td><td class="hm"><?= h($d['status'] ?: '–') ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php $top = $dist; usort($top, fn($a, $b) => ($ord[$b['k']]['s'] ?? 0) <=> ($ord[$a['k']]['s'] ?? 0)); $top = array_values(array_filter($top, fn($d) => isset($ord[$d['k']]))); ?>
<section class="card"><div class="card__h"><h2>Top orders · <?= h($mLabel) ?></h2><strong><?= h(inr($mOrders)) ?></strong></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Distributor</th><th class="r">Orders</th><th class="r">Order value</th><th class="r">Collected</th><th class="r">Outstanding</th></tr></thead><tbody>
  <?php foreach (array_slice($top, 0, 10) as $d): ?><tr><td><?= $nameCell($d) ?></td><td class="r"><?= (int)$ord[$d['k']]['n'] ?></td><td class="r"><strong><?= h(inr((float)$ord[$d['k']]['s'])) ?></strong></td><td class="r"><?= h(inr((float)($col[$d['k']]['s'] ?? 0))) ?></td><td class="r"><?= h(inr((float)$d['outstanding'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php $rc = $q("SELECT c.day, c.amount, c.raw, d.name FROM b2b_coll c LEFT JOIN b2b_dist d ON d.k = c.k WHERE $cw ORDER BY c.day DESC, c.amount DESC LIMIT 12"); ?>
<section class="card"><div class="card__h"><h2>Recent collections</h2><strong><?= h(inr($mColl)) ?></strong></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th>Distributor</th><th class="r">Amount</th></tr></thead><tbody>
  <?php foreach ($rc as $r): ?><tr><td><?= h(fmt_date($r['day'])) ?></td><td><?= h($r['name'] ?: $r['raw']) ?></td><td class="r"><?= h(inr((float)$r['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'distributors'):
  $f = (string)($_GET['f'] ?? 'all'); $qs = trim((string)($_GET['q'] ?? ''));
  $list = array_values(array_filter($dist, function ($d) use ($f, $qs, $ord, $col) {
    if ($qs !== '' && stripos($d['name'], $qs) === false) { return false; }
    return match ($f) { 'owing' => $d['outstanding'] > 0, 'ordered' => isset($ord[$d['k']]), 'legal' => (bool)preg_match('/MSME|Sec 138|Blocked/', $d['flags']), 'inactive' => $d['status'] === 'Inactive', default => true };
  })); ?>
<section class="card"><div class="card__h"><h2>All distributors</h2><span class="muted tiny"><?= count($list) ?> of <?= count($dist) ?></span></div>
  <form method="get" class="formrow"><input type="hidden" name="p" value="offlinedash"><input type="hidden" name="v" value="bhanu"><input type="hidden" name="t" value="distributors"><input type="hidden" name="month" value="<?= h($all ? 'all' : $m) ?>">
    <label class="field grow"><span>Search</span><input name="q" value="<?= h($qs) ?>" placeholder="Distributor name"></label>
    <label class="field"><span>Show</span><select name="f" onchange="this.form.submit()"><?php foreach (['all' => 'Everyone', 'owing' => 'Owing money', 'ordered' => 'Ordered in ' . $mLabel, 'legal' => 'Legal or blocked', 'inactive' => 'Inactive'] as $fv => $fl): ?><option value="<?= h($fv) ?>" <?= $f === $fv ? 'selected' : '' ?>><?= h($fl) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn--ghost" type="submit">Search</button></form>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Distributor</th><th class="r">Outstanding</th><th class="r">Orders · <?= h($mLabel) ?></th><th class="r">Collected · <?= h($mLabel) ?></th><th class="hm">Last order</th><th class="hm">Status</th><th class="hm">Last contact</th><th class="hm">Contact</th></tr></thead><tbody>
  <?php foreach ($list as $d): ?><tr><td><?= $nameCell($d) ?></td><td class="r"><?= $d['outstanding'] > 0 ? '<strong>' . h(inr((float)$d['outstanding'])) . '</strong>' : '–' ?></td><td class="r"><?= isset($ord[$d['k']]) ? h(inr((float)$ord[$d['k']]['s'])) : '–' ?></td><td class="r"><?= isset($col[$d['k']]) ? h(inr((float)$col[$d['k']]['s'])) : '–' ?></td>
    <td class="hm"><?= isset($ordAll[$d['k']]) ? h(date('M Y', strtotime($ordAll[$d['k']]['last'] . '-01'))) : '–' ?></td><td class="hm"><?= h($d['status'] ?: '–') ?></td><td class="hm"><?= $d['last_contact'] ? h(fmt_date($d['last_contact'])) : '–' ?></td><td class="hm"><span class="tiny"><?= h(trim($d['contact'] . ' ' . $d['phone'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'reminders'): ?>
<div class="kpis kpis--4"><?= $kpi('Distributor follow-ups', (string)count($remDist), 'due today, overdue or tomorrow', 'target') ?><?= $kpi('Call again: no response', (string)count(array_filter($remLeads, fn($l) => $l['status'] === 'No answer')), 'calling list and prospects', 'users') ?><?= $kpi('Promised callbacks', (string)count(array_filter($remLeads, fn($l) => $l['status'] === 'Follow up')), '"talk later / may be prospect"', 'users') ?><?= $kpi('Tomorrow', (string)count(array_filter($remDist, fn($d) => $d['next_date'] === $tomorrow)), 'distributor calls dated tomorrow', 'target') ?></div>
<section class="card"><div class="card__h"><h2>Distributor follow-ups and queries</h2><span class="muted tiny"><?= count($remDist) ?> to call, from the Follow-up Tracker</span></div>
  <?php if (!$remDist): ?><p class="muted">No dated follow-ups are due today or tomorrow.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Distributor</th><th>When</th><th class="r">Outstanding</th><th>Query / requirement</th><th class="hm">Next step</th></tr></thead><tbody>
  <?php foreach ($remDist as $d): $ago = $daysAgo($d['next_date']); ?><tr><td><?= $nameCell($d) ?><br><span class="muted tiny"><?= h(trim($d['contact'])) ?> <?= $d['phone'] !== '' ? '<a href="tel:' . h($d['phone']) . '">' . h($d['phone']) . '</a>' : '' ?></span></td>
    <td><?= $d['next_date'] === $tomorrow ? '<span class="chip">Tomorrow</span>' : ($ago <= 0 ? '<span class="chip chip--warn">Today</span>' : '<span class="chip chip--bad">' . $ago . ' d overdue</span>') ?><br><span class="muted tiny"><?= h(fmt_date($d['next_date'])) ?></span></td>
    <td class="r"><?= $d['outstanding'] > 0 ? h(inr((float)$d['outstanding'])) : '–' ?></td><td><?= h(mb_substr(trim($d['purpose'] . ' ' . $d['remark']), 0, 160)) ?></td><td class="hm"><?= h(mb_substr($d['next_text'], 0, 80)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="card"><div class="card__h"><h2>Call again: no response and promised callbacks</h2><span class="muted tiny"><?= count($remLeads) ?> leads, callbacks first, then oldest attempt</span></div>
  <?php if (!$remLeads): ?><p class="muted">No leads waiting for a call-back.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Name</th><th>City</th><th>Phone</th><th>Status</th><th>Last remark</th><th class="hm">Last tried</th></tr></thead><tbody>
  <?php foreach (array_slice($remLeads, 0, 300) as $l): ?><tr><td><strong><?= h($l['name']) ?></strong><br><span class="muted tiny"><?= $l['src'] === 'calling' ? 'Calling list' : 'New prospect' ?></span></td><td><?= h($l['area']) ?></td><td><?= $l['phone'] !== '' ? '<a href="tel:' . h($l['phone']) . '">' . h($l['phone']) . '</a>' : '–' ?></td>
    <td><span class="chip <?= $l['status'] === 'Follow up' ? '' : 'chip--warn' ?>"><?= $l['status'] === 'Follow up' ? 'Call back' : 'No response' ?></span></td><td><?= h($l['remark']) ?></td><td class="hm"><?= $l['tried'] !== '' ? h(fmt_date($l['tried'])) . ' <span class="muted tiny">(' . $daysAgo($l['tried']) . ' d)</span>' : '–' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<?php elseif ($tabNow === 'followups'):
  $sc = []; foreach ($dist as $d) { if ($d['last_contact'] !== '' || $d['status'] !== '') { $sc[$d['status'] ?: 'Not marked'] = ($sc[$d['status'] ?: 'Not marked'] ?? 0) + 1; } } arsort($sc); ?>
<div class="kpis kpis--4"><?php foreach (array_slice($sc, 0, 4, true) as $sn => $cn): ?><?= $kpi($sn, (string)$cn, 'distributors in the follow-up tracker', 'users') ?><?php endforeach; ?></div>
<section class="card"><div class="card__h"><h2>Follow-ups due</h2><span class="muted tiny"><?= count($dueList) ?> distributors</span></div>
  <?php if (!$dueList): ?><p class="muted">No dated follow-ups are due. Many follow-ups in the sheet are written as text (for example "2 weeks later") and cannot be dated.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Distributor</th><th>Due</th><th class="r">Outstanding</th><th class="hm">Purpose</th><th class="hm">Next step</th></tr></thead><tbody>
  <?php foreach (array_slice($dueList, 0, 40) as $d): ?><tr><td><?= $nameCell($d) ?><br><span class="muted tiny"><?= h(trim($d['contact'] . ' ' . $d['phone'])) ?></span></td><td><?= h(fmt_date($d['next_date'])) ?> <span class="muted tiny">(<?= $daysAgo($d['next_date']) ?> d ago)</span></td><td class="r"><?= $d['outstanding'] > 0 ? h(inr((float)$d['outstanding'])) : '–' ?></td><td class="hm"><?= h(mb_substr($d['purpose'], 0, 60)) ?></td><td class="hm"><?= h(mb_substr($d['remark'], 0, 90)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="card"><div class="card__h"><h2>Not contacted in 30+ days</h2><span class="muted tiny">owing or active, biggest balance first</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Distributor</th><th class="r">Outstanding</th><th>Last contact</th><th class="hm">Status</th><th class="hm">Phone</th></tr></thead><tbody>
  <?php foreach (array_slice($silent, 0, 40) as $d): ?><tr><td><?= $nameCell($d) ?></td><td class="r"><?= $d['outstanding'] > 0 ? '<strong>' . h(inr((float)$d['outstanding'])) . '</strong>' : '–' ?></td><td><?= h(fmt_date($d['last_contact'])) ?> <span class="muted tiny">(<?= $daysAgo($d['last_contact']) ?> d)</span></td><td class="hm"><?= h($d['status'] ?: '–') ?></td><td class="hm"><?= h($d['phone']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'legal'): ?>
<div class="kpis kpis--4"><?= $kpi('Legal cases', (string)count($legal), 'MSME or Sec 138', 'target', 'kpi__ic--warn') ?><?= $kpi('Outstanding in cases', h(inr_short($legalOut)), $pct($legalOut, $totOut) . '% of all outstanding', 'target', 'kpi__ic--warn') ?><?= $kpi('Blocked', (string)count($blocked), 'no more orders', 'users', 'kpi__ic--warn') ?><?= $kpi('Outstanding, blocked', h(inr_short($blockedOut)), '', 'target', 'kpi__ic--warn') ?></div>
<section class="card"><div class="card__h"><h2>Cases</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Party</th><th>Case</th><th>Note</th><th class="r">Outstanding</th></tr></thead><tbody>
  <?php usort($legal, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']); foreach ($legal as $d): ?><tr><td><strong><?= h($d['name']) ?></strong></td><td><?= $flagChips($d['flags']) ?></td><td class="muted tiny"><?= h($d['case_note']) ?></td><td class="r"><?= $d['outstanding'] > 0 ? h(inr((float)$d['outstanding'])) : '–' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><div class="card__h"><h2>Blocked parties</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Party</th><th class="r">Outstanding</th></tr></thead><tbody>
  <?php foreach ($blocked as $d): ?><tr><td><strong><?= h($d['name']) ?></strong></td><td class="r"><?= $d['outstanding'] > 0 ? h(inr((float)$d['outstanding'])) : '–' ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'pipeline'):
  $srcs = ['calling' => 'Calling list', 'prospect' => 'New prospects', 'denzuu' => 'Denzuu leads', 'sample' => 'Samples to send'];
  $src = isset($srcs[$_GET['s'] ?? '']) ? $_GET['s'] : 'calling'; $stf = (string)($_GET['st'] ?? ''); $qs = trim((string)($_GET['q'] ?? ''));
  $cnt = []; foreach ($q('SELECT src, COUNT(*) n FROM b2b_lead GROUP BY src') as $r) { $cnt[$r['src']] = (int)$r['n']; }
  $stc = []; foreach ($q('SELECT status, COUNT(*) n FROM b2b_lead WHERE src = ? GROUP BY status ORDER BY n DESC', [$src]) as $r) { $stc[$r['status']] = (int)$r['n']; }
  $sql = 'SELECT * FROM b2b_lead WHERE src = ?'; $args = [$src];
  if ($stf !== '' && isset($stc[$stf])) { $sql .= ' AND status = ?'; $args[] = $stf; }
  if ($qs !== '') { $sql .= ' AND (name LIKE ? OR area LIKE ? OR phone LIKE ? OR remark LIKE ?)'; array_push($args, "%$qs%", "%$qs%", "%$qs%", "%$qs%"); }
  $leadRows = $q($sql . ' ORDER BY rowid', $args);
  $pk = ['v' => 'bhanu', 't' => 'pipeline'] + $keep;
  $stCls = fn($s2) => in_array($s2, ['Interested', 'Follow up', 'Sample'], true) ? '' : (in_array($s2, ['Not interested', 'Blocked', 'Existing', 'Invalid number', 'Company outlet'], true) ? 'chip--bad' : 'chip--warn'); ?>
<div class="pagehead__r" style="margin:0 0 12px;flex-wrap:wrap"><?php foreach ($srcs as $sk => $sl): ?><a class="btn btn--sm <?= $sk === $src ? '' : 'btn--ghost' ?>" href="<?= h(url('offlinedash', $pk + ['s' => $sk])) ?>"><?= h($sl) ?> (<?= (int)($cnt[$sk] ?? 0) ?>)</a> <?php endforeach; ?></div>
<section class="card"><div class="card__h"><h2><?= h($srcs[$src]) ?></h2><span class="muted tiny"><?= count($leadRows) ?> shown</span></div>
  <?php if ($stc): ?><div style="margin-bottom:10px"><a class="chip <?= $stf === '' ? '' : 'chip--warn' ?>" href="<?= h(url('offlinedash', $pk + ['s' => $src])) ?>">All <?= array_sum($stc) ?></a> <?php foreach ($stc as $sn => $cn): ?><a class="chip <?= $stf === $sn ? '' : 'chip--warn' ?>" href="<?= h(url('offlinedash', $pk + ['s' => $src, 'st' => $sn])) ?>"><?= h($sn) ?> <?= $cn ?></a> <?php endforeach; ?></div><?php endif; ?>
  <form method="get" class="formrow"><input type="hidden" name="p" value="offlinedash"><input type="hidden" name="v" value="bhanu"><input type="hidden" name="t" value="pipeline"><input type="hidden" name="s" value="<?= h($src) ?>"><input type="hidden" name="st" value="<?= h($stf) ?>"><input type="hidden" name="month" value="<?= h($all ? 'all' : $m) ?>">
    <label class="field grow"><span>Search</span><input name="q" value="<?= h($qs) ?>" placeholder="Name, city, phone or remark"></label><button class="btn btn--ghost" type="submit">Search</button></form>
  <?php if (!$leadRows): ?><p class="muted">Nothing here yet. The list is read from the sheet (tabs New DaTa, New Prospect, denzu lead, Samples).</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Name</th><th>City</th><th>Phone</th><?php if ($src !== 'denzuu'): ?><th>Call status</th><th>Remark</th><?php endif; ?><th class="hm">Address</th></tr></thead><tbody>
  <?php foreach (array_slice($leadRows, 0, 400) as $l): ?><tr><td><strong><?= h($l['name']) ?></strong><?= $l['contact'] !== '' ? '<br><span class="muted tiny">' . h($l['contact']) . '</span>' : '' ?></td><td><?= h($l['area']) ?></td><td><?= $l['phone'] !== '' ? '<a href="tel:' . h($l['phone']) . '">' . h($l['phone']) . '</a>' : '–' ?></td>
    <?php if ($src !== 'denzuu'): ?><td><span class="chip <?= $stCls($l['status']) ?>"><?= h($l['status']) ?></span></td><td><?= h($l['remark']) ?></td><?php endif; ?><td class="hm"><span class="tiny"><?= h($l['address']) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
  <?php if (count($leadRows) > 400): ?><p class="muted tiny">Showing the first 400. Use search to narrow down.</p><?php endif; ?><?php endif; ?></section>
<?php endif; ?>
<?php layout_foot();
