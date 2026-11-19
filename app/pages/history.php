<?php
defined('DD') or exit;
$u   = user();
$pdo = db();
$win = (int)cfg('edit_window_days', 7);
$min = add_days(today(), -$win);

$from = add_days(today(), -60);
$st = $pdo->prepare('SELECT r.*, COALESCE(SUM(t.hours),0) AS hours, COUNT(t.id) AS n,
    SUM(CASE WHEN t.status="done" THEN 1 ELSE 0 END) AS done,
    SUM(CASE WHEN t.status="blocked" THEN 1 ELSE 0 END) AS blocked
  FROM reports r LEFT JOIN tasks t ON t.report_id = r.id
  WHERE r.user_id = ? AND r.report_date >= ? GROUP BY r.id ORDER BY r.report_date DESC');
$st->execute([$u['id'], $from]);
$rows = $st->fetchAll();

$tasksBy = [];
if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    foreach ($pdo->query("SELECT * FROM tasks WHERE report_id IN ($ids) ORDER BY id") as $t) {
        $tasksBy[$t['report_id']][] = $t;
    }
}
$totalH = array_sum(array_map(fn($r) => (float)$r['hours'], $rows));

layout_head('My history');
?>
<div class="pagehead">
  <div><h1>My history</h1><p class="muted">Your reports from the last 60 days.</p></div>
  <a class="btn" href="<?= h(url('report')) ?>">Fill today's report</a>
</div>

<div class="kpis kpis--3">
  <div class="kpi"><span class="kpi__l">Reports filed</span><strong class="kpi__v"><?= count($rows) ?></strong></div>
  <div class="kpi"><span class="kpi__l">Hours logged</span><strong class="kpi__v"><?= h(fmt_hours($totalH)) ?></strong></div>
  <div class="kpi"><span class="kpi__l">Tasks</span><strong class="kpi__v"><?= (int)array_sum(array_column($rows, 'n')) ?></strong></div>
</div>

<?php if (!$rows): ?>
  <div class="card empty"><h2>No reports yet</h2><p class="muted">Your filed reports will show here.</p></div>
<?php endif; ?>

<div class="list">
<?php foreach ($rows as $r): $editable = $r['report_date'] >= $min; ?>
  <details class="item">
    <summary>
      <span class="item__date"><?= h(fmt_date($r['report_date'])) ?></span>
      <?php if ($r['type'] === 'leave'): ?>
        <span class="chip">Leave</span>
      <?php else: ?>
        <span class="item__meta"><?= h(fmt_hours((float)$r['hours'])) ?> h · <?= (int)$r['n'] ?> task<?= (int)$r['n'] === 1 ? '' : 's' ?></span>
        <?php if ((int)$r['blocked'] > 0): ?><span class="chip chip--bad"><?= (int)$r['blocked'] ?> blocked</span><?php endif; ?>
      <?php endif; ?>
    </summary>
    <div class="item__body">
      <?php foreach ($tasksBy[$r['id']] ?? [] as $t): ?>
        <div class="trow">
          <div class="trow__t"><?= nl2br(h($t['title'])) ?><?php if ($t['remarks'] !== ''): ?><div class="tiny muted"><?= h($t['remarks']) ?></div><?php endif; ?></div>
          <div class="trow__m"><span class="tiny muted"><?= h($t['category']) ?></span> <strong><?= h(fmt_hours((float)$t['hours'])) ?> h</strong> <span class="chip chip--<?= h($t['status']) ?>"><?= h(status_label($t['status'])) ?></span></div>
        </div>
      <?php endforeach; ?>
      <?php if ($r['note'] !== ''): ?><p class="tiny"><strong>Note:</strong> <?= nl2br(h($r['note'])) ?></p><?php endif; ?>
      <?php if ($editable): ?><a class="btn btn--ghost btn--sm" href="<?= h(url('report', ['date' => $r['report_date']])) ?>">Edit this report</a><?php else: ?><span class="tiny muted">Locked. Ask your admin to change it.</span><?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>
</div>
<?php layout_foot();
