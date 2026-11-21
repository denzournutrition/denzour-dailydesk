<?php
defined('DD') or exit;

$u = user();
$pdo = db();
$isAll = in_array($u['role'], ['admin', 'ceo'], true);
$month = s_month($_GET['month'] ?? $_POST['month'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['role'] === 'admin') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'map') {
        $up = $pdo->prepare('INSERT INTO track_map (name_key, user_id) VALUES (?,?) ON CONFLICT(name_key) DO UPDATE SET user_id = excluded.user_id');
        foreach ((array)($_POST['map'] ?? []) as $key => $uid) { $up->execute([track_key((string)$key), (int)$uid]); }
        $uc = $pdo->prepare('INSERT INTO track_cls (name_key, channel) VALUES (?,?) ON CONFLICT(name_key) DO UPDATE SET channel = excluded.channel');
        foreach ((array)($_POST['cls'] ?? []) as $key => $ch) { $uc->execute([track_key((string)$key), in_array($ch, ['online', 'offline'], true) ? $ch : 'none']); }
        flash('success', 'Saved. Each person now sees their own target.');
    }
    redirect(url('track', ['month' => $month]));
}

$d = track_load($month);
$who = (string)($_GET['who'] ?? '');
$sel = ($isAll && $who !== '' && isset($d['ents'][$who])) ? $d['ents'][$who] : null;
$mine = $isAll ? [] : track_entities_for_user($d, (int)$u['id']);
$people = $pdo->query("SELECT id, name FROM users WHERE active = 1 AND role IN ('employee','admin') ORDER BY name")->fetchAll();
$mlabel = date('F Y', strtotime($month . '-01'));

layout_head('Targets');
?>
<div class="pagehead">
  <div><h1><?= $isAll ? 'Targets and pace' : 'My target' ?></h1>
    <p class="muted"><?= $isAll ? 'Everyone\'s monthly target, what they have achieved, and whether they are on pace. Read from the connected Google Sheet.' : 'Your monthly target, what you have achieved, and the pace you need to hit it.' ?></p></div>
  <?php if ($u['role'] === 'admin'): ?><a class="btn btn--ghost btn--sm" href="<?= h(url('gsync')) ?>"><?= icon('upload') ?>Google Sheet</a><?php endif; ?>
</div>
<?php s_month_filter('track', $month); ?>

<?php if (!$d['ents']): ?>
  <section class="card"><p class="muted" style="margin:0">No targets for <?= h($mlabel) ?> yet.
    <?php if ($u['role'] === 'admin'): ?>Open <a href="<?= h(url('gsync')) ?>">Google Sheets</a>, choose <strong>Targets and daily tracker</strong>, and paste the sheet link.<?php else: ?>Your admin has not connected the targets sheet yet.<?php endif; ?></p></section>

<?php elseif (!$isAll): ?>
  <?php if (!$mine): ?>
    <section class="card"><p class="muted" style="margin:0">No target is linked to your name for <?= h($mlabel) ?>. Ask your admin to link it (Targets → Name settings).</p></section>
  <?php else: foreach ($mine as $i => $me):
      $title = $me['kind'] === 'platform' ? $me['name'] . ' (assigned to you)' : $u['name']; ?>
    <?php track_hero($me, $d, $title); ?>
    <section class="card"><div class="card__h"><h2>Day by day · <?= h($me['name']) ?></h2><span class="muted tiny">Bars = what you did · dashed line = daily target</span></div><?= track_chart($me) ?></section>
    <details class="card" <?= $i === 0 ? 'open' : '' ?>><summary><strong>Daily tracker · <?= h($me['name']) ?></strong></summary><div style="margin-top:12px"><?php track_daily_table($me, $d); ?></div></details>
  <?php endforeach; endif; ?>

<?php elseif ($sel): ?>
  <p><a class="btn btn--ghost btn--sm" href="<?= h(url('track', ['month' => $month])) ?>">‹ Everyone</a></p>
  <?php track_hero($sel, $d); ?>
  <section class="card"><div class="card__h"><h2>Day by day</h2><span class="muted tiny">Bars = achieved · dashed line = daily target</span></div><?= track_chart($sel) ?></section>
  <section class="card"><div class="card__h"><h2>Daily tracker</h2></div><?php track_daily_table($sel, $d); ?></section>

<?php else: $t = $d['tot']; ?>
  <div class="kpis kpis--5">
    <div class="kpi"><div class="kpi__h"><span>Total target</span></div><div class="kpi__v num"><?= h(inr_short($t['monthly'])) ?></div><div class="kpi__s">Online + Offline, counted once</div></div>
    <div class="kpi"><div class="kpi__h"><span>Achieved</span></div><div class="kpi__v num"><?= h(inr_short($t['achieved'])) ?></div><div class="kpi__s"><?= (int)round($t['ach_pct']) ?>% of target</div></div>
    <div class="kpi <?= $t['gap'] < 0 ? 'kpi--bad' : '' ?>"><div class="kpi__h"><span><?= $t['gap'] >= 0 ? 'Ahead of pace' : 'Behind pace' ?></span></div><div class="kpi__v num"><?= h(inr_short(abs($t['gap']))) ?></div><div class="kpi__s">should be <?= h(inr_short($t['expected'])) ?> <?= h(track_asof_text($d)) ?></div></div>
    <div class="kpi"><div class="kpi__h"><span>Needed per day</span></div><div class="kpi__v num"><?= h(inr_short($t['need_day'])) ?></div><div class="kpi__s"><?= h(inr_short($t['remaining'])) ?> still to go</div></div>
    <div class="kpi"><div class="kpi__h"><span>Projected month end</span></div><div class="kpi__v num"><?= h(inr_short($t['projected'])) ?></div><div class="kpi__s">at the current rate</div></div>
  </div>
  <section class="card"><div class="card__h"><h2>People</h2><span class="muted tiny">Click a name for the daily tracker</span></div><?php track_team_table($d, 'person'); ?></section>
  <section class="card"><div class="card__h"><h2>Platforms</h2></div><?php track_team_table($d, 'platform'); ?></section>
  <p class="muted tiny">The bar line marks where each person should be today. "Ahead / behind" compares their total with the sum of their daily targets up to <?= h(track_asof_text($d)) ?>.<?= $d['synced'] !== '' ? ' Last read from the sheet ' . h(date('j M, g:i a', strtotime($d['synced']))) . '.' : '' ?></p>

  <?php if ($u['role'] === 'admin'): ?>
  <details class="card" open>
    <summary><strong>Name settings</strong> <span class="muted tiny">link people to employees and choose Online or Offline for the Sales overview</span></summary>
    <form method="post" action="<?= h(url('track', ['month' => $month])) ?>" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="action" value="map"><input type="hidden" name="month" value="<?= h($month) ?>">
      <div class="tblwrap"><table class="tbl"><thead><tr><th>Name in the sheet</th><th>Assigned to employee (they see this target)</th><th>Show sales on Sales overview as</th></tr></thead><tbody>
      <?php foreach ($d['ents'] as $e): $k = track_key($e['name']); ?>
        <tr><td><strong><?= h($e['name']) ?></strong> <span class="muted tiny"><?= $e['kind'] === 'person' ? 'person' : 'platform' ?></span></td>
          <td><select name="map[<?= h($k) ?>]"><option value="0"><?= $e['kind'] === 'person' ? 'Not linked' : 'Nobody' ?></option>
            <?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>" <?= $e['user_id'] === (int)$pp['id'] ? 'selected' : '' ?>><?= h($pp['name']) ?></option><?php endforeach; ?></select></td>
          <td><select name="cls[<?= h($k) ?>]"><?php foreach (['online' => 'Online', 'offline' => 'Offline', 'none' => 'Not counted'] as $cv => $cl): ?><option value="<?= h($cv) ?>" <?= $e['channel'] === $cv ? 'selected' : '' ?>><?= h($cl) ?></option><?php endforeach; ?></select></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <p class="muted tiny">Only names marked Online or Offline are added to the Sales overview and to the total above, so a sale is never counted twice (for example a person's sales that are already inside a platform). If a platform already has its own sales data for the month, that data is kept and the sheet only fills a gap.</p>
      <div class="actions"><button class="btn" type="submit">Save</button></div>
    </form>
  </details>
  <?php endif; ?>
<?php endif; ?>
<?php layout_foot();
