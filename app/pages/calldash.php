<?php
defined('DD') or exit;
/* Calling tracker: the offline team's daily call sheets (one per caller). Reminders = no answer, switched off, call-back dates, interested leads. */
$pdo = db(); $today = today(); $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); })();
$people = array_column($q('SELECT person, COUNT(*) n FROM ct_call GROUP BY person ORDER BY person'), 'person');
$who = (string)($_GET['who'] ?? 'all'); if ($who !== 'all' && !in_array($who, $people, true)) { $who = 'all'; }
$tabs = ['overview' => 'Overview', 'reminders' => 'Reminders', 'leads' => 'Leads', 'calls' => 'All calls', 'queue' => 'Still to call'];
$tabNow = isset($tabs[$_GET['t'] ?? '']) ? $_GET['t'] : 'overview';
$keep = ['who' => $who];
$pw = $who === 'all' ? '1=1' : 'c.person = ' . $pdo->quote($who);
$pwp = $who === 'all' ? '1=1' : 'person = ' . $pdo->quote($who);
$pct = fn(float $v, float $t) => $t > 0 ? round($v / $t * 100) : 0;
$kpi = fn(string $l, string $v, string $s = '', string $ic = 'trend', string $cls = '') => '<div class="kpi"><div class="kpi__top"><span class="kpi__l">' . h($l) . '</span><span class="kpi__ic ' . $cls . '">' . icon($ic) . '</span></div><strong class="kpi__v">' . $v . '</strong><span class="kpi__s">' . $s . '</span></div>';
$cur = ct_cur_sql();
$CAT = CT_CATS;
$chip = fn(string $c) => '<span class="chip ' . (in_array($c, ['noanswer', 'off', 'wrong', 'notint'], true) ? 'chip--warn' : '') . '">' . h($CAT[$c] ?? $c) . '</span>';
$tel = fn(string $p) => '<a href="tel:+91' . h($p) . '">' . h($p) . '</a>';
$dueLbl = function (string $d) use ($today, $tomorrow): string {
    if ($d === '') { return '–'; }
    if ($d < $today) { $n = (int)floor((strtotime($today) - strtotime($d)) / 86400); return '<strong style="color:var(--bad,#c0392b)">Overdue ' . $n . 'd</strong> <span class="muted tiny">' . h(fmt_date($d)) . '</span>'; }
    if ($d === $today) { return '<strong>Today</strong>'; }
    if ($d === $tomorrow) { return 'Tomorrow'; }
    return h(fmt_date($d));
};
$meta = $q("SELECT label, last_run, last_ok, last_msg FROM gsync_links WHERE kind = 'calltrack' ORDER BY id");
$latest = ''; foreach ($meta as $m) { if ($m['last_ok'] && $m['last_run'] > $latest) { $latest = $m['last_run']; } }

layout_head('Calling tracker');
?>
<div class="pagehead">
  <div><h1>Calling tracker</h1><p class="muted">Offline team daily calls: Tanya, Bivakar, Shabik, Shajadi and Ankit<?= $latest !== '' ? ' · sheets read ' . h(fmt_date(substr($latest, 0, 10))) . ' ' . h(substr($latest, 11, 5)) : '' ?></p></div>
  <div class="pagehead__r"><?php foreach (array_merge(['all'], $people) as $pp): ?><a class="btn btn--sm <?= $pp === $who ? '' : 'btn--ghost' ?>" href="<?= h(url('calldash', ['t' => $tabNow, 'who' => $pp])) ?>"><?= $pp === 'all' ? 'Whole team' : h($pp) ?></a> <?php endforeach; ?></div>
</div>
<?php if (!$people): ?>
<section class="card"><p class="muted">No calls read yet. The calling sheets are linked under Admin, Google Sheets ("Calling · Tanya", "Calling · Bivakar", "Calling · Shabik" and so on) and are read every 5 minutes. Press Sync now there to read them immediately. Each sheet must be shared as "Anyone with the link: Viewer".</p>
<?php foreach ($meta as $m): if (!$m['last_ok'] && $m['last_msg'] !== ''): ?><p class="tiny"><?= h($m['label']) ?>: <?= h($m['last_msg']) ?></p><?php endif; endforeach; ?></section>
<?php layout_foot(); return; endif;
foreach ($meta as $m): if (!$m['last_ok'] && $m['last_msg'] !== ''): ?><p class="tiny" style="color:var(--bad,#c0392b)"><?= h($m['label']) ?>: <?= h($m['last_msg']) ?></p><?php endif; endforeach;

$CUR = $q("SELECT * FROM ($cur) c WHERE $pw");
$remRows = array_values(array_filter($CUR, fn($r) => $r['due'] !== '' && in_array($r['cat'], ['noanswer', 'off', 'callback', 'hot', 'connected'], true)));
usort($remRows, fn($a, $b) => [$a['due'], $a['person'], $a['name']] <=> [$b['due'], $b['person'], $b['name']]);
$overdue = array_filter($remRows, fn($r) => $r['due'] < $today); $dueToday = array_filter($remRows, fn($r) => $r['due'] === $today); $dueTom = array_filter($remRows, fn($r) => $r['due'] === $tomorrow);
$nRem = count($remRows);
?>
<div class="pagehead__r" style="margin:-4px 0 14px;flex-wrap:wrap"><?php foreach ($tabs as $tk => $tl): ?><a class="btn btn--sm <?= $tk === $tabNow ? '' : 'btn--ghost' ?>" href="<?= h(url('calldash', ['t' => $tk] + $keep)) ?>"><?= h($tl) ?><?= $tk === 'reminders' ? ' (' . $nRem . ')' : '' ?></a> <?php endforeach; ?></div>

<?php
$C = []; foreach ($q("SELECT cat, COUNT(*) n FROM ct_call WHERE $pwp GROUP BY cat") as $r) { $C[$r['cat']] = (int)$r['n']; }
$tot = fn(array $c) => array_sum($c);
$dialled = $tot($C) - ($C['todo'] ?? 0);
$answered = ($C['deal'] ?? 0) + ($C['hot'] ?? 0) + ($C['connected'] ?? 0) + ($C['callback'] ?? 0) + ($C['notint'] ?? 0) + ($C['wrong'] ?? 0);
$positive = ($C['deal'] ?? 0) + ($C['hot'] ?? 0) + ($C['connected'] ?? 0);
$uniq = (int)$q("SELECT COUNT(DISTINCT phone) n FROM ct_call WHERE $pwp AND cat != 'todo'")[0]['n'];
$queue = (int)$q("SELECT COUNT(*) n FROM ($cur) c WHERE $pw AND c.cat = 'todo'")[0]['n'];
?>

<?php if ($tabNow === 'overview'): ?>
<div class="kpis kpis--4">
  <?= $kpi('Calls made', (string)$dialled, $uniq . ' different numbers', 'trend') ?>
  <?= $kpi('Picked up', (string)$answered, $pct($answered, $dialled) . '% of calls', 'check', 'kpi__ic--ok') ?>
  <?= $kpi('Interested / details shared', (string)$positive, $pct($positive, $answered) . '% of those who picked up', 'users', 'kpi__ic--ok') ?>
  <?= $kpi('Orders / 100%', (string)($C['deal'] ?? 0), 'deal closed or order placed', 'bag', 'kpi__ic--ok') ?>
  <?= $kpi('Reminders due', (string)(count($overdue) + count($dueToday)), count($overdue) . ' overdue · ' . count($dueToday) . ' today · ' . count($dueTom) . ' tomorrow', 'target', (count($overdue) ? 'kpi__ic--warn' : '')) ?>
  <?= $kpi('No answer / switched off', (string)(($C['noanswer'] ?? 0) + ($C['off'] ?? 0)), 'try again on the next day', 'target', 'kpi__ic--warn') ?>
  <?= $kpi('Not interested', (string)(($C['notint'] ?? 0) + ($C['wrong'] ?? 0)), ($C['wrong'] ?? 0) . ' wrong numbers', 'target') ?>
  <?= $kpi('Still to call', (string)$queue, 'numbers in the sheet with no call yet', 'users') ?>
</div>

<?php if ($who === 'all'): ?>
<section class="card"><div class="card__h"><h2>Team</h2><span class="muted tiny">every call in the sheets</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Caller</th><th class="r">Calls</th><th class="r">Picked up</th><th class="r">Pick-up %</th><th class="r">Interested</th><th class="r">Orders</th><th class="r">No answer</th><th class="r">Call back</th><th class="r">Still to call</th><th class="r">Reminders</th></tr></thead><tbody>
  <?php foreach ($people as $pp):
    $pc = []; foreach ($q('SELECT cat, COUNT(*) n FROM ct_call WHERE person = ? GROUP BY cat', [$pp]) as $r) { $pc[$r['cat']] = (int)$r['n']; }
    $pd = array_sum($pc) - ($pc['todo'] ?? 0);
    $pa = ($pc['deal'] ?? 0) + ($pc['hot'] ?? 0) + ($pc['connected'] ?? 0) + ($pc['callback'] ?? 0) + ($pc['notint'] ?? 0) + ($pc['wrong'] ?? 0);
    $pr = count(array_filter($remRows, fn($r) => $r['person'] === $pp)); $pq = count(array_filter($CUR, fn($r) => $r['person'] === $pp && $r['cat'] === 'todo'));
    $dueN = count(array_filter($remRows, fn($r) => $r['person'] === $pp && $r['due'] <= $today)); ?>
    <tr><td><strong><a href="<?= h(url('calldash', ['t' => 'overview', 'who' => $pp])) ?>"><?= h($pp) ?></a></strong></td><td class="r"><?= $pd ?></td><td class="r"><?= $pa ?></td><td class="r"><?= $pct($pa, $pd) ?>%</td><td class="r"><?= ($pc['hot'] ?? 0) + ($pc['connected'] ?? 0) ?></td><td class="r"><?= $pc['deal'] ?? 0 ?></td><td class="r"><?= ($pc['noanswer'] ?? 0) + ($pc['off'] ?? 0) ?></td><td class="r"><?= $pc['callback'] ?? 0 ?></td><td class="r"><?= $pq ?></td><td class="r"><a href="<?= h(url('calldash', ['t' => 'reminders', 'who' => $pp])) ?>"><?= $pr ?></a><?= $dueN ? ' <span class="muted tiny">(' . $dueN . ' due)</span>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<section class="card"><div class="card__h"><h2>Day by day</h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><?php if ($who === 'all'): ?><th>Caller</th><?php endif; ?><th>Day</th><th class="r">Calls</th><th class="r">Picked up</th><th class="r">Pick-up %</th><th class="r">Interested</th><th class="r">Orders</th><th class="r">No answer</th><th class="r">Switched off</th><th class="r">Call back</th></tr></thead><tbody>
  <?php foreach ($q("SELECT person, day,
        SUM(cat != 'todo') calls, SUM(cat IN ('deal','hot','connected','callback','notint','wrong')) ans, SUM(cat IN ('hot','connected')) hot, SUM(cat = 'deal') deal,
        SUM(cat = 'noanswer') na, SUM(cat = 'off') off, SUM(cat = 'callback') cb FROM ct_call WHERE $pwp GROUP BY person, day HAVING calls > 0 ORDER BY day DESC, person") as $r): ?>
    <tr><?php if ($who === 'all'): ?><td><?= h($r['person']) ?></td><?php endif; ?><td><strong><?= h(fmt_date($r['day'])) ?></strong></td><td class="r"><?= (int)$r['calls'] ?></td><td class="r"><?= (int)$r['ans'] ?></td><td class="r"><?= $pct((float)$r['ans'], (float)$r['calls']) ?>%</td><td class="r"><?= (int)$r['hot'] ?></td><td class="r"><?= (int)$r['deal'] ?></td><td class="r"><?= (int)$r['na'] ?></td><td class="r"><?= (int)$r['off'] ?></td><td class="r"><?= (int)$r['cb'] ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>

<section class="card"><div class="card__h"><h2>Call outcomes</h2></div>
  <?php $mx = max(1, max(array_merge([1], array_values(array_diff_key($C, ['todo' => 1]))))); ?>
  <div class="hbars"><?php foreach ($CAT as $ck => $cl): if ($ck === 'todo' || empty($C[$ck])) { continue; } ?><div class="hbar hbar--w"><span class="hbar__l"><?= h($cl) ?></span><span class="hbar__t"><i style="width:<?= round($C[$ck] / $mx * 100) ?>%"></i></span><span class="hbar__v"><?= $C[$ck] ?> · <?= $pct((float)$C[$ck], (float)$dialled) ?>%</span></div><?php endforeach; ?></div></section>

<?php if ($nRem): ?><section class="card"><div class="card__h"><h2>Call these next</h2><a class="muted tiny" href="<?= h(url('calldash', ['t' => 'reminders'] + $keep)) ?>">All <?= $nRem ?> reminders</a></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><?php if ($who === 'all'): ?><th>Caller</th><?php endif; ?><th>Who</th><th>Phone</th><th>Status</th><th>Due</th><th class="hm">Note</th></tr></thead><tbody>
  <?php foreach (array_slice(array_values(array_merge($overdue, $dueToday)), 0, 10) as $r): ?>
    <tr><?php if ($who === 'all'): ?><td><?= h($r['person']) ?></td><?php endif; ?><td><strong><?= h($r['name']) ?></strong><?= $r['city'] !== '' ? '<br><span class="muted tiny">' . h($r['city']) . '</span>' : '' ?></td><td><?= $tel($r['phone']) ?></td><td><?= $chip($r['cat']) ?></td><td><?= $dueLbl($r['due']) ?></td><td class="hm tiny"><?= h(mb_substr($r['remark'], 0, 90)) ?></td></tr>
  <?php endforeach; if (!count($overdue) && !count($dueToday)): ?><tr><td colspan="6" class="muted">Nothing is due today. Tomorrow: <?= count($dueTom) ?> calls.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>

<?php elseif ($tabNow === 'reminders'): ?>
<div class="kpis kpis--4">
  <?= $kpi('Overdue', (string)count($overdue), 'should have been called already', 'target', count($overdue) ? 'kpi__ic--warn' : 'kpi__ic--ok') ?>
  <?= $kpi('Today', (string)count($dueToday), 'call back today', 'check') ?>
  <?= $kpi('Tomorrow', (string)count($dueTom), 'planned for tomorrow', 'trend') ?>
  <?= $kpi('Later', (string)($nRem - count($overdue) - count($dueToday) - count($dueTom)), 'dated call-backs', 'users') ?>
</div>
<p class="muted tiny" style="margin:-4px 0 12px">A reminder comes from the last call on each number: no answer or switched off = try again the next day; "Call Back, Tomorrow", a weekday, a date like "30th November", or a time like "after 5pm" in the remark sets the day; interested leads are followed up after 2 days. When the next call is entered in the sheet, the reminder goes away by itself.</p>
<?php
$groups = ['Overdue' => $overdue, 'Today' => $dueToday, 'Tomorrow' => $dueTom, 'Later' => array_filter($remRows, fn($r) => $r['due'] > $tomorrow)];
foreach ($groups as $gl => $gr): if (!count($gr)) { continue; } ?>
<section class="card"><div class="card__h"><h2><?= h($gl) ?> · <?= count($gr) ?></h2></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><?php if ($who === 'all'): ?><th>Caller</th><?php endif; ?><th>Who</th><th>Phone</th><th>Status</th><th class="r">Tries</th><th>Due</th><th>Note</th></tr></thead><tbody>
  <?php foreach ($gr as $r): ?>
    <tr><?php if ($who === 'all'): ?><td><?= h($r['person']) ?></td><?php endif; ?><td><strong><?= h($r['name']) ?></strong><?= $r['city'] !== '' ? '<br><span class="muted tiny">' . h($r['city']) . '</span>' : '' ?></td><td><?= $tel($r['phone']) ?></td><td><?= $chip($r['cat']) ?><br><span class="muted tiny">last call <?= h(fmt_date($r['day'])) ?></span></td><td class="r"><?= (int)$r['tries'] ?></td><td><?= $dueLbl($r['due']) ?><br><span class="muted tiny"><?= h($r['hint']) ?></span></td><td class="tiny"><?= h(mb_substr($r['remark'], 0, 160)) ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php endforeach; if (!$nRem): ?><section class="card"><p class="muted">No reminders right now.</p></section><?php endif; ?>

<?php elseif ($tabNow === 'leads'): ?>
<?php $leads = array_values(array_filter($CUR, fn($r) => in_array($r['cat'], ['deal', 'hot', 'connected'], true))); usort($leads, fn($a, $b) => [array_search($a['cat'], ['deal', 'hot', 'connected']), $b['day']] <=> [array_search($b['cat'], ['deal', 'hot', 'connected']), $a['day']]); ?>
<section class="card"><div class="card__h"><h2>Orders, interested and details shared · <?= count($leads) ?></h2><span class="muted tiny">latest status of each number</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><?php if ($who === 'all'): ?><th>Caller</th><?php endif; ?><th>Who</th><th>Phone</th><th>Status</th><th>Last call</th><th>Next call</th><th>Note</th></tr></thead><tbody>
  <?php foreach ($leads as $r): ?>
    <tr><?php if ($who === 'all'): ?><td><?= h($r['person']) ?></td><?php endif; ?><td><strong><?= h($r['name']) ?></strong><?= $r['city'] !== '' ? '<br><span class="muted tiny">' . h($r['city']) . '</span>' : '' ?></td><td><?= $tel($r['phone']) ?></td><td><?= $chip($r['cat']) ?></td><td><?= h(fmt_date($r['day'])) ?></td><td><?= $dueLbl($r['due']) ?></td><td class="tiny"><?= h(mb_substr($r['remark'], 0, 200)) ?></td></tr>
  <?php endforeach; if (!$leads): ?><tr><td colspan="7" class="muted">No leads yet.</td></tr><?php endif; ?></tbody></table></div></section>

<?php elseif ($tabNow === 'queue'): ?>
<?php $qr = array_values(array_filter($CUR, fn($r) => $r['cat'] === 'todo')); ?>
<section class="card"><div class="card__h"><h2>Numbers still to call · <?= count($qr) ?></h2><span class="muted tiny">in the sheet with no outcome entered yet</span></div>
  <div class="tblwrap"><table class="tbl"><thead><tr><?php if ($who === 'all'): ?><th>Caller</th><?php endif; ?><th>Who</th><th>Phone</th><th>Added</th></tr></thead><tbody>
  <?php foreach ($qr as $r): ?><tr><?php if ($who === 'all'): ?><td><?= h($r['person']) ?></td><?php endif; ?><td><?= h($r['name']) ?></td><td><?= $tel($r['phone']) ?></td><td><?= h(fmt_date($r['day'])) ?></td></tr><?php endforeach; if (!$qr): ?><tr><td colspan="4" class="muted">Everything in the sheets has been called.</td></tr><?php endif; ?></tbody></table></div></section>

<?php else: ?>
<?php
$cf = (string)($_GET['c'] ?? ''); if (!isset($CAT[$cf])) { $cf = ''; }
$sq = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT * FROM ct_call WHERE $pwp AND cat != 'todo'" . ($cf !== '' ? ' AND cat = ' . $pdo->quote($cf) : '') . ($sq !== '' ? ' AND (name LIKE ' . $pdo->quote('%' . $sq . '%') . ' OR phone LIKE ' . $pdo->quote('%' . $sq . '%') . ' OR remark LIKE ' . $pdo->quote('%' . $sq . '%') . ')' : '') . ' ORDER BY day DESC, person, rown DESC LIMIT 400';
$rows = $q($sql); $pk = $keep + ['t' => 'calls'];
?>
<section class="card"><div class="card__h"><h2>All calls · <?= count($rows) ?></h2></div>
  <div style="margin-bottom:10px"><a class="chip <?= $cf === '' ? '' : 'chip--warn' ?>" href="<?= h(url('calldash', $pk)) ?>">All <?= $dialled ?></a> <?php foreach ($CAT as $ck => $cl): if ($ck === 'todo' || empty($C[$ck])) { continue; } ?><a class="chip <?= $cf === $ck ? '' : 'chip--warn' ?>" href="<?= h(url('calldash', $pk + ['c' => $ck])) ?>"><?= h($cl) ?> <?= $C[$ck] ?></a> <?php endforeach; ?></div>
  <form method="get" class="formrow"><input type="hidden" name="p" value="calldash"><input type="hidden" name="t" value="calls"><input type="hidden" name="who" value="<?= h($who) ?>"><input type="hidden" name="c" value="<?= h($cf) ?>"><input type="search" name="q" value="<?= h($sq) ?>" placeholder="Search name, phone or remark"> <button class="btn btn--sm">Search</button></form>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Day</th><?php if ($who === 'all'): ?><th>Caller</th><?php endif; ?><th>Who</th><th>Phone</th><th>Status</th><th>Note</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= h(fmt_date($r['day'])) ?></td><?php if ($who === 'all'): ?><td><?= h($r['person']) ?></td><?php endif; ?><td><strong><?= h($r['name']) ?></strong><?= $r['city'] !== '' ? '<br><span class="muted tiny">' . h($r['city']) . '</span>' : '' ?></td><td><?= $tel($r['phone']) ?></td><td><?= $chip($r['cat']) ?></td><td class="tiny"><?= h(mb_substr($r['remark'], 0, 200)) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif;
layout_foot();
