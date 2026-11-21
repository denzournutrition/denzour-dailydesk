<?php
defined('DD') or exit;
$pdo = db();
$cats = cfg('categories');
$desigs = cfg('designations');
$users = $pdo->query("SELECT id, name, designation FROM users WHERE active = 1 ORDER BY name")->fetchAll();
$uname = []; foreach ($users as $x) { $uname[(string)$x['id']] = $x['name']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'add') {
        $title = trim((string)($_POST['title'] ?? ''));
        $mode = ($_POST['mode'] ?? '') === 'extra' ? 'extra' : 'daily';
        $to = (string)($_POST['to'] ?? 'all');
        $scope = 'all'; $ref = '';
        if (strpos($to, 'd:') === 0) { $scope = 'designation'; $ref = substr($to, 2); }
        elseif (strpos($to, 'u:') === 0) { $scope = 'user'; $ref = (string)(int)substr($to, 2); }
        $cat = (string)($_POST['category'] ?? 'Execution'); if (!in_array($cat, $cats, true)) { $cat = 'Other'; }
        $hrs = max(0.0, min(16.0, round((float)str_replace(',', '.', (string)($_POST['hours'] ?? '0')) * 4) / 4));
        if ($title === '') { flash('error', 'Type the task name.'); }
        else {
            $pdo->prepare('INSERT INTO routine (mode, scope, ref, title, category, hours, sort) VALUES (?,?,?,?,?,?,?)')
                ->execute([$mode, $scope, $ref, mb_substr($title, 0, 300), $cat, $hrs, (int)$pdo->query('SELECT COALESCE(MAX(sort),0)+1 FROM routine')->fetchColumn()]);
            flash('ok', $mode === 'daily' ? 'Added. It will be pre-filled on the daily report.' : 'Added. It will show as a one-click button.');
        }
    } elseif ($act === 'delete') {
        $pdo->prepare('DELETE FROM routine WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Removed.');
    }
    redirect(url('routine'));
}
$rows = $pdo->query('SELECT * FROM routine ORDER BY mode, sort, id')->fetchAll();
$who = function (array $r) use ($uname): string {
    if ($r['scope'] === 'designation') { return $r['ref']; }
    if ($r['scope'] === 'user') { return $uname[$r['ref']] ?? 'Person #' . $r['ref']; }
    return 'Everyone';
};
layout_head('Daily routine');
?>
<div class="pagehead"><div><h1>Daily routine</h1>
<p class="muted">Set the work each person does every day and it appears already filled in on their daily report. They only fix the hours. Add <strong>extras</strong> (like SEO) as one-click buttons for the odd tasks.</p></div></div>

<form method="post" class="card formrow">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label class="field"><span>Type</span><select name="mode"><option value="daily">Every day (pre-filled)</option><option value="extra">Extra (one-click button)</option></select></label>
  <label class="field"><span>For</span><select name="to"><option value="all">Everyone</option>
    <optgroup label="A role"><?php foreach ($desigs as $d): ?><option value="d:<?= h($d) ?>"><?= h($d) ?></option><?php endforeach; ?></optgroup>
    <optgroup label="One person"><?php foreach ($users as $x): ?><option value="u:<?= (int)$x['id'] ?>"><?= h($x['name']) ?></option><?php endforeach; ?></optgroup></select></label>
  <label class="field grow"><span>Task</span><input type="text" name="title" maxlength="300" placeholder="e.g. Reply to marketplace queries" required></label>
  <label class="field"><span>Category</span><select name="category"><?php foreach ($cats as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Usual hours</span><input type="number" name="hours" min="0" max="16" step="0.25" placeholder="0" style="width:90px"></label>
  <button class="btn" type="submit">Add</button>
</form>

<?php foreach (['daily' => 'Pre-filled every day', 'extra' => 'One-click extras'] as $m => $label): ?>
<h2 style="margin:22px 0 10px;font-size:16px"><?= h($label) ?></h2>
<div class="list">
<?php $n = 0; foreach ($rows as $r): if ($r['mode'] !== $m) { continue; } $n++; ?>
  <div class="item item--row"><span class="item__date" style="min-width:150px"><?= h($who($r)) ?></span><span style="flex:1"><?= h($r['title']) ?> <span class="muted tiny">· <?= h($r['category']) ?><?= (float)$r['hours'] > 0 ? ' · ' . h(fmt_hours((float)$r['hours'])) . ' h' : '' ?></span></span>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">Remove</button></form></div>
<?php endforeach; if (!$n): ?><div class="card empty"><p class="muted">Nothing yet.</p></div><?php endif; ?>
</div>
<?php endforeach; layout_foot();
