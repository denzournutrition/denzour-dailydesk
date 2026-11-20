<?php
defined('DD') or exit;
$pdo = db(); $me = user(); $isAdmin = $me['role'] === 'admin'; $today = today();
if (!can_enter_channels($me)) { redirect(home_for($me)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $cid = (int)($_POST['channel'] ?? 0); $day = (string)($_POST['day'] ?? '');
        $sales = (float)str_replace([',', '₹', ' '], '', (string)($_POST['sales'] ?? '0'));
        $orders = max(0, (int)($_POST['orders'] ?? 0));
        $ok = $pdo->prepare('SELECT 1 FROM sales_channels WHERE id = ? AND active = 1'); $ok->execute([$cid]);
        if (!$ok->fetchColumn() || !valid_date($day) || $day > $today) { flash('error', 'Choose a channel and a date that is not in the future.'); }
        elseif ($sales < 0 || $sales > 100000000) { flash('error', 'Enter a sales amount between 0 and 10 crore.'); }
        else {
            $pdo->prepare('INSERT INTO channel_daily (channel_id, day, sales, orders, user_id, note, updated_at) VALUES (?,?,?,?,?,?,?)
                ON CONFLICT(channel_id, day) DO UPDATE SET sales=excluded.sales, orders=excluded.orders, user_id=excluded.user_id, note=excluded.note, updated_at=excluded.updated_at')
                ->execute([$cid, $day, $sales, $orders, (int)$me['id'], mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120), date('Y-m-d H:i:s')]);
            flash('ok', 'Saved ' . inr($sales) . ' for ' . fmt_date($day) . '.');
        }
    } elseif ($act === 'delrow' && $isAdmin) {
        $pdo->prepare('DELETE FROM channel_daily WHERE channel_id = ? AND day = ?')->execute([(int)($_POST['channel'] ?? 0), (string)($_POST['day'] ?? '')]);
        flash('ok', 'Entry removed.');
    } elseif ($act === 'addchannel' && $isAdmin) {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60); $kind = ($_POST['kind'] ?? '') === 'online' ? 'online' : 'offline';
        if ($name === '') { flash('error', 'Give the channel a name.'); }
        else {
            try { $pdo->prepare('INSERT INTO sales_channels (name, kind, web, active) VALUES (?,?,0,1)')->execute([$name, $kind]); flash('ok', 'Channel added.'); }
            catch (PDOException $e) { flash('error', 'A channel with that name already exists.'); }
        }
    } elseif ($act === 'toggle' && $isAdmin) {
        $pdo->prepare('UPDATE sales_channels SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['channel'] ?? 0)]);
        flash('ok', 'Updated.');
    }
    redirect(url('channels'));
}

$chs = channels_all(true);
$allChs = $isAdmin ? channels_all(false) : [];
$recent = $pdo->query("SELECT d.*, c.name, c.kind, u.name AS who FROM channel_daily d JOIN sales_channels c ON c.id = d.channel_id LEFT JOIN users u ON u.id = d.user_id ORDER BY d.day DESC, d.updated_at DESC LIMIT 40")->fetchAll();
$csrf = csrf_field();
layout_head('Website and offline sales');
?>
<div class="pagehead"><div><h1>Website and offline sales</h1><p class="muted">Add the daily sales for the website and for offline channels. The CEO sees them next to the marketplaces.</p></div></div>

<section class="card card--wide">
  <h2>Add a day's sales</h2>
  <form method="post" class="formrow">
    <?= $csrf ?><input type="hidden" name="action" value="save">
    <label class="field"><span>Channel</span><select name="channel" required><?php foreach ($chs as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(($c['kind'] === 'online' ? 'Online · ' : 'Offline · ') . $c['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Date</span><input type="date" name="day" value="<?= h(add_days($today, -1)) ?>" max="<?= h($today) ?>" required></label>
    <label class="field"><span>Sales (₹)</span><input name="sales" inputmode="decimal" required placeholder="e.g. 48500"></label>
    <label class="field"><span>Orders</span><input name="orders" inputmode="numeric" value="0"></label>
    <label class="field grow"><span>Note <em>(optional)</em></span><input name="note" maxlength="120" placeholder="e.g. 2 new stockists in Pune"></label>
    <button class="btn" type="submit">Save</button>
  </form>
  <p class="muted tiny" style="margin-top:10px">Saving the same channel and date again replaces the earlier number. Website sales here are the full website total: Inside Sales is then shown as "of which" and not added twice.</p>
</section>

<?php if ($isAdmin): ?>
<section class="card card--wide">
  <h2>Channels</h2>
  <?php foreach ($allChs as $c): ?>
    <div class="dlrow"><div><strong><?= h($c['name']) ?></strong> <span class="chip <?= $c['kind'] === 'online' ? '' : 'chip--warn' ?>"><?= $c['kind'] === 'online' ? 'Online' : 'Offline' ?></span><?= $c['active'] ? '' : ' <span class="chip chip--bad">Paused</span>' ?></div>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="channel" value="<?= (int)$c['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $c['active'] ? 'Pause' : 'Resume' ?></button></form></div>
  <?php endforeach; ?>
  <form method="post" class="formrow" style="margin-top:14px"><?= $csrf ?><input type="hidden" name="action" value="addchannel">
    <label class="field grow"><span>New channel</span><input name="name" maxlength="60" required placeholder="e.g. Offline: Delhi distributor"></label>
    <label class="field"><span>Type</span><select name="kind"><option value="offline">Offline</option><option value="online">Online</option></select></label>
    <button class="btn btn--ghost" type="submit">Add channel</button></form>
</section>
<?php endif; ?>

<section class="card">
  <h2>Recent entries</h2>
  <?php if (!$recent): ?><p class="muted">Nothing entered yet.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th>Channel</th><th class="r">Sales</th><th class="r">Orders</th><th>Entered by</th><th></th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?>
    <tr><td><?= h(fmt_date($r['day'])) ?></td><td><?= h($r['name']) ?><?= $r['note'] !== '' ? '<br><span class="muted tiny">' . h($r['note']) . '</span>' : '' ?></td><td class="r"><?= h(inr((float)$r['sales'])) ?></td><td class="r"><?= (int)$r['orders'] ?></td><td><?= h($r['who'] ?? '') ?></td>
      <td><?php if ($isAdmin): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="delrow"><input type="hidden" name="channel" value="<?= (int)$r['channel_id'] ?>"><input type="hidden" name="day" value="<?= h($r['day']) ?>"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Remove this entry?">Remove</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<?php layout_foot();
