<?php
defined('DD') or exit;

$pdo = db();
$meU = user();
$uid = (int)$meU['id'];
if (!can_sales_desk($meU)) {
    http_response_code(403);
    layout_head('Not allowed');
    echo '<div class="card"><h1>Not allowed</h1><p class="muted">The Sales desk is for the sales team. Ask your admin to tick "Can upload sales sheets" for you in People.</p><p><a class="btn" href="' . h(home_for($meU)) . '">Go back</a></p></div>';
    layout_foot();
    exit;
}
$today = today();
$month = s_month($_GET['month'] ?? null);
[$mStart, $mEnd] = s_month_range($month);
/* Admin works on behalf of a chosen sales person; employees only ever see their own desk. */
$deskU = $meU; $whoList = [];
if ($meU['role'] === 'admin') {
    $whoList = sales_members();
    $want = (int)($_GET['who'] ?? 0);
    $deskU = null;
    foreach ($whoList as $m) { if ((int)$m['id'] === $want) { $deskU = $m; } }
    if (!$deskU && $whoList) { $deskU = $whoList[0]; }
    if (!$deskU) {
        layout_head('Sales desk');
        echo '<div class="card"><h1>Sales desk</h1><p class="muted">No sales person yet. Open People, edit the person, and tick "Can upload sales sheets (Sales desk)". Then come back here to upload their sheet.</p><p><a class="btn" href="' . h(url('people')) . '">Open People</a></p></div>';
        layout_foot();
        exit;
    }
    $deskU = db()->query('SELECT * FROM users WHERE id = ' . (int)$deskU['id'])->fetch();
    $uid = (int)$deskU['id'];
}
$self = url('salesdesk', ['month' => $month] + ($meU['role'] === 'admin' ? ['who' => $uid] : []));
$preview = null;

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'upload') {
        $f = $_FILES['sheet'] ?? null;
        $errs = [UPLOAD_ERR_INI_SIZE => 'The file is bigger than this server allows. Remove extra rows or save as .csv.', UPLOAD_ERR_FORM_SIZE => 'The file is too big.', UPLOAD_ERR_NO_FILE => 'Please choose a file first.'];
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('error', $errs[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload did not finish. Please try again.');
            redirect($self);
        }
        if ($f['size'] > 20 * 1024 * 1024) {
            flash('error', 'The file is over 20 MB. Please upload a smaller file.');
            redirect($self);
        }
        $from = valid_date($_POST['from'] ?? '') ? (string)$_POST['from'] : date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month'));
        try {
            $tabs = sales_parse_file($f['tmp_name'], (string)$f['name']);
        } catch (Throwable $e) {
            flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'This file could not be read. Save it again as .xlsx or .csv and retry.');
            redirect($self);
        }
        $tabs = sales_annotate($tabs, $uid, $from);
        $token = bin2hex(random_bytes(16));
        $json = json_encode(['uid' => $uid, 'name' => (string)$f['name'], 'from' => $from, 'tabs' => $tabs], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || @file_put_contents(sales_stage_dir() . '/' . $token . '.json', $json) === false) {
            flash('error', 'The file was read but could not be held for review. Please try again.');
            redirect($self);
        }
        $preview = ['token' => $token, 'name' => (string)$f['name'], 'from' => $from, 'tabs' => $tabs];
    } elseif ($act === 'confirm') {
        $tok = (string)($_POST['token'] ?? '');
        $file = preg_match('/^[a-f0-9]{32}$/', $tok) ? sales_stage_dir() . '/' . $tok . '.json' : '';
        $data = ($file !== '' && is_file($file)) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($data) || (int)($data['uid'] ?? 0) !== $uid) {
            flash('error', 'That upload expired. Please upload the file again.');
            redirect($self);
        }
        $chosen = array_map('strval', (array)($_POST['tabs'] ?? []));
        if (!$chosen) {
            flash('error', 'Tick at least one tab to import.');
            $preview = ['token' => $tok, 'name' => $data['name'], 'from' => $data['from'], 'tabs' => $data['tabs']];
        } else {
            $r = sales_commit($uid, (string)$data['name'], (string)$data['from'], $data['tabs'], $chosen);
            @unlink($file);
            flash('success', 'Imported ' . $r['orders'] . ' order(s) and ' . $r['calls'] . ' call(s)'
                . (($r['orders_dup'] + $r['calls_dup']) ? ' · ' . ($r['orders_dup'] + $r['calls_dup']) . ' were already in DailyDesk' : '') . '. Your dashboard is up to date.');
            redirect($self);
        }
    } elseif ($act === 'cbdone') {
        $ph = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        if ($ph !== '') {
            $pdo->prepare('INSERT INTO sales_cb_done (user_id, phone, done_day) VALUES (?,?,?) ON CONFLICT(user_id, phone) DO UPDATE SET done_day = excluded.done_day')->execute([$uid, $ph, $today]);
            flash('success', 'Marked as called.');
        }
        redirect($self);
    } elseif ($act === 'minutes') {
        $up = $pdo->prepare('INSERT INTO sales_daily (user_id, day, call_minutes) VALUES (?,?,?) ON CONFLICT(user_id, day) DO UPDATE SET call_minutes = excluded.call_minutes');
        $del = $pdo->prepare('DELETE FROM sales_daily WHERE user_id = ? AND day = ?');
        $n = 0;
        foreach ((array)($_POST['minutes'] ?? []) as $day => $val) {
            if (!valid_date((string)$day) || $day > $today || $day < $mStart || $day > $mEnd) { continue; }
            $val = trim((string)$val);
            if ($val === '') { $del->execute([$uid, $day]); continue; }
            $m = (float)str_replace(',', '.', $val);
            if ($m < 0 || $m > 1440) { continue; }
            $up->execute([$uid, $day, $m]); $n++;
        }
        flash('success', 'Call minutes saved.');
        redirect($self);
    } elseif ($act === 'undo') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM sales_uploads WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        if ($u && ((int)$u['user_id'] === $uid || $meU['role'] === 'admin')) {
            $pdo->prepare('DELETE FROM sales_orders WHERE upload_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM sales_calls WHERE upload_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM sales_uploads WHERE id = ?')->execute([$id]);
            flash('success', 'That upload was removed, with all rows it added.');
        }
        redirect($self);
    }
}

/* ---------- data ---------- */
$stats = sales_stats($month);
$zero = ['sales' => 0.0, 'orders' => 0, 'calls' => 0, 'connected' => 0, 'minutes' => 0.0, 'fromcalls' => 0, 'cod' => 0, 'rto' => 0];
$my = ($stats['per'][$uid] ?? []) + $zero;
$tg = sales_targets($month);
$nMem = max(1, count(sales_members()));
$myTarget = $tg['users'][$uid] ?? (($meU['role'] === 'admin' || is_sales($meU)) && $tg['team']['sales'] > 0 ? $tg['team']['sales'] / $nMem : 0.0);
$pace = sales_pace($month, $myTarget, $my['sales']);
$callsDay = (int)$tg['team']['calls_day'];

$dayCalls = []; $dayOrders = []; $dayMin = [];
$st = $pdo->prepare('SELECT call_date d, COUNT(*) n, SUM(connected) c FROM sales_calls WHERE user_id = ? AND call_date BETWEEN ? AND ? GROUP BY call_date');
$st->execute([$uid, $mStart, $mEnd]);
foreach ($st->fetchAll() as $r) { $dayCalls[$r['d']] = [(int)$r['n'], (int)$r['c']]; }
$st = $pdo->prepare('SELECT order_date d, COUNT(*) n, SUM(amount) a FROM sales_orders WHERE user_id = ? AND order_date BETWEEN ? AND ? GROUP BY order_date');
$st->execute([$uid, $mStart, $mEnd]);
foreach ($st->fetchAll() as $r) { $dayOrders[$r['d']] = [(int)$r['n'], (float)$r['a']]; }
$st = $pdo->prepare('SELECT day, call_minutes FROM sales_daily WHERE user_id = ? AND day BETWEEN ? AND ?');
$st->execute([$uid, $mStart, $mEnd]);
foreach ($st->fetchAll() as $r) { $dayMin[$r['day']] = (float)$r['call_minutes']; }

$rows = [];
$wdSet = array_flip(working_days($mStart, min($mEnd, $today)));
for ($d = min($mEnd, $today); $d >= $mStart; $d = add_days($d, -1)) {
    if (isset($wdSet[$d]) || isset($dayCalls[$d]) || isset($dayOrders[$d])) { $rows[] = $d; }
}
$st = $pdo->prepare('SELECT * FROM sales_orders WHERE user_id = ? AND order_date BETWEEN ? AND ? ORDER BY order_date DESC, id DESC LIMIT 10');
$st->execute([$uid, $mStart, $mEnd]);
$recent = $st->fetchAll();
$st = $pdo->prepare('SELECT * FROM sales_uploads WHERE user_id = ? ORDER BY id DESC LIMIT 6');
$st->execute([$uid]);
$ups = $st->fetchAll();
$callbacks = sales_callbacks($uid);
$yestDay = add_days($today, -1);

$connPct = $my['calls'] ? (int)round($my['connected'] / $my['calls'] * 100) : 0;
$aov = $my['orders'] ? $my['sales'] / $my['orders'] : 0;
$tPct = pct($my['sales'], $myTarget);
$defFrom = date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month'));

layout_head('Sales desk');
?>
<div class="pagehead">
  <div><h1>Sales desk</h1><p class="muted"><?= $whoList ? 'Uploading for ' . h($deskU['name']) . '. Their dashboard and the team dashboard update by themselves' : 'Upload your sheet and your numbers update by themselves' ?> · <?= h(date('F Y', strtotime($month . '-01'))) ?></p></div>
  <div class="pagehead__r"><?php if ($whoList): ?><form method="get" class="inl"><input type="hidden" name="p" value="salesdesk"><input type="hidden" name="month" value="<?= h($month) ?>"><select name="who" data-autosubmit aria-label="Sales person"><?php foreach ($whoList as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $uid ? 'selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select></form><?php endif; ?><a class="btn" href="#upload"><?= icon('upload') ?>Upload sheet</a></div>
</div>

<?php if ($callbacks): ?>
<section class="card card--alert" aria-label="Call back today">
  <div class="card__h"><h2>Call back today <span class="chip chip--warn"><?= count($callbacks) ?></span></h2><span class="muted tiny">They did not pick up earlier. Call again today.</span></div>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th>Customer</th><th>Phone</th><th>Last tried</th><th class="r hm">Tries</th><th class="hm">Remark</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($callbacks as $cb): $y = $cb['last_day'] === $yestDay; ?>
      <tr><td><strong><?= h($cb['customer'] !== '' ? $cb['customer'] : 'Customer') ?></strong></td>
        <td><a href="tel:<?= h($cb['phone']) ?>"><?= h($cb['phone']) ?></a></td>
        <td><span class="chip <?= $y ? 'chip--warn' : 'chip--bad' ?>"><?= $y ? 'Yesterday' : h(fmt_short($cb['last_day'])) ?></span></td>
        <td class="r hm"><?= (int)$cb['attempts'] ?></td><td class="hm tiny"><?= h(mb_substr((string)$cb['remark'], 0, 60)) ?></td>
        <td class="r"><form method="post" action="<?= h($self) ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="action" value="cbdone"><input type="hidden" name="phone" value="<?= h($cb['phone']) ?>"><button class="btn btn--ghost btn--sm" type="submit">Called</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted tiny">A customer leaves this list when you reach them, when they place an order, or when you press Called.</p>
</section>
<?php endif; ?>

<?php if ($preview): ?>
<section class="card" id="preview" aria-label="Review before importing">
  <div class="card__h"><h2>Check before importing</h2><span class="muted tiny"><?= h($preview['name']) ?> · rows from <?= h(fmt_date($preview['from'], true)) ?></span></div>
  <form method="post" action="<?= h($self) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?= h($preview['token']) ?>">
    <div class="tblwrap"><table class="tbl">
      <thead><tr><th>Import</th><th>Tab</th><th>Looks like</th><th class="r">New rows</th><th class="r">Already in DailyDesk</th><th class="r">Before start date</th><th>Dates</th></tr></thead>
      <tbody>
      <?php $anyNew = false; foreach ($preview['tabs'] as $i => $t): $known = $t['type'] !== ''; $anyNew = $anyNew || ($known && $t['new'] > 0); ?>
        <tr class="<?= $known ? '' : 'is-dim' ?>">
          <td><?php if ($known): ?><input type="checkbox" name="tabs[]" value="<?= (int)$i ?>" <?= $t['new'] > 0 ? 'checked' : '' ?> aria-label="Import <?= h($t['name']) ?>"><?php else: ?>–<?php endif; ?></td>
          <td><strong><?= h($t['name']) ?></strong></td>
          <td><?= $t['type'] === 'orders' ? 'Orders' : ($t['type'] === 'calls' ? 'Call log' : '<span class="muted">Not recognised, ignored</span>') ?></td>
          <td class="r"><?= $known ? (int)$t['new'] . ($t['type'] === 'orders' ? ' · ' . h(inr($t['amount'])) : '') : '–' ?></td>
          <td class="r"><?= $known ? (int)$t['dup'] : '–' ?></td>
          <td class="r"><?= $known ? (int)$t['old'] : '–' ?></td>
          <td class="tiny"><?= ($known && $t['min'] !== '') ? h(fmt_short($t['min']) . ' – ' . fmt_short($t['max'])) : '–' ?></td>
        </tr>
        <?php foreach ($t['notes'] as $n): ?><tr class="is-note"><td></td><td colspan="6" class="tiny muted"><?= h($t['name']) ?>: <?= h($n) ?></td></tr><?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$anyNew): ?><p class="muted tiny">Nothing new to add from these tabs. Everything in the date range is already in DailyDesk.</p><?php endif; ?>
    <div class="actions"><button class="btn" type="submit">Import selected tabs</button><a class="btn btn--ghost" href="<?= h($self) ?>">Cancel</a></div>
  </form>
</section>
<?php endif; ?>

<?php s_month_filter('salesdesk', $month, $meU['role'] === 'admin' ? '<input type="hidden" name="who" value="' . $uid . '">' : ''); ?>

<section class="card tgt" aria-label="My target">
  <?php if ($myTarget > 0): ?>
    <div class="tgt__top">
      <div><span class="tgt__l">My sales this month</span>
        <div class="bignum"><strong><?= h(inr($my['sales'])) ?></strong><span class="muted">of <?= h(inr($myTarget)) ?></span><span class="chip <?= $tPct >= $pace['expected_pct'] ? 'chip--ok' : 'chip--warn' ?>"><?= $tPct ?>%</span></div></div>
    </div>
    <div class="meter" role="img" aria-label="<?= $tPct ?> percent of target"><i style="width:<?= min(100, $tPct) ?>%"></i><?php if ($pace['live']): ?><b style="left:<?= min(100, $pace['expected_pct']) ?>%" title="Where you should be today"></b><?php endif; ?></div>
    <div class="stat4">
      <div><b><?= h(inr_short($pace['remaining'])) ?></b><span>Left to reach target</span></div>
      <div><b><?= $pace['live'] ? h(inr_short($pace['per_day'])) : '–' ?></b><span>Needed per working day</span></div>
      <div><b><?= $pace['live'] ? (int)$pace['left'] : '–' ?></b><span>Working days left</span></div>
      <div><b><?= h(inr_short($pace['projected'])) ?></b><span>On pace for month end</span></div>
    </div>
  <?php else: ?>
    <div class="bignum"><strong><?= h(inr($my['sales'])) ?></strong><span class="muted">sales this month</span></div>
    <p class="muted tiny">No target is set for this month yet. Your admin sets it under Sales targets.</p>
  <?php endif; ?>
</section>

<div class="kpis kpis--5">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Orders</span><span class="kpi__ic kpi__ic--ok"><?= icon('check') ?></span></div>
    <strong class="kpi__v"><?= (int)$my['orders'] ?></strong><span class="kpi__s">Average order <?= h(inr($aov)) ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Calls</span><span class="kpi__ic"><?= icon('clock') ?></span></div>
    <strong class="kpi__v"><?= (int)$my['calls'] ?></strong><span class="kpi__s"><?= $callsDay > 0 ? 'Target ' . $callsDay . ' a day' : 'From your call tabs' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Connected</span><span class="kpi__ic kpi__ic--ok"><?= icon('users') ?></span></div>
    <strong class="kpi__v"><?= $connPct ?>%</strong><span class="kpi__s"><?= (int)$my['connected'] ?> of <?= (int)$my['calls'] ?> calls</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Call minutes</span><span class="kpi__ic kpi__ic--warn"><?= icon('timer') ?></span></div>
    <strong class="kpi__v"><?= h(fmt_hours((float)$my['minutes'])) ?></strong><span class="kpi__s">Typed in below, per day</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Orders after a call</span><span class="kpi__ic"><?= icon('trend') ?></span></div>
    <strong class="kpi__v"><?= (int)$my['fromcalls'] ?></strong><span class="kpi__s"><?= $my['orders'] ? pct($my['fromcalls'], $my['orders']) . '% of your orders' : 'Matched by phone number' ?></span></div>
</div>

<section class="card" id="upload" aria-label="Upload sheet">
  <div class="card__h"><h2>Upload your sheet</h2><span class="muted tiny">Excel (.xlsx) or CSV</span></div>
  <p class="muted tiny">Upload the whole sales workbook. DailyDesk finds the order tabs and call tabs by itself, shows you what it found, and adds only what is new. Uploading the same file again does not double anything.</p>
  <form method="post" enctype="multipart/form-data" action="<?= h($self) ?>" class="upform">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <label class="field"><span>Sheet file</span><input type="file" name="sheet" accept=".xlsx,.csv" required></label>
    <label class="field"><span>Import rows from this date</span><input type="date" name="from" value="<?= h($defFrom) ?>" max="<?= h($today) ?>"></label>
    <button class="btn" type="submit"><?= icon('upload') ?>Read file</button>
  </form>
</section>

<section class="card" aria-label="Day by day">
  <div class="card__h"><h2>Day by day</h2><span class="muted tiny">Type your call minutes for each day</span></div>
  <?php if (!$rows): ?><p class="muted">No days to show yet for this month.</p><?php else: ?>
  <form method="post" action="<?= h($self) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="minutes">
    <div class="tblwrap"><table class="tbl tbl--day">
      <thead><tr><th>Date</th><th class="r">Calls</th><th class="r hm">Connected</th><th class="r hm">Orders</th><th class="r">Sales</th><th class="r">Call minutes</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $d): $c = $dayCalls[$d] ?? [0, 0]; $o = $dayOrders[$d] ?? [0, 0.0]; ?>
        <tr>
          <td><?= h(fmt_date($d)) ?></td>
          <td class="r <?= ($callsDay > 0 && isset($wdSet[$d]) && $c[0] < $callsDay) ? 'is-low' : '' ?>"><?= $c[0] ?></td><td class="r hm"><?= $c[1] ?></td>
          <td class="r hm"><?= $o[0] ?></td><td class="r"><?= $o[0] ? h(inr($o[1])) : '–' ?></td>
          <td class="r"><input class="mini" type="number" step="1" min="0" max="1440" inputmode="numeric" name="minutes[<?= h($d) ?>]" value="<?= isset($dayMin[$d]) ? h((string)(float)$dayMin[$d]) : '' ?>" aria-label="Call minutes on <?= h(fmt_date($d)) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="actions"><button class="btn" type="submit">Save call minutes</button></div>
  </form>
  <?php endif; ?>
</section>

<div class="grid2">
  <section class="card" aria-label="Recent orders">
    <div class="card__h"><h2>Recent orders</h2><span class="muted tiny">Latest 10 this month</span></div>
    <?php if (!$recent): ?><p class="muted">No orders yet. Upload your sheet to see them here.</p><?php else: ?>
    <div class="tblwrap"><table class="tbl">
      <thead><tr><th>Date</th><th>Customer</th><th>Source</th><th class="r">Amount</th><th>Payment</th></tr></thead>
      <tbody><?php foreach ($recent as $o): ?><tr><td><?= h(fmt_short($o['order_date'])) ?></td><td><?= h($o['customer']) ?></td><td><?= h($o['source']) ?></td><td class="r"><?= h(inr((float)$o['amount'])) ?></td><td><?= h($o['payment']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
  <section class="card" aria-label="My uploads">
    <div class="card__h"><h2>My uploads</h2></div>
    <?php if (!$ups): ?><p class="muted">Nothing uploaded yet.</p><?php else: foreach ($ups as $u): ?>
      <form method="post" action="<?= h($self) ?>" class="uprow">
        <?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <div><strong><?= h($u['filename']) ?></strong><br><span class="muted tiny"><?= h(date('j M, g:i a', strtotime($u['uploaded_at']))) ?> · <?= (int)$u['orders_new'] ?> orders, <?= (int)$u['calls_new'] ?> calls</span></div>
        <button class="btn btn--ghost btn--sm" type="submit" data-confirm="Remove this upload and every row it added?">Undo</button>
      </form>
    <?php endforeach; endif; ?>
  </section>
</div>
<?php gsync_card('sales', $uid, 'salesdesk'); ?>
<?php layout_foot();
