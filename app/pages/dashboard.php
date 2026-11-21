<?php
defined('DD') or exit;
$pdo = db();
$today = today();

/* ---------- filters ---------- */
$range = (string)($_GET['range'] ?? '7d');
$from = $to = $today;
switch ($range) {
    case 'today':     $from = $to = $today; break;
    case 'yesterday': $from = $to = add_days($today, -1); break;
    case '30d':       $from = add_days($today, -29); break;
    case 'month':     $from = date('Y-m-01'); break;
    case 'custom':
        $f = $_GET['from'] ?? ''; $t = $_GET['to'] ?? '';
        if (valid_date($f) && valid_date($t) && $f <= $t) {
            $from = $f; $to = min($t, $today);
            if ($from > $to) { $from = $to; }
            if ((new DateTime($from))->diff(new DateTime($to))->days > 92) { $from = add_days($to, -92); }
        } else { $range = '7d'; $from = add_days($today, -6); }
        break;
    default: $range = '7d'; $from = add_days($today, -6);
}
$desigs = cfg('designations');
$fd = (string)($_GET['designation'] ?? '');
if (!in_array($fd, $desigs, true)) { $fd = ''; }
$fu = (int)($_GET['user'] ?? 0);

$all = $pdo->query("SELECT id, name, designation FROM users WHERE active = 1 AND role = 'employee' ORDER BY name")->fetchAll();
$people = array_values(array_filter($all, fn($p) => ($fd === '' || $p['designation'] === $fd) && ($fu === 0 || (int)$p['id'] === $fu)));
$ids = array_map(fn($p) => (int)$p['id'], $people);
$in = $ids ? implode(',', $ids) : '0';

$wd = working_days($from, min($to, $today));
$wdCount = count($wd);

/* ---------- data ---------- */
$per = [];
foreach ($people as $p) {
    $per[$p['id']] = $p + ['filed' => 0, 'leave' => 0, 'hours' => 0.0, 'tasks' => 0, 'done' => 0, 'blocked' => 0, 'last' => null];
}
$q = $pdo->query("SELECT r.user_id, r.type, r.report_date FROM reports r WHERE r.user_id IN ($in) AND r.report_date BETWEEN '$from' AND '$to'");
$byDay = [];
foreach ($q as $r) {
    $per[$r['user_id']]['filed']++;
    if ($r['type'] === 'leave') { $per[$r['user_id']]['leave']++; }
    if (!$per[$r['user_id']]['last'] || $r['report_date'] > $per[$r['user_id']]['last']) { $per[$r['user_id']]['last'] = $r['report_date']; }
    $byDay[$r['report_date']]['reports'] = ($byDay[$r['report_date']]['reports'] ?? 0) + 1;
}
$tasks = $pdo->query("SELECT t.*, r.user_id, r.report_date FROM tasks t JOIN reports r ON r.id = t.report_id
    WHERE r.user_id IN ($in) AND r.report_date BETWEEN '$from' AND '$to' ORDER BY r.report_date DESC, t.id")->fetchAll();
$cat = []; $stat = ['done' => 0, 'in_progress' => 0, 'blocked' => 0]; $desH = []; $totalH = 0.0; $blocked = [];
$tasksBy = [];
foreach ($tasks as $t) {
    $h = (float)$t['hours'];
    $per[$t['user_id']]['hours'] += $h;
    $per[$t['user_id']]['tasks']++;
    if ($t['status'] === 'done') { $per[$t['user_id']]['done']++; }
    if ($t['status'] === 'blocked') { $per[$t['user_id']]['blocked']++; $blocked[] = $t; }
    $cat[$t['category']] = ($cat[$t['category']] ?? 0) + $h;
    $stat[$t['status']]++;
    $dg = $per[$t['user_id']]['designation'];
    $desH[$dg] = ($desH[$dg] ?? 0) + $h;
    $byDay[$t['report_date']]['hours'] = ($byDay[$t['report_date']]['hours'] ?? 0) + $h;
    $totalH += $h;
    $tasksBy[$t['user_id']][$t['report_date']][] = $t;
}
arsort($cat); arsort($desH);

$nPeople = count($people);
$expected = $nPeople * $wdCount;
$filedOnWd = 0;
foreach ($people as $p) {
    $st = $pdo->prepare('SELECT report_date FROM reports WHERE user_id = ? AND report_date BETWEEN ? AND ?');
    $st->execute([$p['id'], $from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { if (in_array($d, $wd, true)) { $filedOnWd++; } }
}
$rate = $expected > 0 ? round($filedOnWd / $expected * 100) : null;
$workReports = 0; foreach ($per as $p) { $workReports += $p['filed'] - $p['leave']; }
$avgDay = $workReports > 0 ? $totalH / $workReports : 0;

/* today panel */
$todayIsWd = in_array($today, working_days($today, $today), true);
$filedToday = [];
if ($ids) {
    foreach ($pdo->query("SELECT user_id, type FROM reports WHERE report_date = '$today' AND user_id IN ($in)") as $r) { $filedToday[$r['user_id']] = $r['type']; }
}
$missing = array_filter($people, fn($p) => !isset($filedToday[$p['id']]));

/* ---------- summary text ---------- */
$periodLabel = $from === $to ? fmt_date($from, true) : fmt_date($from) . ' to ' . fmt_date($to, true);
$sum = [];
if ($nPeople === 0) {
    $sum[] = 'No team members match these filters.';
} elseif ($workReports === 0 && $totalH == 0) {
    $sum[] = 'No work reports were filed for ' . $periodLabel . '.';
} else {
    $s = $nPeople . ' team member' . ($nPeople === 1 ? '' : 's') . ' logged ' . fmt_hours($totalH) . ' hours across ' . count($tasks) . ' tasks';
    $s .= $rate !== null ? ', with ' . $rate . '% of expected reports filed.' : '.';
    $sum[] = $s;
    if ($cat) { $k = array_key_first($cat); $sum[] = 'Most time went to ' . $k . ' (' . fmt_hours($cat[$k]) . ' h, ' . round($cat[$k] / max($totalH, .01) * 100) . '%).'; }
    if ($stat['blocked'] > 0) { $sum[] = $stat['blocked'] . ' task' . ($stat['blocked'] === 1 ? ' is' : 's are') . ' marked blocked and need attention.'; }
    else { $sum[] = 'No tasks are blocked.'; }
    $top = $per; usort($top, fn($a, $b) => $b['hours'] <=> $a['hours']);
    if ($top && $top[0]['hours'] > 0) { $sum[] = 'Highest hours: ' . $top[0]['name'] . ' (' . fmt_hours($top[0]['hours']) . ' h).'; }
}
if ($todayIsWd && $nPeople && $to === $today) {
    $sum[] = count($missing) === 0 ? 'Everyone has filed today.' : count($missing) . ' not filed yet today.';
}

/* ---------- previous period (for trend badges) ---------- */
$len   = (new DateTime($from))->diff(new DateTime($to))->days + 1;
$pFrom = add_days($from, -$len);
$pTo   = add_days($from, -1);
$pWd   = working_days($pFrom, $pTo);
$prev  = ['hours' => 0.0, 'tasks' => 0, 'blocked' => 0, 'filed' => 0];
if ($ids) {
    $st = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(t.hours),0) h, COALESCE(SUM(t.status='blocked'),0) b FROM tasks t JOIN reports r ON r.id=t.report_id WHERE r.user_id IN ($in) AND r.report_date BETWEEN ? AND ?");
    $st->execute([$pFrom, $pTo]);
    $x = $st->fetch();
    $prev['tasks'] = (int)$x['c']; $prev['hours'] = (float)$x['h']; $prev['blocked'] = (int)$x['b'];
    $st = $pdo->prepare("SELECT report_date FROM reports WHERE user_id IN ($in) AND report_date BETWEEN ? AND ?");
    $st->execute([$pFrom, $pTo]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { if (in_array($d, $pWd, true)) { $prev['filed']++; } }
}
$pExpected = $nPeople * count($pWd);
$pRate = $pExpected > 0 ? round($prev['filed'] / $pExpected * 100) : null;

function delta(float $cur, float $old, bool $upGood = true, string $unit = '%'): string
{
    if ($old <= 0) { return $cur > 0 ? '<span class="delta delta--flat">new</span>' : ''; }
    $pc = round(($cur - $old) / $old * 100, 1);
    if (abs($pc) < 0.1) { return '<span class="delta delta--flat">0%</span>'; }
    $up = $pc > 0; $good = $up === $upGood;
    return '<span class="delta delta--' . ($good ? 'up' : 'down') . '">' . ($up ? '▲' : '▼') . ' ' . h(rtrim(rtrim(number_format(abs($pc), 1), '0'), '.')) . $unit . '</span>';
}
function delta_pts(?int $cur, ?int $old): string
{
    if ($cur === null || $old === null) { return ''; }
    $d = $cur - $old;
    if ($d === 0) { return '<span class="delta delta--flat">0 pts</span>'; }
    return '<span class="delta delta--' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '▲' : '▼') . ' ' . abs($d) . ' pts</span>';
}

/* hours by weekday */
$wk = array_fill(0, 7, 0.0);
foreach ($byDay as $d => $v) { $wk[(int)(new DateTime($d))->format('w')] += (float)($v['hours'] ?? 0); }
$wkMax = max($wk) ?: 1; $wkTop = array_search(max($wk), $wk, true);
$wkNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/* ---------- svg helpers ---------- */
function area_chart(array $days, array $byDay): string
{
    $n = count($days); if ($n === 0) { return ''; }
    $max = 1; foreach ($days as $d) { $max = max($max, (float)($byDay[$d]['hours'] ?? 0)); }
    $W = 720; $H = 230; $pl = 36; $pr = 8; $pb = 28; $pt = 12;
    $iw = $W - $pl - $pr; $ih = $H - $pb - $pt;
    $x = fn($i) => $pl + ($n > 1 ? $i * $iw / ($n - 1) : $iw / 2);
    $y = fn($v) => $pt + $ih * (1 - $v / $max);
    $o = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Hours logged per day" preserveAspectRatio="xMidYMid meet"><defs><linearGradient id="ga" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2F6F55" stop-opacity=".22"/><stop offset="1" stop-color="#2F6F55" stop-opacity="0"/></linearGradient></defs>';
    foreach ([0, .25, .5, .75, 1] as $g) {
        $yy = $pt + $ih * (1 - $g);
        $o .= '<line x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/><text x="' . ($pl - 8) . '" y="' . ($yy + 4) . '" text-anchor="end" class="axis">' . fmt_hours($max * $g) . '</text>';
    }
    $pts = [];
    foreach ($days as $i => $d) { $pts[] = round($x($i), 1) . ',' . round($y((float)($byDay[$d]['hours'] ?? 0)), 1); }
    $line = 'M' . implode(' L', $pts);
    $o .= '<path class="area" d="' . $line . ' L' . round($x($n - 1), 1) . ',' . ($pt + $ih) . ' L' . round($x(0), 1) . ',' . ($pt + $ih) . ' Z"/><path class="ln" d="' . $line . '"/>';
    $step = max(1, (int)ceil($n / 8));
    foreach ($days as $i => $d) {
        $v = (float)($byDay[$d]['hours'] ?? 0);
        if ($n <= 40) { $o .= '<circle class="dot" cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="3.2"><title>' . h(fmt_date($d)) . ': ' . fmt_hours($v) . ' h</title></circle>'; }
        if ($i % $step === 0) { $o .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle" class="axis">' . h(fmt_short($d)) . '</text>'; }
    }
    return $o . '</svg>';
}
function gauge(int $pct): string
{
    $cx = 150; $cy = 140; $r1 = 112; $r2 = 132; $n = 44; $o = '<svg viewBox="0 0 300 160" role="img" aria-label="Report rate ' . $pct . ' percent">';
    for ($i = 0; $i < $n; $i++) {
        $a = M_PI * (1 - $i / ($n - 1));
        $on = ($i / ($n - 1) * 100) <= $pct;
        $o .= '<line x1="' . round($cx + $r1 * cos($a), 1) . '" y1="' . round($cy - $r1 * sin($a), 1) . '" x2="' . round($cx + $r2 * cos($a), 1) . '" y2="' . round($cy - $r2 * sin($a), 1) . '" stroke="' . ($on ? '#22a55a' : 'currentColor') . '" stroke-opacity="' . ($on ? '1' : '.16') . '" stroke-width="4" stroke-linecap="round"/>';
    }
    return $o . '</svg>';
}
function hbars(array $data, string $unit = ' h'): string
{
    if (!$data) { return '<p class="muted tiny">No data for this period.</p>'; }
    $max = max($data) ?: 1; $o = '<div class="hbars">';
    foreach ($data as $k => $v) {
        $o .= '<div class="hbar"><span class="hbar__l">' . h($k) . '</span><span class="hbar__t"><i style="width:' . round($v / $max * 100) . '%"></i></span><span class="hbar__v">' . h(fmt_hours((float)$v)) . $unit . '</span></div>';
    }
    return $o . '</div>';
}
$allDays = []; for ($d = $from; $d <= $to; $d = add_days($d, 1)) { $allDays[] = $d; }
$chartDays = ($wd && count($wd) > 1) ? $wd : $allDays;
$exportQ = ['range' => $range, 'from' => $from, 'to' => $to, 'designation' => $fd, 'user' => $fu];
$target = (int)cfg('report_target', 90);

$widgets = [
    'kpis'     => ['Key numbers', 'Reports filed, hours, tasks and blocked tasks with change vs the last period.', 'Overview'],
    'hours'    => ['Hours trend', 'Total hours logged by the team, day by day.', 'Performance'],
    'weekday'  => ['Most active day', 'Which weekday the team logs the most hours.', 'Patterns'],
    'rate'     => ['Report rate', 'Share of expected daily reports that were filed.', 'Discipline'],
    'today'    => ['Today', 'Who has filed today and who has not.', 'Daily'],
    'status'   => ['Task status', 'Done, in progress and blocked tasks, plus hours by category.', 'Delivery'],
    'desig'    => ['Hours by designation', 'Compare effort across teams such as SEO, Sales and Design.', 'Teams'],
    'blocked'  => ['Blocked tasks', 'Tasks that need attention right now.', 'Risks'],
    'employees'=> ['Employees', 'Per-person hours, days filed, tasks and drill-down.', 'People'],
];
$rateTxt = $rate === null ? '–' : (string)$rate;

layout_head('Dashboard');
?>
<div class="pagehead">
  <div><h1>Dashboard</h1><p class="muted">View only · <?= h($periodLabel) ?></p></div>
  <div class="pagehead__r">
    <button class="btn btn--ghost" type="button" data-open="widgets"><?= icon('widget') ?>Add widget</button>
    <a class="btn" href="<?= h(url('export', $exportQ)) ?>"><?= icon('download') ?>Export</a>
  </div>
</div>

<form method="get" class="filters" id="filters">
  <input type="hidden" name="p" value="dashboard">
  <div class="seg" role="group" aria-label="Date range">
    <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month', 'custom' => 'Custom'] as $k => $l): ?>
      <label><input type="radio" name="range" value="<?= $k ?>" <?= $range === $k ? 'checked' : '' ?> data-autosubmit><span><?= $l ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="filters__row">
    <?php if ($range === 'custom'): ?>
      <label class="field"><span>From</span><input type="date" name="from" value="<?= h($from) ?>" max="<?= h($today) ?>"></label>
      <label class="field"><span>To</span><input type="date" name="to" value="<?= h($to) ?>" max="<?= h($today) ?>"></label>
    <?php endif; ?>
    <label class="field"><span>Designation</span>
      <select name="designation" data-autosubmit><option value="">All designations</option><?php foreach ($desigs as $d): ?><option <?= $fd === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Employee</span>
      <select name="user" data-autosubmit><option value="0">Everyone</option><?php foreach ($all as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $fu === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select></label>
    <?php if ($range === 'custom'): ?><button class="btn btn--sm" type="submit">Apply</button><?php endif; ?>
  </div>
</form>

<section class="card summary" aria-label="Summary" data-widget="summary">
  <p><?= h(implode(' ', $sum)) ?></p>
</section>

<div class="kpis" data-widget="kpis">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Reports filed</span><span class="kpi__ic"><?= icon('file') ?></span></div>
    <strong class="kpi__v"><?= $rate === null ? '–' : $rate . '%' ?> <?= delta_pts($rate, $pRate) ?></strong><span class="kpi__s"><?= $filedOnWd ?> of <?= $expected ?> expected · vs <?= $pRate === null ? '–' : $pRate . '%' ?> last period</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Hours logged</span><span class="kpi__ic kpi__ic--warn"><?= icon('timer') ?></span></div>
    <strong class="kpi__v"><?= h(fmt_hours($totalH)) ?> <?= delta($totalH, $prev['hours']) ?></strong><span class="kpi__s">avg <?= h(fmt_hours(round($avgDay, 1))) ?> h per report · vs <?= h(fmt_hours($prev['hours'])) ?> h</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Tasks</span><span class="kpi__ic kpi__ic--ok"><?= icon('check') ?></span></div>
    <strong class="kpi__v"><?= count($tasks) ?> <?= delta((float)count($tasks), (float)$prev['tasks']) ?></strong><span class="kpi__s"><?= $stat['done'] ?> done · <?= $stat['in_progress'] ?> in progress · vs <?= $prev['tasks'] ?></span></div>
  <div class="kpi <?= $stat['blocked'] ? 'kpi--bad' : '' ?>"><div class="kpi__top"><span class="kpi__l">Blocked</span><span class="kpi__ic kpi__ic--bad"><?= icon('alert') ?></span></div>
    <strong class="kpi__v"><?= $stat['blocked'] ?> <?= delta((float)$stat['blocked'], (float)$prev['blocked'], false) ?></strong><span class="kpi__s"><?= array_sum(array_column($per, 'leave')) ?> leave day(s) in this period</span></div>
</div>

<div class="dash">
  <div class="dash__col">
    <section class="card" data-widget="hours" aria-label="Hours trend">
      <div class="card__h"><h2>Total hours</h2></div>
      <div class="bignum"><strong><?= h(fmt_hours($totalH)) ?> h</strong> <?= delta($totalH, $prev['hours']) ?> <span class="muted tiny">vs last period</span></div>
      <?= area_chart($chartDays, $byDay) ?>
      <?php $ts = max(1, array_sum($stat)); ?>
      <div class="stat3">
        <div style="--c:var(--ok)"><b><?= $stat['done'] ?></b><span>Done</span></div>
        <div style="--c:var(--prog)"><b><?= $stat['in_progress'] ?></b><span>In progress</span></div>
        <div style="--c:var(--bad)"><b><?= $stat['blocked'] ?></b><span>Blocked</span></div>
      </div>
    </section>

    <section class="card" data-widget="employees" aria-label="Employees">
      <div class="card__h"><h2>Employees</h2><span class="muted tiny"><?= $nPeople ?> people</span></div>
      <?php if (!$people): ?><p class="muted">No employees yet.</p><?php endif; ?>
      <div class="tablewrap">
      <?php if ($people): ?><div class="thead"><span>Name</span><span>Hours</span><span>Days filed</span><span>Tasks</span><span>Attendance</span></div><?php endif; ?>
      <?php foreach ($per as $p): $att = $wdCount ? min(100, round(($p['filed']) / $wdCount * 100)) : 0; ?>
        <details class="emp">
          <summary>
            <span class="emp__n"><strong><?= h($p['name']) ?></strong><small><?= h($p['designation']) ?></small></span>
            <span class="emp__m"><b><?= h(fmt_hours($p['hours'])) ?></b> h</span>
            <span class="emp__m"><b><?= $p['filed'] ?></b>/<?= $wdCount ?> days</span>
            <span class="emp__m"><b><?= $p['tasks'] ?></b> tasks<?php if ($p['blocked']): ?> <span class="chip chip--bad"><?= $p['blocked'] ?> blocked</span><?php endif; ?></span>
            <span class="emp__bar" aria-hidden="true"><i style="width:<?= $att ?>%"></i></span>
          </summary>
          <div class="emp__body">
            <?php if (empty($tasksBy[$p['id']])): ?><p class="muted tiny">No tasks in this period.</p><?php endif; ?>
            <?php foreach ($tasksBy[$p['id']] ?? [] as $date => $list): ?>
              <h4 class="day"><?= h(fmt_date($date)) ?></h4>
              <?php foreach ($list as $t): ?>
                <div class="trow"><div class="trow__t"><?= nl2br(h($t['title'])) ?><?php if ($t['remarks'] !== ''): ?><div class="tiny muted"><?= h($t['remarks']) ?></div><?php endif; ?></div>
                  <div class="trow__m"><span class="tiny muted"><?= h($t['category']) ?></span> <strong><?= h(fmt_hours((float)$t['hours'])) ?> h</strong> <span class="chip chip--<?= h($t['status']) ?>"><?= h(status_label($t['status'])) ?></span></div></div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      </div>
    </section>

    <section class="card" data-widget="status" aria-label="Task status">
      <div class="card__h"><h2>Task status &amp; categories</h2></div>
      <div class="stack" role="img" aria-label="Task status split">
        <i class="s-done" style="width:<?= $stat['done'] / $ts * 100 ?>%"></i><i class="s-prog" style="width:<?= $stat['in_progress'] / $ts * 100 ?>%"></i><i class="s-blk" style="width:<?= $stat['blocked'] / $ts * 100 ?>%"></i>
      </div>
      <ul class="legend"><li><i class="s-done"></i>Done <?= $stat['done'] ?></li><li><i class="s-prog"></i>In progress <?= $stat['in_progress'] ?></li><li><i class="s-blk"></i>Blocked <?= $stat['blocked'] ?></li></ul>
      <h3 class="sub">By category</h3><?= hbars($cat) ?>
    </section>
  </div>

  <div class="dash__col">
    <section class="card" data-widget="weekday" aria-label="Most active day">
      <div class="card__h"><h2>Most active day</h2></div>
      <div class="wk" role="img" aria-label="Hours by weekday">
        <?php foreach ($wk as $i => $v): $hp = $v > 0 ? max(4, round($v / $wkMax * 78)) : 3; ?>
          <div class="wk__c <?= $i === $wkTop && $v > 0 ? 'is-top' : '' ?>"><span class="wk__t"><?php if ($i === $wkTop && $v > 0): ?><span class="wk__v"><?= h(fmt_hours($v)) ?></span><?php endif; ?><span class="wk__b" style="height:<?= $hp ?>%"></span></span><span class="wk__d"><?= $wkNames[$i] ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card" data-widget="rate" aria-label="Report rate">
      <div class="card__h"><h2>Report rate</h2></div>
      <div class="gauge"><?= gauge((int)($rate ?? 0)) ?>
        <div class="gauge__t"><strong><?= $rateTxt ?><?= $rate === null ? '' : '%' ?></strong><span><?= $rate === null ? 'No working days yet' : ($rate >= $target ? 'On track for ' . $target . '% target' : 'Below ' . $target . '% target') ?></span></div></div>
    </section>

    <?php if ($todayIsWd && $nPeople): ?>
    <section class="card" data-widget="today" aria-label="Today">
      <div class="card__h"><h2>Today</h2><span class="muted tiny"><?= h(fmt_date($today)) ?></span></div>
      <div class="today">
        <div><h3>Filed (<?= count($filedToday) ?>)</h3>
          <?php foreach ($people as $p): if (isset($filedToday[$p['id']])): ?><span class="chip chip--ok"><?= h($p['name']) ?><?= $filedToday[$p['id']] === 'leave' ? ' · leave' : '' ?></span><?php endif; endforeach; ?>
          <?php if (!$filedToday): ?><span class="muted tiny">Nobody yet</span><?php endif; ?></div>
        <div><h3>Not filed (<?= count($missing) ?>)</h3>
          <?php foreach ($missing as $p): ?><span class="chip chip--warn"><?= h($p['name']) ?></span><?php endforeach; ?>
          <?php if (!$missing): ?><span class="muted tiny">All done</span><?php endif; ?></div>
      </div>
    </section>
    <?php endif; ?>

    <section class="card" data-widget="desig" aria-label="Hours by designation"><div class="card__h"><h2>Hours by designation</h2></div><?= hbars($desH) ?></section>

    <section class="card" data-widget="blocked" aria-label="Blocked tasks">
      <div class="card__h"><h2>Blocked tasks</h2></div>
      <?php if (!$blocked): ?><p class="muted tiny">Nothing blocked. 🎉</p><?php endif; ?>
      <?php foreach (array_slice($blocked, 0, 12) as $t): ?>
        <div class="trow"><div class="trow__t"><?= h($t['title']) ?><div class="tiny muted"><?= h($per[$t['user_id']]['name']) ?> · <?= h(fmt_short($t['report_date'])) ?><?= $t['remarks'] !== '' ? ' · ' . h($t['remarks']) : '' ?></div></div></div>
      <?php endforeach; ?>
    </section>
  </div>
</div>

<dialog class="wd" id="widgets" aria-labelledby="wdt">
  <div class="wd__h"><h2 id="wdt">Add widget</h2><button class="wd__x" type="button" data-close aria-label="Close">✕</button></div>
  <div class="wd__l">
    <?php foreach ($widgets as $k => [$t, $d, $tag]): ?>
      <div class="wd__i"><div><b><?= h($t) ?></b><span><?= h($d) ?></span><br><em>#<?= h($tag) ?></em></div>
        <button class="btn btn--sm wd__btn" type="button" data-toggle="<?= h($k) ?>">Selected</button></div>
    <?php endforeach; ?>
  </div>
</dialog>
<?php layout_foot();
