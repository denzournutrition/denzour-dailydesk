<?php
defined('DD') or exit;

/* Google Sheet live sync: the dashboard pulls a shared Google Sheet by itself every few minutes. */

function gsync_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS gsync_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL DEFAULT '',
        kind TEXT NOT NULL DEFAULT 'sales',
        user_id INTEGER NOT NULL DEFAULT 0,
        sheet_id TEXT NOT NULL,
        url TEXT NOT NULL DEFAULT '',
        every_min INTEGER NOT NULL DEFAULT 5,
        enabled INTEGER NOT NULL DEFAULT 1,
        last_run TEXT NOT NULL DEFAULT '',
        last_ok INTEGER NOT NULL DEFAULT 0,
        last_msg TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT ''
    )");
    // all Google Sheets refresh every 5 minutes (one-time switch for links made earlier)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '')");
        if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'gsync_5min'")->fetchColumn()) {
            $pdo->exec('UPDATE gsync_links SET every_min = 5');
            $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('gsync_5min', '1')");
        }
    } catch (Throwable $e) { /* ignore */ }
}

function gsync_sheet_id(string $url): string
{
    return preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $url, $m) ? $m[1] : '';
}

function gsync_key(): string
{
    $k = (string)cfg('install_key', 'dd');
    return substr(hash_hmac('sha256', 'gsync-cron-v2', $k), 0, 32); // v2: the first key was published by mistake, so it no longer works
}

function gsync_cron_url(): string
{
    return url('gsynccron', ['key' => gsync_key()]);
}

/** Downloads the whole workbook (all tabs) as .xlsx. Returns the temp file path. */
function gsync_fetch(string $sheetId): string
{
    $u = 'https://docs.google.com/spreadsheets/d/' . $sheetId . '/export?format=xlsx';
    $tmp = tempnam(sys_get_temp_dir(), 'gs');
    if (function_exists('curl_init')) {
        $fh = fopen($tmp, 'w');
        $c = curl_init($u);
        curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'DailyDesk-Sync/1.0', CURLOPT_MAXFILESIZE => 25 * 1024 * 1024]);
        $ok = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE); $err = curl_error($c);
        curl_close($c); fclose($fh);
        if (!$ok || $code >= 400) { @unlink($tmp); throw new RuntimeException($code === 404 || $code === 403 || $code === 401 ? 'Google refused access. Share the sheet: General access = Anyone with the link, Viewer.' : 'Could not reach Google' . ($err ? ' (' . $err . ')' : ' (HTTP ' . $code . ')') . '.'); }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'DailyDesk-Sync/1.0']]);
        $data = @file_get_contents($u, false, $ctx);
        if ($data === false) { @unlink($tmp); throw new RuntimeException('Could not reach Google. Ask the host to allow outgoing HTTPS (curl).'); }
        file_put_contents($tmp, $data);
    }
    if ((string)@file_get_contents($tmp, false, null, 0, 2) !== 'PK') {
        @unlink($tmp);
        throw new RuntimeException('Google did not return a spreadsheet. Share the sheet: General access = Anyone with the link, Viewer.');
    }
    return $tmp;
}

/** Runs one link now. Always updates the link's status; returns the status text. */
function gsync_run(int $id): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM gsync_links WHERE id = ?'); $st->execute([$id]);
    $l = $st->fetch();
    if (!$l) { return 'Link not found.'; }
    $pdo->prepare('UPDATE gsync_links SET last_run = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]); // claim it first so two visitors do not both run it
    $tmp = '';
    try {
        @set_time_limit(120);
        $tmp = gsync_fetch((string)$l['sheet_id']);
        $name = ($l['label'] !== '' ? $l['label'] : 'Google Sheet') . '.xlsx';
        if ($l['kind'] === 'targets') {
            $month = preg_match('/^\d{4}-\d{2}$/', (string)($l['month'] ?? '')) ? (string)$l['month'] : substr(today(), 0, 7);
            $msg = track_import($tmp, $month);
        } elseif ($l['kind'] === 'sales') {
            $uid = (int)$l['user_id'];
            if (!$uid) { throw new RuntimeException('Choose which sales person this sheet belongs to.'); }
            $from = date('Y-m-d', strtotime('-35 days'));
            $tabs = sales_annotate(sales_parse_file($tmp, $name), $uid, $from);
            $chosen = [];
            foreach ($tabs as $i => $t) { if (!empty($t['ok']) || (!empty($t['orders']) || !empty($t['calls']))) { $chosen[] = (string)$i; } }
            $r = sales_commit($uid, $name . ' (live)', $from, $tabs, $chosen);
            $msg = 'Added ' . $r['orders'] . ' new orders and ' . $r['calls'] . ' new calls (' . ($r['orders_dup'] + $r['calls_dup']) . ' already saved).';
        } else {
            $tabs = ecom_parse_file($tmp, $name);
            $chosen = [];
            foreach ($tabs as $i => $t) { if (!empty($t['ok'])) { $chosen[] = $i; } }
            if (!$chosen) { throw new RuntimeException('No sales or spend data found in this sheet.'); }
            $uid = (int)$l['user_id'] ?: (int)($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn());
            $n = ecom_commit($uid, $name . ' (live)', $tabs, $chosen, []);
            $msg = 'Updated ' . $n . ' platform-days.';
        }
        $pdo->prepare('UPDATE gsync_links SET last_ok = 1, last_msg = ? WHERE id = ?')->execute([$msg, $id]);
        return $msg;
    } catch (Throwable $e) {
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Could not read this sheet: ' . $e->getMessage();
        $pdo->prepare('UPDATE gsync_links SET last_ok = 0, last_msg = ? WHERE id = ?')->execute([mb_substr($msg, 0, 300), $id]);
        return $msg;
    } finally {
        if ($tmp !== '') { @unlink($tmp); }
    }
}

/** Runs every link that is due. Called after a page has been sent, and by the cron URL. */
function gsync_run_due(): int
{
    $n = 0;
    $now = time();
    foreach (db()->query('SELECT id, every_min, last_run FROM gsync_links WHERE enabled = 1')->fetchAll() as $l) {
        $due = $l['last_run'] === '' || $now - strtotime($l['last_run']) >= max(5, (int)$l['every_min']) * 60;
        if ($due) { gsync_run((int)$l['id']); $n++; if ($n >= 3) { break; } }
    }
    $n += conn_run_due();
    return $n;
}

/** Cheap hook for normal page views: at most one check a minute, runs after the response is sent. */
function gsync_auto(): void
{
    $f = dirname(__DIR__) . '/data/gsync.tick';
    if ((int)@filemtime($f) > time() - 60) { return; }
    @touch($f);
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        try { gsync_run_due(); } catch (Throwable $e) { /* never break a page */ }
    });
}

/** Self-service card for a desk page: the person connects their own Google Sheet. */
function gsync_card(string $kind, int $uid, string $back): void
{
    $st = db()->prepare('SELECT * FROM gsync_links WHERE kind = ? AND user_id = ? ORDER BY id');
    $st->execute([$kind, $uid]);
    $links = $st->fetchAll();
    $act = url('mysheet');
    $csrf = csrf_field();
    $hidden = $csrf . '<input type="hidden" name="back" value="' . h($back) . '"><input type="hidden" name="kind" value="' . h($kind) . '"><input type="hidden" name="uid" value="' . $uid . '">';
    ?>
<section class="card card--wide" id="gsheet">
  <div class="card__h"><h2>Google Sheet (live)</h2><span class="muted tiny">Updates your dashboard by itself</span></div>
  <?php foreach ($links as $l): ?>
    <div class="dlrow">
      <div><strong><?= h($l['label'] !== '' ? $l['label'] : 'Google Sheet') ?></strong><?= $l['enabled'] ? '' : ' <span class="chip">Paused</span>' ?><br>
        <span class="muted tiny"><?= $l['last_run'] !== '' ? ($l['last_ok'] ? 'Last synced ' : 'Problem at ') . h(date('d M, g:i a', strtotime($l['last_run']))) . ' · ' : 'Not synced yet · ' ?><?= h($l['last_msg']) ?> · every <?= (int)$l['every_min'] ?> min</span></div>
      <form method="post" action="<?= h($act) ?>" style="white-space:nowrap"><?= $hidden ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <button class="btn btn--ghost btn--sm" name="action" value="now" type="submit">Sync now</button>
        <button class="btn btn--ghost btn--sm" name="action" value="toggle" type="submit"><?= $l['enabled'] ? 'Pause' : 'Resume' ?></button>
        <button class="btn btn--ghost btn--sm" name="action" value="delete" type="submit" data-confirm="Remove this Google Sheet link? Data already imported stays.">Remove</button></form>
    </div>
  <?php endforeach; ?>
  <form method="post" action="<?= h($act) ?>"><?= $hidden ?><input type="hidden" name="action" value="add">
    <p class="muted tiny"><?= $links ? 'Add another sheet.' : 'In Google Sheets click Share, set General access to Anyone with the link (Viewer), then paste the link here.' ?></p>
    <div class="formgrid formgrid--3">
      <label class="field grow"><span>Google Sheet link</span><input type="url" name="url" required placeholder="https://docs.google.com/spreadsheets/d/…"></label>
      <label class="field"><span>Name <em>optional</em></span><input type="text" name="label" maxlength="80"></label>
      <label class="field"><span>Refresh every</span><select name="every"><option value="5">5 minutes</option><option value="15" selected>15 minutes</option><option value="30">30 minutes</option><option value="60">1 hour</option></select></label>
    </div>
    <div class="actions"><button class="btn" type="submit">Connect and sync now</button></div>
  </form>
</section>
<?php
}
