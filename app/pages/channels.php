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
        $num = fn(string $k) => max(0.0, (float)str_replace([',', '₹', ' '], '', (string)($_POST[$k] ?? '0')));
        $ok = $pdo->prepare('SELECT web FROM sales_channels WHERE id = ? AND active = 1'); $ok->execute([$cid]);
        $isWeb = $ok->fetchColumn();
        $meta = $isWeb ? $num('meta') : 0.0; $infl = $isWeb ? $num('infl') : 0.0;
        $ok = $pdo->prepare('SELECT 1 FROM sales_channels WHERE id = ? AND active = 1'); $ok->execute([$cid]);
        if (!$ok->fetchColumn() || !valid_date($day) || $day > $today) { flash('error', 'Choose a channel and a date that is not in the future.'); }
        elseif ($sales < 0 || $sales > 100000000) { flash('error', 'Enter a sales amount between 0 and 10 crore.'); }
        elseif ($meta + $infl > $sales + 0.5) { flash('error', 'Meta ads plus influencer codes cannot be more than the total Shopify sales.'); }
        else {
            $pdo->prepare('INSERT INTO channel_daily (channel_id, day, sales, orders, user_id, note, updated_at, meta, infl) VALUES (?,?,?,?,?,?,?,?,?)
                ON CONFLICT(channel_id, day) DO UPDATE SET sales=excluded.sales, orders=excluded.orders, user_id=excluded.user_id, note=excluded.note, updated_at=excluded.updated_at, meta=excluded.meta, infl=excluded.infl')
                ->execute([$cid, $day, $sales, $orders, (int)$me['id'], mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120), date('Y-m-d H:i:s'), $meta, $infl]);
            flash('ok', 'Saved ' . inr($sales) . ' for ' . fmt_date($day) . '.');
        }
    } elseif ($act === 'offimport') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) > 5000000) { flash('error', 'Choose the offline sales CSV file (up to 5 MB).'); }
        else {
            try { $x = offline_import($f['tmp_name']); flash('ok', 'Offline sales imported: ' . $x['rows'] . ' invoices, ' . inr($x['sales']) . ', ' . fmt_date($x['from']) . ' to ' . fmt_date($x['to']) . '.' . ($x['skipped'] ? ' Skipped ' . $x['skipped'] . ' rows without a number or date.' : '')); }
            catch (Throwable $e) { flash('error', $e->getMessage()); }
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
    <label class="field"><span>Shopify only: from Meta ads (₹)</span><input name="meta" inputmode="decimal" placeholder="optional"></label>
    <label class="field"><span>Shopify only: from influencer codes (₹)</span><input name="infl" inputmode="decimal" placeholder="optional"></label>
    <label class="field grow"><span>Note <em>(optional)</em></span><input name="note" maxlength="120" placeholder="e.g. 2 new stockists in Pune"></label>
    <button class="btn" type="submit">Save</button>
  </form>
  <p class="muted tiny" style="margin-top:10px">Saving the same channel and date again replaces the earlier number. For Shopify, enter the full website total for the day. The CEO page splits it into Meta ads, influencer codes, Inside Sales (taken from the sales sheets) and others (the rest).</p>
</section>

<?php $om = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : substr($today, 0, 7); $osel = offline_by_seller($om . '-01', $om . '-31'); $ost = $pdo->prepare("SELECT status, COUNT(*) n, SUM(total) t, SUM(balance) b FROM offline_orders WHERE day BETWEEN ? AND ? GROUP BY status ORDER BY t DESC"); $ost->execute([$om . '-01', $om . '-31']); $ost = $ost->fetchAll(); ?>
<section class="card card--wide">
  <h2>Offline sales: import invoices</h2>
  <p class="muted tiny">Upload the offline team's invoice CSV (No, Date, Customer, Phone, Seller, Order Status, Courier / Note, Total, Advance, Balance). Upload the latest file any time: invoices in the same dates are replaced, so nothing is counted twice. Daily totals go to the <strong>Offline</strong> channel. Bhanu's sheet line is separate and shows as <strong>Bhanu credit offline</strong>.</p>
  <form method="post" enctype="multipart/form-data" class="formrow"><?= $csrf ?><input type="hidden" name="action" value="offimport">
    <label class="field grow"><span>CSV file</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
    <button class="btn" type="submit">Import</button></form>
  <?php if ($osel): $ot = array_sum(array_column($osel, 'sales')); ?>
  <form method="get" class="formrow" style="margin-top:12px"><input type="hidden" name="p" value="channels"><label class="field"><span>Month</span><input type="month" name="month" value="<?= h($om) ?>" onchange="this.form.submit()"></label></form>
  <div class="tblwrap"><table class="tbl"><thead><tr><th>Seller</th><th class="r">Invoices</th><th class="r">Sales</th><th class="r">Advance received</th><th class="r">Balance due</th></tr></thead><tbody>
  <?php foreach ($osel as $o): ?><tr><td><strong><?= h($o['seller'] ?: 'No seller') ?></strong></td><td class="r"><?= (int)$o['orders'] ?></td><td class="r"><?= h(inr((float)$o['sales'])) ?></td><td class="r"><?= h(inr((float)$o['advance'])) ?></td><td class="r"><?= h(inr((float)$o['balance'])) ?></td></tr><?php endforeach; ?>
  <tr><td><strong>Total</strong></td><td class="r"><strong><?= array_sum(array_column($osel, 'orders')) ?></strong></td><td class="r"><strong><?= h(inr($ot)) ?></strong></td><td class="r"><strong><?= h(inr(array_sum(array_column($osel, 'advance')))) ?></strong></td><td class="r"><strong><?= h(inr(array_sum(array_column($osel, 'balance')))) ?></strong></td></tr>
  </tbody></table></div>
  <div class="tblwrap" style="margin-top:12px"><table class="tbl"><thead><tr><th>Order status</th><th class="r">Invoices</th><th class="r">Sales</th><th class="r">Balance due</th></tr></thead><tbody>
  <?php foreach ($ost as $o): ?><tr><td><?= h($o['status'] ?: '–') ?></td><td class="r"><?= (int)$o['n'] ?></td><td class="r"><?= h(inr((float)$o['t'])) ?></td><td class="r"><?= h(inr((float)$o['b'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
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
    <tr><td><?= h(fmt_date($r['day'])) ?></td><td><?= h($r['name']) ?><?= $r['note'] !== '' ? '<br><span class="muted tiny">' . h($r['note']) . '</span>' : '' ?></td><td class="r"><?= h(inr((float)$r['sales'])) ?><?= ($r['meta'] + $r['infl']) > 0 ? '<br><span class="muted tiny">Meta ' . h(inr((float)$r['meta'])) . ' · Influencer ' . h(inr((float)$r['infl'])) . '</span>' : '' ?></td><td class="r"><?= (int)$r['orders'] ?></td><td><?= h($r['who'] ?? '') ?></td>
      <td><?php if ($isAdmin): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="delrow"><input type="hidden" name="channel" value="<?= (int)$r['channel_id'] ?>"><input type="hidden" name="day" value="<?= h($r['day']) ?>"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Remove this entry?">Remove</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<?php layout_foot();
