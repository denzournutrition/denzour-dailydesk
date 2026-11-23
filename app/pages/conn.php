<?php
defined('DD') or exit;
$self = url('conn');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $kind = (string)($_POST['kind'] ?? '');
    $act = (string)($_POST['action'] ?? '');
    if (isset(CONN_KINDS[$kind])) {
        if ($act === 'save' || $act === 'savesync') {
            try {
                conn_save($kind, $_POST);
                flash('ok', CONN_KINDS[$kind][0] . ' saved.');
                if ($act === 'savesync' && conn_ready($kind)) { $m = conn_run($kind); flash(db()->query("SELECT last_ok FROM conn WHERE kind = " . db()->quote($kind))->fetchColumn() ? 'ok' : 'error', $m); }
            } catch (Throwable $e) { flash('error', 'Could not save: ' . $e->getMessage()); }
        } elseif ($act === 'sync') {
            $m = conn_run($kind);
            flash(db()->query("SELECT last_ok FROM conn WHERE kind = " . db()->quote($kind))->fetchColumn() ? 'ok' : 'error', $m);
        } elseif ($act === 'remove') {
            db()->prepare('DELETE FROM conn WHERE kind = ?')->execute([$kind]);
            flash('ok', CONN_KINDS[$kind][0] . ' disconnected. Data already collected is kept.');
        }
    }
    redirect($self);
}
layout_head('Connections');
?>
<div class="pagehead"><div><h1>Connections</h1><p class="muted">Connect Meta Ads, Google Ads and Instagram so their numbers arrive by themselves. Keys are kept encrypted and are never shown again.</p></div></div>
<?php foreach (CONN_KINDS as $kind => [$title, $what]): $c = conn_get($kind); $row = $c['row']; $ready = conn_ready($kind); ?>
<section class="card">
  <div class="card__h"><h2><?= h($title) ?></h2>
    <?php if ($row && $row['last_run'] !== ''): ?><span class="chip <?= $row['last_ok'] ? 'chip--ok' : 'chip--warn' ?>"><?= $row['last_ok'] ? 'Working' : 'Needs attention' ?></span><?php elseif ($ready): ?><span class="chip">Saved</span><?php else: ?><span class="chip">Not connected</span><?php endif; ?></div>
  <p class="muted" style="margin:0 0 12px"><?= h($what) ?>.</p>
  <?php if ($row && $row['last_run'] !== ''): ?><p class="tiny muted" style="margin:0 0 12px">Last run <?= h(date('j M, g:i a', strtotime($row['last_run']))) ?>: <?= h($row['last_msg']) ?></p><?php endif; ?>
  <form method="post" action="<?= h($self) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="kind" value="<?= h($kind) ?>">
    <div class="formgrid formgrid--3">
    <?php foreach (CONN_FIELDS[$kind] as $f => [$label, $isSecret, $hint, $def]): $has = $isSecret ? !empty($c['secrets'][$f]) : false; $val = $isSecret ? '' : ($c['cfg'][$f] ?? $def); ?>
      <label class="field"><span><?= h($label) ?><?= $isSecret && $has ? ' <em>(saved)</em>' : '' ?></span>
        <input type="<?= $isSecret ? 'password' : 'text' ?>" name="<?= h($f) ?>" value="<?= h((string)$val) ?>" <?= $isSecret ? 'placeholder="' . ($has ? 'Leave empty to keep the saved one' : 'Paste it here') . '" autocomplete="new-password"' : '' ?>>
        <?php if ($hint): ?><small class="muted tiny"><?= h($hint) ?></small><?php endif; ?></label>
    <?php endforeach; ?>
    </div>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      <button class="btn" type="submit" name="action" value="savesync">Save and fetch now</button>
      <button class="btn btn--ghost" type="submit" name="action" value="save">Save only</button>
      <?php if ($row): ?><button class="btn btn--ghost" type="submit" name="action" value="remove" formnovalidate data-confirm="Disconnect <?= h($title) ?>? The saved key is deleted. Data already collected stays.">Disconnect</button><?php endif; ?>
    </div>
  </form>
</section>
<?php endforeach; ?>
<p class="muted tiny">After the first fetch, each connection refreshes by itself about every 3 hours while people use DailyDesk. Meta and Google update the last 45 days each time, so late-counted sales are caught.</p>
<?php layout_foot();
