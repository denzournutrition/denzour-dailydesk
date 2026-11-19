<?php
defined('DD') or exit;
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $d = (string)($_POST['hdate'] ?? ''); $l = trim((string)($_POST['label'] ?? ''));
        if (valid_date($d) && $l !== '') {
            $pdo->prepare('INSERT OR REPLACE INTO holidays (hdate,label) VALUES (?,?)')->execute([$d, mb_substr($l, 0, 80)]);
            flash('ok', 'Holiday added.');
        } else { flash('error', 'Enter a valid date and a name.'); }
    } elseif ($act === 'delete' && valid_date((string)($_POST['hdate'] ?? ''))) {
        $pdo->prepare('DELETE FROM holidays WHERE hdate = ?')->execute([$_POST['hdate']]);
        flash('ok', 'Holiday removed.');
    }
    redirect(url('holidays'));
}
$rows = $pdo->query('SELECT * FROM holidays ORDER BY hdate DESC')->fetchAll();
layout_head('Holidays');
?>
<div class="pagehead"><div><h1>Holidays</h1><p class="muted">Holidays and Sundays are not counted as missing reports.</p></div></div>
<form method="post" class="card formrow">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label class="field"><span>Date</span><input type="date" name="hdate" required></label>
  <label class="field grow"><span>Name</span><input type="text" name="label" maxlength="80" placeholder="e.g. Diwali" required></label>
  <button class="btn" type="submit">Add holiday</button>
</form>
<div class="list">
<?php foreach ($rows as $r): ?>
  <div class="item item--row"><span class="item__date"><?= h(fmt_date($r['hdate'], true)) ?></span><span><?= h($r['label']) ?></span>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="hdate" value="<?= h($r['hdate']) ?>"><button class="btn btn--ghost btn--sm" type="submit">Remove</button></form></div>
<?php endforeach; if (!$rows): ?><div class="card empty"><p class="muted">No holidays added.</p></div><?php endif; ?>
</div>
<?php layout_foot();
