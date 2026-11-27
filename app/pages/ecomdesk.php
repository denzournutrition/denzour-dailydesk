<?php
defined('DD') or exit;

$pdo = db();
$me = user();
$uid = (int)$me['id'];
if (!can_ecom_desk($me)) {
    http_response_code(403);
    layout_head('Not allowed');
    echo '<div class="card"><h1>Not allowed</h1><p class="muted">The E-commerce desk is for the e-commerce team. Ask your admin to tick "Can update e-commerce sales" for you in People.</p><p><a class="btn" href="' . h(home_for($me)) . '">Go back</a></p></div>';
    layout_foot();
    exit;
}
$today = today();
$yest = add_days($today, -1);
$day = valid_date($_GET['d'] ?? '') && $_GET['d'] <= $today ? (string)$_GET['d'] : $yest;
$self = url('ecomdesk', ['d' => $day]);
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'upload') {
        $f = $_FILES['sheet'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('error', $f && $f['error'] === UPLOAD_ERR_INI_SIZE ? 'The file is bigger than this server allows. Save it as .csv or remove extra rows.' : 'Please choose a file first.');
            redirect($self);
        }
        try { $tabs = ecom_parse_file($f['tmp_name'], (string)$f['name']); }
        catch (Throwable $e) { flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'This file could not be read. Save it again as .xlsx or .csv and retry.'); redirect($self); }
        $token = bin2hex(random_bytes(16));
        $json = json_encode(['uid' => $uid, 'name' => (string)$f['name'], 'tabs' => $tabs, 'kind' => 'ecom'], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || @file_put_contents(sales_stage_dir() . '/' . $token . '.json', $json) === false) {
            flash('error', 'The file was read but could not be held for review. Please try again.');
            redirect($self);
        }
        $preview = ['token' => $token, 'name' => (string)$f['name'], 'tabs' => $tabs];
    } elseif ($act === 'confirm') {
        $tok = (string)($_POST['token'] ?? '');
        $file = preg_match('/^[a-f0-9]{32}$/', $tok) ? sales_stage_dir() . '/' . $tok . '.json' : '';
        $data = ($file !== '' && is_file($file)) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($data) || ($data['kind'] ?? '') !== 'ecom' || (int)($data['uid'] ?? 0) !== $uid) {
            flash('error', 'That upload expired. Please upload the file again.');
            redirect($self);
        }
        $chosen = array_map('intval', (array)($_POST['tabs'] ?? []));
        $pf = [];
        foreach ((array)($_POST['plat'] ?? []) as $i => $k) { if (isset(ECOM_PLATFORMS[$k])) { $pf[(int)$i] = (string)$k; } }
        if (!$chosen) {
            flash('error', 'Tick at least one tab to import.');
            $preview = ['token' => $tok, 'name' => $data['name'], 'tabs' => $data['tabs']];
        } else {
            $n = ecom_commit($uid, (string)$data['name'], $data['tabs'], $chosen, $pf);
            @unlink($file);
            flash($n ? 'success' : 'error', $n ? 'Saved ' . $n . ' day-rows. The E-commerce dashboard is up to date.' : 'Nothing was saved. Choose the platform for each tab.');
            redirect($self);
        }
    } elseif ($act === 'entry') {
        $d = (string)($_POST['day'] ?? '');
        $n = 0;
        if (valid_date($d) && $d <= $today) {
            foreach (ecom_sheet_platforms() as $k => $_) {
                $row = (array)($_POST['p'][$k] ?? []);
                $vals = [];
                foreach (['sales', 'orders', 'units', 'spend'] as $f) {
                    $raw = trim((string)($row[$f] ?? ''));
                    if ($raw !== '') { $vals[$f] = in_array($f, ['orders', 'units'], true) ? (int)round(s_amount($raw)) : s_amount($raw); }
                }
                if ($vals) { ecom_save_day($k, $d, $vals, $uid, 'manual'); $n++; }
            }
        }
        flash($n ? 'success' : 'error', $n ? 'Saved for ' . fmt_date($d) . '.' : 'Type at least one number.');
        redirect(url('ecomdesk', ['d' => valid_date($d) ? $d : $day]));
    }
}

/* ---------- data ---------- */
$cur = ecom_rows($day, $day)[$day] ?? [];
$from14 = add_days($today, -13);
$by14 = ecom_rows($from14, $today);
$st = $pdo->prepare('SELECT * FROM ecom_uploads WHERE user_id = ? ORDER BY id DESC LIMIT 5');
$st->execute([$uid]);
$ups = $st->fetchAll();
$csrf = csrf_field();

layout_head('E-commerce desk');
?>
<div class="pagehead">
  <div><h1>E-commerce desk</h1><p class="muted">Upload your sales and ad spend sheet, or type the day's numbers. The dashboard updates by itself.</p></div>
  <div class="pagehead__r"><a class="btn" href="#upload"><?= icon('upload') ?>Upload sheet</a></div>
</div>

<div class="platrow" aria-label="Updated yesterday">
  <?php foreach (ecom_sheet_platforms() as $k => $p): $r = $by14[$yest][$k] ?? null; ?>
    <div class="platchip"><?= ecom_logo($k, 34) ?><div><strong><?= h($p[0]) ?></strong><span class="tiny <?= $r ? 'ok' : 'bad' ?>"><?= $r ? 'Yesterday: ' . h(inr_short((float)$r['sales'])) . ' sales · ' . h(inr_short((float)$r['spend'])) . ' spend' : 'Yesterday not updated' ?></span></div></div>
  <?php endforeach; ?>
</div>

<?php if ($preview): ?>
<form method="post" action="<?= h($self) ?>" class="card card--wide">
  <?= $csrf ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?= h($preview['token']) ?>">
  <div class="card__h"><h2>Check what was found in <?= h($preview['name']) ?></h2></div>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th></th><th>Tab</th><th>Platform</th><th>Found</th><th class="r">Days</th><th class="r">Sales</th><th class="r">Ad spend</th></tr></thead>
    <tbody>
    <?php foreach ($preview['tabs'] as $i => $t):
        $days = array_unique(array_column($t['rows'], 'day')); sort($days);
        $ts = array_sum(array_column($t['rows'], 'sales')); $tp = array_sum(array_column($t['rows'], 'spend'));
        $mixed = $t['ok'] && count(array_unique(array_filter(array_column($t['rows'], 'p')))) > 0 && count(array_filter(array_column($t['rows'], 'p'), fn($x) => $x === '')) === 0; ?>
      <tr class="<?= $t['ok'] ? '' : 'is-dim' ?>">
        <td><?php if ($t['ok']): ?><input type="checkbox" name="tabs[]" value="<?= $i ?>" checked aria-label="Import <?= h($t['name']) ?>"><?php endif; ?></td>
        <td><strong><?= h($t['name']) ?></strong><?php if ($t['ok'] && !empty($t['note'])): ?><br><span class="muted tiny"><?= h($t['note']) ?></span><?php endif; ?></td>
        <td><?php if (!$t['ok']): ?><span class="muted"><?= h($t['note']) ?></span>
          <?php elseif ($mixed): ?><span class="chip chip--ok">From the Platform column</span>
          <?php else: ?><select name="plat[<?= $i ?>]" aria-label="Platform for <?= h($t['name']) ?>"><option value="">Choose…</option><?php foreach (ecom_sheet_platforms() as $k => $p): ?><option value="<?= h($k) ?>" <?= $t['platform'] === $k ? 'selected' : '' ?>><?= h($p[0]) ?></option><?php endforeach; ?></select><?php endif; ?></td>
        <td class="tiny"><?= $t['ok'] ? h(implode(', ', array_map(fn($c) => ['sales' => 'Sales', 'orders' => 'Orders', 'units' => 'Units', 'spend' => 'Spend'][$c], $t['cols']))) : '' ?></td>
        <td class="r"><?= $t['ok'] ? count($days) . ($days ? '<br><span class="muted tiny">' . h(fmt_short($days[0])) . ' – ' . h(fmt_short(end($days))) . '</span>' : '') : '' ?></td>
        <td class="r"><?= $t['ok'] && in_array('sales', $t['cols'], true) ? h(inr($ts)) : '–' ?></td>
        <td class="r"><?= $t['ok'] && in_array('spend', $t['cols'], true) ? h(inr($tp)) : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted tiny">A day that is already saved is updated with the new numbers. Only the columns found in the sheet are changed, so you can upload a sales sheet and a spend sheet separately.</p>
  <div class="actions"><button class="btn" type="submit">Save to dashboard</button><a class="btn btn--ghost" href="<?= h($self) ?>">Cancel</a></div>
</form>
<?php endif; ?>

<section class="card" id="upload">
  <div class="card__h"><h2>Upload sheet</h2></div>
  <p class="muted tiny">Excel or CSV with a Date column and Sales (or GMV) and Ad spend columns. Add a Platform column to put all platforms in one sheet, or use one tab per platform named Blinkit, Myntra, Hyugalife or Tata 1mg. Several rows for the same day are added together.</p>
  <form method="post" enctype="multipart/form-data" action="<?= h($self) ?>" class="upform">
    <?= $csrf ?><input type="hidden" name="action" value="upload">
    <label class="field grow"><span>Sheet file</span><input type="file" name="sheet" accept=".xlsx,.csv" required></label>
    <button class="btn" type="submit"><?= icon('upload') ?>Read sheet</button>
  </form>
</section>

<form method="post" action="<?= h($self) ?>" class="card card--wide">
  <?= $csrf ?><input type="hidden" name="action" value="entry"><input type="hidden" name="day" value="<?= h($day) ?>">
  <div class="card__h"><h2>Type the day's numbers</h2>
  </div>
  <?php ecom_day_bar('ecomdesk', $day, false); ?>
  <div class="tblwrap"><table class="tbl tbl--entry">
    <thead><tr><th>Platform</th><th class="r">Sales ₹</th><th class="r">Orders</th><th class="r">Ad spend ₹</th></tr></thead>
    <tbody>
    <?php foreach (ecom_sheet_platforms() as $k => $p): $r = $cur[$k] ?? null; ?>
      <tr><td><span class="pcell"><?= ecom_logo($k, 30) ?><strong><?= h($p[0]) ?></strong></span></td>
        <td class="r"><input class="num" inputmode="decimal" name="p[<?= h($k) ?>][sales]" value="<?= $r ? h((string)(float)$r['sales']) : '' ?>" aria-label="<?= h($p[0]) ?> sales"></td>
        <td class="r"><input class="num" inputmode="numeric" name="p[<?= h($k) ?>][orders]" value="<?= $r ? (int)$r['orders'] : '' ?>" aria-label="<?= h($p[0]) ?> orders"></td>
        <td class="r"><input class="num" inputmode="decimal" name="p[<?= h($k) ?>][spend]" value="<?= $r ? h((string)(float)$r['spend']) : '' ?>" aria-label="<?= h($p[0]) ?> ad spend"></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="actions"><button class="btn" type="submit">Save <?= h(fmt_date($day)) ?></button><span class="muted tiny">Leave a box empty to keep what is saved.</span></div>
</form>

<section class="card" aria-label="Last 14 days">
  <div class="card__h"><h2>Last 14 days</h2><span class="muted tiny">Sales / ad spend</span></div>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th>Date</th><?php foreach (ecom_sheet_platforms() as $k => $p): ?><th class="r"><?= h($p[0]) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php for ($d = $today; $d >= $from14; $d = add_days($d, -1)): $wd = is_working_day($d); ?>
      <tr class="<?= $wd ? '' : 'is-dim' ?>"><td><?= h(fmt_date($d)) ?></td>
        <?php foreach (ecom_sheet_platforms() as $k => $p): $r = $by14[$d][$k] ?? null; ?>
          <td class="r <?= !$r && $wd && $d < $today ? 'is-low' : '' ?>"><?= $r ? h(inr_short((float)$r['sales'])) . ' <span class="muted tiny">/ ' . h(inr_short((float)$r['spend'])) . '</span>' : '–' ?></td>
        <?php endforeach; ?></tr>
    <?php endfor; ?>
    </tbody>
  </table></div>
</section>

<?php if ($ups): ?>
<section class="card"><div class="card__h"><h2>My uploads</h2></div>
  <ul class="plain"><?php foreach ($ups as $u): ?><li><strong><?= h($u['filename']) ?></strong> <span class="muted tiny">· <?= h(date('j M, g:i a', strtotime($u['created_at']))) ?> · <?= (int)$u['rows_in'] ?> day-rows</span></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<?php gsync_card('ecom', $uid, 'ecomdesk'); ?>
<?php layout_foot();
