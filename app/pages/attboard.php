<?php
defined('DD') or exit;

$pdo = db();
$me = user();
$isAdmin = $me['role'] === 'admin';
$today = today();
$day = valid_date($_GET['d'] ?? '') ? (string)$_GET['d'] : $today;
if ($day > $today) { $day = $today; }
$month = s_month($_GET['month'] ?? substr($day, 0, 7));
$self = url('attboard', ['d' => $day]);
$c = att_cfg();

/* ---------- actions (admin) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'settings') {
        $t = (string)($_POST['start'] ?? '');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) { set_setting('att_start_time', $t); }
        set_setting('att_grace', (string)max(0, min(240, (int)($_POST['grace'] ?? 15))));
        set_setting('att_lunch', (string)max(0, min(240, (int)($_POST['lunch'] ?? 45))));
        set_setting('att_tea', (string)max(0, min(240, (int)($_POST['tea'] ?? 30))));
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['remind'] ?? ''))) { set_setting('att_remind', (string)$_POST['remind']); }
        set_setting('att_hours', (string)max(1, min(16, (float)str_replace(',', '.', (string)($_POST['hours'] ?? 8)))));
        flash('success', 'Attendance rules saved. They apply from now on.');
    } elseif ($act === 'review') {
        $dec = ($_POST['decision'] ?? '') === 'ok' ? 'ok' : (($_POST['decision'] ?? '') === 'flag' ? 'flag' : '');
        $kind = ($_POST['kind'] ?? '') === 'missing' ? 'missing' : 'late';
        if ($dec !== '' && valid_date((string)($_POST['day'] ?? ''))) {
            $pdo->prepare('UPDATE att_reasons SET review = ?, reviewed_by = ?, reviewed_at = ? WHERE user_id = ? AND day = ? AND kind = ?')
                ->execute([$dec, (int)$me['id'], date('Y-m-d H:i:s'), (int)($_POST['uid'] ?? 0), (string)$_POST['day'], $kind]);
            flash('success', $dec === 'ok' ? 'Accepted.' : 'Flagged.');
        }
    }
    redirect($self);
}

/* ---------- data for the chosen day ---------- */
$people = $pdo->query("SELECT id, name, designation, created_at FROM users WHERE active = 1 AND role = 'employee' ORDER BY name")->fetchAll();
$ids = array_map(fn($p) => (int)$p['id'], $people);
$dd = att_data($ids, $day, $day);
$rs = att_reasons($ids, $day, $day);
$leave = [];
foreach ($pdo->query("SELECT user_id FROM reports WHERE type = 'leave' AND report_date = " . $pdo->quote($day))->fetchAll(PDO::FETCH_COLUMN) as $x) { $leave[(int)$x] = true; }
$isWd = is_working_day($day);

$rows = []; $cnt = ['in' => 0, 'late' => 0, 'out' => 0, 'break' => 0, 'leave' => 0];
foreach ($people as $p) {
    $id = (int)$p['id'];
    $a = $dd[$id][$day];
    $why = $rs[$id][$day]['late'] ?? ($rs[$id][$day]['missing'] ?? null);
    $status = 'Not in'; $cls = 'chip--bad';
    if (!$isWd && $a['in'] === null) { $status = 'Off day'; $cls = ''; }
    elseif (isset($leave[$id]) && $a['in'] === null) { $status = 'On leave'; $cls = 'chip--warn'; $cnt['leave']++; }
    elseif ($a['in'] !== null) {
        $cnt['in']++;
        if ($a['is_late']) { $cnt['late']++; }
        $map = ['working' => ['Working', 'chip--ok'], 'on_lunch' => ['On lunch', 'chip--warn'], 'on_tea' => ['On tea', 'chip--warn'], 'done' => ['Day ended', '']];
        [$status, $cls] = $map[$a['state']];
        if (in_array($a['state'], ['on_lunch', 'on_tea'], true)) { $cnt['break']++; }
        if ($a['state'] === 'done') { $cnt['out']++; }
    }
    $rows[] = ['p' => $p, 'a' => $a, 'why' => $why, 'status' => $status, 'cls' => $cls];
}
$notIn = $isWd ? count(array_filter($rows, fn($r) => $r['status'] === 'Not in')) : 0;

/* ---------- month summary ---------- */
[$mS, $mE] = s_month_range($month);
$mEnd = min($mE, $today);
$wdM = $mS <= $mEnd ? working_days($mS, $mEnd) : [];
$md = $wdM ? att_data($ids, $mS, $mEnd) : [];
$sum = [];
foreach ($people as $p) {
    $id = (int)$p['id'];
    $s = ['tracked' => 0, 'present' => 0, 'late' => 0, 'missing' => 0, 'net' => 0, 'lunchOver' => 0, 'teaOver' => 0, 'inMin' => 0];
    $first = max($c['since'], substr((string)$p['created_at'], 0, 10));
    $s['tracked'] = 0;
    foreach ($wdM as $d) {
        if ($d < $first) { continue; }
        $s['tracked']++;
        $a = $md[$id][$d] ?? null;
        if (!$a || $a['in'] === null) { if ($d < $today) { $s['missing']++; } continue; }
        $s['present']++; $s['net'] += $a['net'] ?? 0;
        if ($a['is_late']) { $s['late']++; }
        if ($c['lunch'] > 0 && $a['lunch'] > $c['lunch']) { $s['lunchOver']++; }
        if ($c['tea'] > 0 && $a['tea'] > $c['tea']) { $s['teaOver']++; }
        $s['inMin'] += (int)date('G', strtotime($a['in'])) * 60 + (int)date('i', strtotime($a['in']));
    }
    $sum[] = ['p' => $p] + $s;
}
$csrf = csrf_field();

layout_head('Attendance');
?>
<div class="pagehead">
  <div><h1>Attendance</h1><p class="muted">First login is the start of the day · <?= h(fmt_date($day, true)) ?></p></div>
  <div class="pagehead__r"><a class="btn btn--ghost" href="<?= h(url('exports')) ?>"><?= icon('download') ?>Download data</a></div>
</div>

<form method="get" class="filters"><input type="hidden" name="p" value="attboard">
  <label class="field"><span>Day</span><input type="date" name="d" value="<?= h($day) ?>" max="<?= h($today) ?>" data-autosubmit></label>
</form>

<div class="kpis kpis--4">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">In today</span><span class="kpi__ic kpi__ic--ok"><?= icon('check') ?></span></div><strong class="kpi__v"><?= $cnt['in'] ?> <span class="muted of">of <?= count($people) ?></span></strong><span class="kpi__s"><?= $cnt['out'] ?> already ended the day</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Late</span><span class="kpi__ic kpi__ic--warn"><?= icon('clock') ?></span></div><strong class="kpi__v"><?= $cnt['late'] ?></strong><span class="kpi__s">After <?= h($c['start']) ?> + <?= (int)$c['grace'] ?> min</span></div>
  <div class="kpi <?= $notIn ? 'kpi--bad' : '' ?>"><div class="kpi__top"><span class="kpi__l">Not in</span><span class="kpi__ic kpi__ic--bad"><?= icon('alert') ?></span></div><strong class="kpi__v"><?= $notIn ?></strong><span class="kpi__s"><?= $cnt['leave'] ?> on leave</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">On a break now</span><span class="kpi__ic"><?= icon('coffee') ?></span></div><strong class="kpi__v"><?= $cnt['break'] ?></strong><span class="kpi__s">Lunch or tea</span></div>
</div>

<section class="card" aria-label="Who is in">
  <div class="card__h"><h2>Team on <?= h(fmt_short($day)) ?></h2><span class="muted tiny"><?= $isWd ? 'Working day' : 'Off day' ?></span></div>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th>Name</th><th>Status</th><th>Start</th><th>End</th><th class="r">Late</th><th class="r">Lunch</th><th class="r">Tea</th><th class="r">Working</th><th>Reason</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $a = $r['a']; $why = $r['why']; ?>
      <tr>
        <td><strong><?= h($r['p']['name']) ?></strong><br><span class="muted tiny"><?= h($r['p']['designation']) ?></span></td>
        <td><span class="chip <?= $r['cls'] ?>"><?= h($r['status']) ?></span></td>
        <?php if ($a['in'] === null): ?><td colspan="6" class="muted">–</td><?php else: ?>
          <td><?= h(att_clock($a['in'])) ?></td>
          <td><?= ($a['state'] !== 'done' && $day === $today) ? '<span class="muted">–</span>' : h(att_clock($a['out'])) . ($a['out_auto'] ? ' <span class="muted tiny">auto</span>' : '') ?></td>
          <td class="r <?= $a['is_late'] ? 'is-low' : '' ?>"><?= $a['is_late'] ? (int)$a['late'] . 'm' : '–' ?></td>
          <td class="r <?= ($c['lunch'] > 0 && $a['lunch'] > $c['lunch']) ? 'is-low' : '' ?>"><?= (int)$a['lunch'] ?>m</td>
          <td class="r <?= ($c['tea'] > 0 && $a['tea'] > $c['tea']) ? 'is-low' : '' ?>"><?= (int)$a['tea'] ?>m</td>
          <td class="r"><?= h(fmt_hm($a['net'])) ?></td>
        <?php endif; ?>
        <td class="tiny">
          <?php if ($why): ?>
            <?= h($why['reason']) ?><?= $why['note'] !== '' ? ' · ' . h($why['note']) : '' ?><?= $why['claimed_in'] !== '' ? ' · says started ' . h($why['claimed_in']) : '' ?>
            <?php if ($isAdmin): ?>
              <form method="post" action="<?= h($self) ?>" class="inl"><?= $csrf ?><input type="hidden" name="action" value="review"><input type="hidden" name="uid" value="<?= (int)$r['p']['id'] ?>"><input type="hidden" name="day" value="<?= h($day) ?>"><input type="hidden" name="kind" value="<?= h($why['kind'] ?? ($a['in'] ? 'late' : 'missing')) ?>">
                <button class="chip <?= $why['review'] === 'ok' ? 'chip--ok' : '' ?>" name="decision" value="ok" type="submit">Accept</button><button class="chip <?= $why['review'] === 'flag' ? 'chip--bad' : '' ?>" name="decision" value="flag" type="submit">Flag</button></form>
            <?php else: ?><span class="chip <?= $why['review'] === 'ok' ? 'chip--ok' : ($why['review'] === 'flag' ? 'chip--bad' : '') ?>"><?= h(ucfirst($why['review'] === 'ok' ? 'accepted' : $why['review'])) ?></span><?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted">No employees yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<section class="card" aria-label="Month summary">
  <div class="card__h"><h2>Month summary</h2>
    <form method="get" class="inl"><input type="hidden" name="p" value="attboard"><input type="hidden" name="d" value="<?= h($day) ?>"><select name="month" data-autosubmit aria-label="Month"><?php
      for ($i = 0; $i < 12; $i++) { $m = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -' . $i . ' month')); echo '<option value="' . $m . '"' . ($m === $month ? ' selected' : '') . '>' . h(date('F Y', strtotime($m . '-01'))) . '</option>'; } ?></select></form></div>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th>Name</th><th class="r">Days in</th><th class="r">Days not in</th><th class="r">Late days</th><th class="r">Average start</th><th class="r">Average working</th><th class="r">Lunch over</th><th class="r">Tea over</th></tr></thead>
    <tbody>
    <?php foreach ($sum as $s): $avgIn = $s['present'] ? (int)round($s['inMin'] / $s['present']) : null; ?>
      <tr><td><strong><?= h($s['p']['name']) ?></strong></td><td class="r"><?= $s['present'] ?> / <?= $s['tracked'] ?></td><td class="r <?= $s['missing'] ? 'is-low' : '' ?>"><?= $s['missing'] ?></td><td class="r <?= $s['late'] ? 'is-low' : '' ?>"><?= $s['late'] ?></td>
        <td class="r"><?= $avgIn === null ? '–' : date('g:i a', strtotime(sprintf('%02d:%02d', intdiv($avgIn, 60), $avgIn % 60))) ?></td>
        <td class="r"><?= $s['present'] ? h(fmt_hm((int)round($s['net'] / $s['present']))) : '–' ?></td>
        <td class="r"><?= $s['lunchOver'] ?></td><td class="r"><?= $s['teaOver'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php if ($isAdmin): ?>
<form method="post" class="card card--wide" action="<?= h($self) ?>">
  <?= $csrf ?><input type="hidden" name="action" value="settings">
  <div class="card__h"><h2>Attendance rules</h2></div>
  <div class="formgrid">
    <label class="field"><span>Work starts at</span><input type="time" name="start" value="<?= h($c['start']) ?>" required></label>
    <label class="field"><span>Grace before "late" (minutes)</span><input type="number" name="grace" min="0" max="240" value="<?= (int)$c['grace'] ?>"></label>
    <label class="field"><span>Lunch allowed (minutes)</span><input type="number" name="lunch" min="0" max="240" value="<?= (int)$c['lunch'] ?>"></label>
    <label class="field"><span>Tea allowed in total (minutes)</span><input type="number" name="tea" min="0" max="240" value="<?= (int)$c['tea'] ?>"></label>
    <label class="field"><span>Remind to submit the daily report from</span><input type="time" name="remind" value="<?= h($c['remind']) ?>" required></label>
    <label class="field"><span>Expected working hours a day</span><input type="number" name="hours" min="1" max="16" step="0.5" value="<?= h((string)$c['hours']) ?>"></label>
  </div>
  <p class="muted tiny">Weekly offs and holidays come from the Holidays page. Nothing is asked on those days.</p>
  <div class="actions"><button class="btn" type="submit">Save rules</button></div>
</form>
<?php endif; ?>
<?php layout_foot();
