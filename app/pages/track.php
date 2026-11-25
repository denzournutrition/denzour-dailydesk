<?php
defined('DD') or exit;

$u = user();
$pdo = db();
$isAll = in_array($u['role'], ['admin', 'ceo'], true);
$month = s_month($_GET['month'] ?? $_POST['month'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'map' && $u['role'] === 'admin') {
        $up = $pdo->prepare('INSERT INTO track_map (name_key, user_id) VALUES (?,?) ON CONFLICT(name_key) DO UPDATE SET user_id = excluded.user_id');
        foreach ((array)($_POST['map'] ?? []) as $key => $uid) { $up->execute([track_key((string)$key), (int)$uid]); }
        $uc = $pdo->prepare('INSERT INTO track_cls (name_key, channel) VALUES (?,?) ON CONFLICT(name_key) DO UPDATE SET channel = excluded.channel');
        foreach ((array)($_POST['cls'] ?? []) as $key => $ch) { $uc->execute([track_key((string)$key), in_array($ch, ['online', 'offline'], true) ? $ch : 'none']); }
        foreach ((array)($_POST['alias'] ?? []) as $key => $al) {
            $key = track_key((string)$key); $al = mb_substr(trim((string)$al), 0, 40);
            if ($al === '') { $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute(['track_alias:' . $key]); }
            else { $pdo->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?,?)')->execute(['track_alias:' . $key, $al]); }
        }
        $us = $pdo->prepare('INSERT INTO track_src (name_key, src) VALUES (?,?) ON CONFLICT(name_key) DO UPDATE SET src = excluded.src');
        foreach ((array)($_POST['src'] ?? []) as $key => $sv) { $us->execute([track_key((string)$key), in_array($sv, ['auto', 'manual'], true) ? $sv : 'sheet']); }
        $up2 = $pdo->prepare('INSERT INTO track_part (name_key, parent_key) VALUES (?,?) ON CONFLICT(name_key) DO UPDATE SET parent_key = excluded.parent_key');
        foreach ((array)($_POST['part'] ?? []) as $key => $pv) { $key = (string)$key; $up2->execute([strpos($key, 'p:') === 0 ? $key : track_key($key), (string)$pv === '' || (string)$pv === '-' ? (string)$pv : track_key((string)$pv)]); }
        flash('success', 'Saved.');
    } elseif ($act === 'enter') {
        $name = (string)($_POST['name'] ?? '');
        $date = (string)($_POST['date'] ?? '');
        $m = substr($date, 0, 7);
        $dd = track_load($m);
        $ok = false;
        foreach (track_entities_for_user($dd, (int)$u['id']) as $e) { if ($e['name'] === $name && $e['src'] === 'manual') { $ok = true; } }
        $minD = date('Y-m-d', strtotime(today() . ' -7 days'));
        if ($u['role'] === 'admin') { $ok = isset($dd['ents'][$name]); $minD = '2000-01-01'; }
        if (!$ok || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > today() || $date < $minD) {
            flash('error', 'That entry could not be saved. Pick a day from the last 7 days.');
        } else {
            $raw = trim((string)($_POST['amount'] ?? ''));
            $day = (int)substr($date, 8, 2);
            if ($raw === '') {
                $pdo->prepare('DELETE FROM track_manual WHERE month = ? AND name = ? AND day = ?')->execute([$m, $name, $day]);
                flash('success', 'Entry for ' . fmt_date($date) . ' cleared.');
            } else {
                $pdo->prepare('INSERT INTO track_manual (month, name, day, achieved, user_id, updated_at) VALUES (?,?,?,?,?,?) ON CONFLICT(month, name, day) DO UPDATE SET achieved = excluded.achieved, user_id = excluded.user_id, updated_at = excluded.updated_at')
                    ->execute([$m, $name, $day, max(0.0, s_amount($raw)), (int)$u['id'], date('Y-m-d H:i:s')]);
                flash('success', 'Saved ' . inr(max(0.0, s_amount($raw))) . ' for ' . fmt_date($date) . '.');
            }
        }
    }
    redirect(url('track', ['month' => $m ?? $month]));
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
    <?php if ($me['src'] === 'manual'): $days = []; for ($k = 0; $k < 8; $k++) { $dt = date('Y-m-d', strtotime(today() . ' -' . $k . ' days')); if (substr($dt, 0, 7) === $month) { $days[] = $dt; } } ?>
    <section class="card">
      <div class="card__h"><h2>Fill in your sales · <?= h($me['name']) ?></h2><span class="muted tiny">Pick the day and type what you sold. Typing again for the same day replaces it.</span></div>
      <?php if (!$days): ?><p class="muted">You can fill in the last 7 days, and none of them are in <?= h($mlabel) ?>.</p><?php else: ?>
      <form method="post" action="<?= h(url('track', ['month' => $month])) ?>" class="formgrid formgrid--3">
        <?= csrf_field() ?><input type="hidden" name="action" value="enter"><input type="hidden" name="name" value="<?= h($me['name']) ?>">
        <label class="field"><span>Day</span><select name="date"><?php foreach ($days as $dt): $cur = $me['days'][(int)substr($dt, 8, 2)]['a'] ?? null; ?><option value="<?= h($dt) ?>"><?= h(date('D, j M', strtotime($dt))) ?><?= $cur !== null ? ' · ' . h(inr($cur)) : '' ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Sales (₹)</span><input type="text" name="amount" inputmode="numeric" placeholder="e.g. 45000"></label>
        <div class="actions" style="align-self:end"><button class="btn" type="submit">Save</button></div>
      </form><?php endif; ?>
    </section>
    <?php elseif ($me['src'] === 'auto'): ?><p class="muted tiny" style="margin:-6px 0 14px">These figures update by themselves from the orders and platform data already in DailyDesk.</p>
    <?php endif; ?>
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
      <div class="tblwrap"><table class="tbl"><thead><tr><th>Name in the sheet</th><th>Assigned to employee (they see this target)</th><th>Show sales on Sales overview as</th><th>Line name <span class="muted tiny">(the designation)</span></th><th>Daily figures come from</th><th>Already included in</th></tr></thead><tbody>
      <?php foreach ($d['ents'] as $e): $k = track_key($e['name']); ?>
        <tr><td><strong><?= h($e['name']) ?></strong> <span class="muted tiny"><?= $e['kind'] === 'person' ? 'person' : 'platform' ?></span></td>
          <td><select name="map[<?= h($k) ?>]"><option value="0"><?= $e['kind'] === 'person' ? 'Not linked' : 'Nobody' ?></option>
            <?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>" <?= $e['user_id'] === (int)$pp['id'] ? 'selected' : '' ?>><?= h($pp['name']) ?></option><?php endforeach; ?></select></td>
          <td><select name="cls[<?= h($k) ?>]"><?php foreach (['online' => 'Online', 'offline' => 'Offline', 'none' => 'Not counted'] as $cv => $cl): ?><option value="<?= h($cv) ?>" <?= $e['channel'] === $cv ? 'selected' : '' ?>><?= h($cl) ?></option><?php endforeach; ?></select></td>
          <td><input name="alias[<?= h($k) ?>]" maxlength="40" value="<?= h(track_alias_map()[$k] ?? '') ?>" placeholder="<?= h($e['name']) ?>" style="width:150px"></td>
          <td><select name="src[<?= h($k) ?>]"><?php foreach (['sheet' => 'Google Sheet', 'auto' => 'Automatic (DailyDesk data)', 'manual' => 'Employee types them'] as $sv => $sl): ?><option value="<?= h($sv) ?>" <?= $e['src'] === $sv ? 'selected' : '' ?>><?= h($sl) ?></option><?php endforeach; ?></select></td>
          <td><select name="part[<?= h($k) ?>]"><option value="">Nothing (counted on its own)</option><?php foreach ($d['ents'] as $o): if ($o['name'] === $e['name']) { continue; } ?><option value="<?= h(track_key($o['name'])) ?>" <?= $e['parent'] === $o['name'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></td></tr>
      <?php endforeach; ?>
      <?php foreach (['blinkit', 'myntra', 'hugulife', 'tata1mg'] as $pkey): $own = track_platform_owner($pkey, $d['ents']); $saved = track_parts()['p:' . $pkey] ?? null; ?>
        <tr><td><strong><?= h(ECOM_PLATFORMS[$pkey][0]) ?></strong> <span class="muted tiny">platform sheet</span></td><td class="muted tiny" colspan="4">Platform-wise sales and spend from the e-commerce sheet</td>
          <td><select name="part[p:<?= h($pkey) ?>]"><option value="-" <?= $saved === '-' ? 'selected' : '' ?>>Nothing (counted on its own)</option><?php foreach ($d['ents'] as $o): if ($o['kind'] !== 'person') { continue; } ?><option value="<?= h(track_key($o['name'])) ?>" <?= $own === track_key($o['name']) ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <p class="muted tiny"><strong>Already included in</strong> is for a name whose sales are part of another line, such as Sourabh's influencer sales inside Shopify, or Ritik's four platforms inside Ritik. It is still shown, but never added twice. Only names marked Online or Offline are added to the Sales overview and to the total above, so a sale is never counted twice (for example a person's sales that are already inside a platform). <strong>Automatic</strong> uses a person's Inside Sales orders, or a platform's e-commerce and Shopify data, already in DailyDesk (days with nothing become ₹0). <strong>Employee types them</strong> gives the assigned employee a box on their My target page; what they type overrides the sheet for that day.  The total always comes from this main sheet; the platform-wise sheets only show the split and the ad spend.</p>
      <div class="actions"><button class="btn" type="submit">Save</button></div>
    </form>
  </details>
  <?php endif; ?>
<?php endif; ?>
<?php layout_foot();
