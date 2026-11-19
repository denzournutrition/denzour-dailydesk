<?php
defined('DD') or exit;
$pdo = db();
$to = $_GET['to'] ?? ''; $from = $_GET['from'] ?? '';
if (!valid_date($from) || !valid_date($to) || $from > $to) { $to = today(); $from = add_days($to, -6); }
$fu = (int)($_GET['user'] ?? 0);
$emps = $pdo->query("SELECT id,name,designation FROM users WHERE active=1 AND role IN ('employee','admin') ORDER BY name")->fetchAll();

$sql = "SELECT r.*, u.name, u.designation, COALESCE(SUM(t.hours),0) AS hours, COUNT(t.id) AS n
        FROM reports r JOIN users u ON u.id=r.user_id LEFT JOIN tasks t ON t.report_id=r.id
        WHERE r.report_date BETWEEN ? AND ?";
$args = [$from, $to];
if ($fu) { $sql .= ' AND u.id = ?'; $args[] = $fu; }
$sql .= ' GROUP BY r.id ORDER BY r.report_date DESC, u.name';
$st = $pdo->prepare($sql); $st->execute($args); $rows = $st->fetchAll();

layout_head('All reports');
?>
<div class="pagehead"><div><h1>All reports</h1><p class="muted">Admin: view, edit, add or delete any employee's report, any date.</p></div>
  <a class="btn btn--ghost" href="<?= h(url('export', ['from' => $from, 'to' => $to, 'user' => $fu])) ?>">Download CSV</a></div>

<form method="get" class="card formrow">
  <input type="hidden" name="p" value="allreports">
  <label class="field"><span>From</span><input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="field"><span>To</span><input type="date" name="to" value="<?= h($to) ?>"></label>
  <label class="field grow"><span>Employee</span><select name="user"><option value="0">Everyone</option><?php foreach ($emps as $e): ?><option value="<?= (int)$e['id'] ?>" <?= $fu === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn" type="submit">Show</button>
</form>

<form method="get" action="<?= h(url()) ?>" class="card formrow">
  <input type="hidden" name="p" value="report">
  <strong class="grow">Add or fix a report for someone</strong>
  <label class="field"><span>Employee</span><select name="uid"><?php foreach ($emps as $e): ?><option value="<?= (int)$e['id'] ?>"><?= h($e['name']) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Date</span><input type="date" name="date" value="<?= h(today()) ?>" max="<?= h(today()) ?>"></label>
  <button class="btn btn--ghost" type="submit">Open</button>
</form>

<p class="muted tiny"><?= count($rows) ?> report(s) · <?= h(fmt_hours(array_sum(array_map(fn($r) => (float)$r['hours'], $rows)))) ?> hours</p>
<div class="list">
<?php foreach ($rows as $r): ?>
  <div class="item item--row">
    <span class="item__date"><?= h(fmt_date($r['report_date'])) ?></span>
    <span class="grow"><strong><?= h($r['name']) ?></strong> <small class="muted"><?= h($r['designation']) ?></small></span>
    <span class="muted"><?= $r['type'] === 'leave' ? 'Leave' : h(fmt_hours((float)$r['hours'])) . ' h · ' . (int)$r['n'] . ' tasks' ?></span>
    <a class="btn btn--ghost btn--sm" href="<?= h(url('report', ['date' => $r['report_date'], 'uid' => $r['user_id']])) ?>">View / edit</a>
  </div>
<?php endforeach; if (!$rows): ?><div class="card empty"><p class="muted">No reports in this range.</p></div><?php endif; ?>
</div>
<?php layout_foot();
