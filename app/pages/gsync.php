<?php
defined('DD') or exit;

$pdo = db();
$self = url('gsync');
$kinds = ['sales' => 'Sales (orders and calls)', 'ecom' => 'E-commerce (Blinkit, Myntra, HUGU Life, Tata 1mg)'];
$people = $pdo->query("SELECT id, name FROM users WHERE role = 'employee' AND active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'add') {
        $url = trim((string)($_POST['url'] ?? ''));
        $sid = gsync_sheet_id($url);
        $kind = isset($kinds[$_POST['kind'] ?? '']) ? (string)$_POST['kind'] : 'sales';
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($sid === '') { flash('error', 'That does not look like a Google Sheets link. Copy it from the browser address bar.'); }
        elseif ($kind === 'sales' && !$uid) { flash('error', 'Choose the sales person this sheet belongs to.'); }
        else {
            $every = in_array((int)($_POST['every'] ?? 15), [5, 15, 30, 60], true) ? (int)$_POST['every'] : 15;
            $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
            $pdo->prepare('INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$label, $kind, $kind === 'sales' ? $uid : 0, $sid, mb_substr($url, 0, 400), $every, date('Y-m-d H:i:s')]);
            $id = (int)$pdo->lastInsertId();
            $m = gsync_run($id);
            flash($pdo->query('SELECT last_ok FROM gsync_links WHERE id = ' . $id)->fetchColumn() ? 'success' : 'error', 'Connected. ' . $m);
        }
    } elseif ($act === 'now') {
        $id = (int)($_POST['id'] ?? 0);
        $m = gsync_run($id);
        flash($pdo->query('SELECT last_ok FROM gsync_links WHERE id = ' . $id)->fetchColumn() ? 'success' : 'error', $m);
    } elseif ($act === 'toggle') {
        $pdo->prepare('UPDATE gsync_links SET enabled = 1 - enabled WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($act === 'delete') {
        $pdo->prepare('DELETE FROM gsync_links WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Link removed. Data already imported stays on the dashboard.');
    }
    redirect($self);
}

$links = $pdo->query('SELECT g.*, u.name AS who FROM gsync_links g LEFT JOIN users u ON u.id = g.user_id ORDER BY g.id')->fetchAll();
$csrf = csrf_field();
$cron = 'curl -s "' . (preg_match('#^https?://#', gsync_cron_url()) ? gsync_cron_url() : (((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')) . gsync_cron_url())) . '" >/dev/null 2>&1';

layout_head('Google Sheets');
?>
<div class="pagehead">
  <div><h1>Google Sheets live sync</h1><p class="muted">Connect a team member's Google Sheet once. DailyDesk reads it by itself every few minutes, so the dashboards stay current without anyone uploading a file.</p></div>
</div>

<section class="card card--wide">
  <div class="card__h"><h2>Connected sheets</h2></div>
  <?php if (!$links): ?><p class="muted">No sheet connected yet. Add one below.</p><?php else: ?>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th>Sheet</th><th>Type</th><th>Every</th><th>Last sync</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($links as $l): ?>
      <tr class="<?= $l['enabled'] ? '' : 'is-dim' ?>">
        <td><strong><?= h($l['label'] !== '' ? $l['label'] : 'Google Sheet') ?></strong><?= $l['who'] ? '<br><span class="muted tiny">' . h($l['who']) . '</span>' : '' ?></td>
        <td class="tiny"><?= $l['kind'] === 'ecom' ? 'E-commerce' : 'Sales' ?></td>
        <td class="tiny"><?= (int)$l['every_min'] ?> min</td>
        <td class="tiny"><?= $l['last_run'] !== '' ? h(date('d M, g:i a', strtotime($l['last_run']))) : 'Not yet' ?></td>
        <td class="tiny"><?= $l['last_run'] === '' ? '' : ($l['last_ok'] ? '<span class="chip chip--ok">OK</span> ' : '<span class="chip chip--warn">Problem</span> ') ?><?= h($l['last_msg']) ?></td>
        <td class="r" style="white-space:nowrap">
          <form method="post" action="<?= h($self) ?>" style="display:inline"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn--ghost btn--sm" name="action" value="now" type="submit">Sync now</button>
          <button class="btn btn--ghost btn--sm" name="action" value="toggle" type="submit"><?= $l['enabled'] ? 'Pause' : 'Resume' ?></button>
          <button class="btn btn--ghost btn--sm" name="action" value="delete" type="submit" data-confirm="Remove this link? Data already imported stays.">Remove</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<form method="post" action="<?= h($self) ?>" class="card card--wide">
  <?= $csrf ?><input type="hidden" name="action" value="add">
  <div class="card__h"><h2>Connect a Google Sheet</h2></div>
  <ol class="steps">
    <li>Open the Google Sheet. Click <strong>Share</strong>, then under General access choose <strong>Anyone with the link</strong> and role <strong>Viewer</strong>.</li>
    <li>Copy the link from the address bar and paste it below.</li>
    <li>Pick whose sheet it is. Their dashboard updates every time the sheet changes.</li>
  </ol>
  <div class="formgrid formgrid--3">
    <label class="field grow"><span>Google Sheet link</span><input type="url" name="url" required placeholder="https://docs.google.com/spreadsheets/d/…"></label>
    <label class="field"><span>Name <em>optional</em></span><input type="text" name="label" maxlength="80" placeholder="e.g. Riya sales sheet"></label>
    <label class="field"><span>Type</span><select name="kind" id="gskind"><?php foreach ($kinds as $k => $t): ?><option value="<?= h($k) ?>"><?= h($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Sales person <em>for Sales type</em></span><select name="user_id"><option value="">Choose…</option><?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>"><?= h($pp['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Refresh every</span><select name="every"><option value="5">5 minutes</option><option value="15" selected>15 minutes</option><option value="30">30 minutes</option><option value="60">1 hour</option></select></label>
  </div>
  <div class="actions"><button class="btn" type="submit">Connect and sync now</button></div>
  <p class="muted tiny">The sheet needs the same columns as the files you already upload (the Sales desk and E-commerce desk formats). Sales sheets re-read the last 35 days; rows already saved are not duplicated.</p>
</form>

<section class="card card--wide">
  <div class="card__h"><h2>Keep it live when nobody is logged in</h2></div>
  <p class="muted">Syncing runs automatically while anyone uses DailyDesk. To make it run all day anyway, add this in cPanel → Cron Jobs, every 5 minutes:</p>
  <p><code style="word-break:break-all"><?= h($cron) ?></code></p>
</section>
<?php layout_foot();
