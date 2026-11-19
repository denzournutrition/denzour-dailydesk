<?php
defined('DD') or exit;
$me  = user();
$pdo = db();
$u   = $me;
$uidQ = [];
if ($me['role'] === 'admin' && (int)($_GET['uid'] ?? $_POST['uid'] ?? 0) > 0) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)($_GET['uid'] ?? $_POST['uid'])]);
    if ($row = $st->fetch()) { $u = $row; $uidQ = ['uid' => (int)$row['id']]; }
}
$forOther = (int)$u['id'] !== (int)$me['id'];
$win = (int)cfg('edit_window_days', 7);
$min = add_days(today(), -$win);
$max = today();

$date = $_GET['date'] ?? ($_POST['date'] ?? $max);
if (!is_string($date) || !valid_date($date)) {
    $date = $max;
}
$inWindow = $forOther ? ($date <= $max) : ($date >= $min && $date <= $max);

$cats = cfg('categories');
$errors = [];

/** Load an existing report for the chosen date. */
function load_report(PDO $pdo, int $uid, string $date): ?array
{
    $st = $pdo->prepare('SELECT * FROM reports WHERE user_id = ? AND report_date = ?');
    $st->execute([$uid, $date]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $t = $pdo->prepare('SELECT * FROM tasks WHERE report_id = ? ORDER BY id');
    $t->execute([$r['id']]);
    $r['tasks'] = $t->fetchAll();
    return $r;
}

$existing = load_report($pdo, (int)$u['id'], $date);
$form = [
    'type'  => $existing['type'] ?? 'work',
    'note'  => $existing['note'] ?? '',
    'tasks' => $existing['tasks'] ?? [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ($forOther && $existing && !empty($_POST['delete'])) {
        $pdo->prepare('DELETE FROM reports WHERE id = ?')->execute([$existing['id']]);
        flash('ok', 'Report deleted.');
        redirect(url('allreports'));
    }
    if (!$inWindow) {
        $errors[] = 'This date is outside the days you can edit. Ask your admin for help.';
    }
    $type = (($_POST['type'] ?? 'work') === 'leave') ? 'leave' : 'work';
    $note = trim((string)($_POST['note'] ?? ''));
    $note = mb_substr($note, 0, 1500);

    $titles  = (array)($_POST['title'] ?? []);
    $cat     = (array)($_POST['category'] ?? []);
    $hrs     = (array)($_POST['hours'] ?? []);
    $stat    = (array)($_POST['status'] ?? []);
    $rem     = (array)($_POST['remarks'] ?? []);
    $tasks   = [];
    $total   = 0.0;
    $maxTask = (float)cfg('max_hours_task', 16);

    if ($type === 'work') {
        foreach ($titles as $i => $title) {
            $title = trim((string)$title);
            if ($title === '') {
                continue;
            }
            $hour = str_replace(',', '.', trim((string)($hrs[$i] ?? '0')));
            if (!is_numeric($hour) || (float)$hour < 0 || (float)$hour > $maxTask) {
                $errors[] = 'Hours for "' . mb_substr($title, 0, 40) . '" must be between 0 and ' . $maxTask . '.';
                $hour = 0;
            }
            $hour = round((float)$hour * 4) / 4; // quarter-hour steps
            $c = (string)($cat[$i] ?? 'Other');
            $s = (string)($stat[$i] ?? 'done');
            $tasks[] = [
                'title'    => mb_substr($title, 0, 300),
                'category' => in_array($c, $cats, true) ? $c : 'Other',
                'hours'    => $hour,
                'status'   => in_array($s, ['done', 'in_progress', 'blocked'], true) ? $s : 'done',
                'remarks'  => mb_substr(trim((string)($rem[$i] ?? '')), 0, 500),
            ];
            $total += $hour;
        }
        if (!$tasks) {
            $errors[] = 'Add at least one task, or tick "I was on leave".';
        }
        if ($total > (float)cfg('max_hours_day', 24)) {
            $errors[] = 'Total hours for one day cannot be more than ' . cfg('max_hours_day', 24) . '.';
        }
    }

    $form = ['type' => $type, 'note' => $note, 'tasks' => $tasks];

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            if ($existing) {
                $rid = (int)$existing['id'];
                $pdo->prepare('UPDATE reports SET type = ?, note = ?, updated_at = ? WHERE id = ?')
                    ->execute([$type, $note, $now, $rid]);
                $pdo->prepare('DELETE FROM tasks WHERE report_id = ?')->execute([$rid]);
            } else {
                $pdo->prepare('INSERT INTO reports (user_id, report_date, type, note, submitted_at, updated_at) VALUES (?,?,?,?,?,?)')
                    ->execute([$u['id'], $date, $type, $note, $now, $now]);
                $rid = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO tasks (report_id, title, category, hours, status, remarks) VALUES (?,?,?,?,?,?)');
            foreach ($tasks as $t) {
                $ins->execute([$rid, $t['title'], $t['category'], $t['hours'], $t['status'], $t['remarks']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('DailyDesk save failed: ' . $e->getMessage());
            $errors[] = 'Could not save. Please try again.';
        }
        if (!$errors) {
            flash('ok', $type === 'leave'
                ? 'Saved. Marked as leave for ' . fmt_date($date) . '.'
                : 'Report saved for ' . fmt_date($date) . ' · ' . fmt_hours($total) . ' hours.');
            redirect(url('report', ['date' => $date] + $uidQ));
        }
    }
}

if (!$form['tasks'] && $form['type'] === 'work') {
    $form['tasks'][] = ['title' => '', 'category' => 'Execution', 'hours' => '', 'status' => 'done', 'remarks' => ''];
}

$total = 0.0;
foreach ($form['tasks'] as $t) {
    $total += (float)$t['hours'];
}

layout_head('Daily report');
?>
<div class="pagehead">
  <div>
    <h1>Daily report</h1>
    <?php if ($forOther): ?><p class="muted"><strong>Admin edit</strong> for <?= h($u['name']) ?> (<?= h($u['designation']) ?>). No date limit. <a href="<?= h(url('allreports')) ?>">Back to all reports</a></p>
    <?php else: ?><p class="muted">Fill what you worked on. You can edit it for <?= $win ?> days.</p><?php endif; ?>
  </div>
  <div class="datenav" aria-label="Choose date">
    <?php $prev = add_days($date, -1); $next = add_days($date, 1); ?>
    <a class="btn btn--ghost btn--sm <?= (!$forOther && $prev < $min) ? 'is-disabled' : '' ?>" href="<?= h(url('report', ['date' => $prev] + $uidQ)) ?>" <?= (!$forOther && $prev < $min) ? 'aria-disabled="true" tabindex="-1"' : '' ?>>‹ Prev</a>
    <form method="get" action="<?= h(url()) ?>" class="datenav__pick">
      <input type="hidden" name="p" value="report"><?php if ($uidQ): ?><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>"><?php endif; ?>
      <input type="date" name="date" value="<?= h($date) ?>" <?= $forOther ? '' : 'min="' . h($min) . '"' ?> max="<?= h($max) ?>" data-autosubmit aria-label="Report date">
    </form>
    <a class="btn btn--ghost btn--sm <?= $next > $max ? 'is-disabled' : '' ?>" href="<?= h(url('report', ['date' => $next] + $uidQ)) ?>" <?= $next > $max ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next ›</a>
  </div>
</div>

<div class="statusline">
  <strong><?= h(fmt_date($date, true)) ?></strong>
  <?php if ($existing): ?>
    <span class="chip chip--ok">Filed</span>
    <span class="muted tiny">Last saved <?= h(date('j M, g:i a', strtotime($existing['updated_at']))) ?></span>
  <?php else: ?>
    <span class="chip chip--warn">Not filed yet</span>
  <?php endif; ?>
  <?php if (!$inWindow): ?><span class="chip">Locked</span><?php endif; ?>
</div>

<?php foreach ($errors as $e): ?><div class="flash flash--error" role="alert"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="reportform" id="reportform" data-max-day="<?= h((string)cfg('max_hours_day', 24)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="date" value="<?= h($date) ?>">
  <?php if ($uidQ): ?><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>"><?php endif; ?>

  <label class="leave">
    <input type="checkbox" name="type" value="leave" id="leavebox" <?= $form['type'] === 'leave' ? 'checked' : '' ?> <?= $inWindow ? '' : 'disabled' ?>>
    <span>I was on leave this day</span>
  </label>

  <div id="taskarea" <?= $form['type'] === 'leave' ? 'hidden' : '' ?>>
    <div class="tasks" id="tasks">
      <?php foreach ($form['tasks'] as $i => $t): ?>
      <fieldset class="task" <?= $inWindow ? '' : 'disabled' ?>>
        <legend class="sr">Task <?= $i + 1 ?></legend>
        <label class="field field--title"><span>What did you work on?</span>
          <textarea name="title[]" rows="2" maxlength="300" placeholder="e.g. Published 2 blogs on whey protein, built backlinks on 10 sites"><?= h($t['title']) ?></textarea>
        </label>
        <div class="task__row">
          <label class="field"><span>Category</span>
            <select name="category[]">
              <?php foreach ($cats as $c): ?><option <?= ($t['category'] ?? '') === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span>Hours</span>
            <input type="number" name="hours[]" value="<?= h($t['hours'] === '' ? '' : fmt_hours((float)$t['hours'])) ?>" min="0" max="<?= h((string)cfg('max_hours_task', 16)) ?>" step="0.25" inputmode="decimal" placeholder="0">
          </label>
          <label class="field"><span>Status</span>
            <select name="status[]">
              <?php foreach (['done' => 'Done', 'in_progress' => 'In progress', 'blocked' => 'Blocked'] as $k => $l): ?>
                <option value="<?= h($k) ?>" <?= ($t['status'] ?? 'done') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <label class="field"><span>Remarks <em>(optional)</em></span>
          <input type="text" name="remarks[]" maxlength="500" value="<?= h($t['remarks'] ?? '') ?>" placeholder="Result, link, or what is stopping you">
        </label>
        <button type="button" class="task__remove" data-remove aria-label="Remove this task">Remove task</button>
      </fieldset>
      <?php endforeach; ?>
    </div>
    <?php if ($inWindow): ?>
    <button type="button" class="btn btn--ghost" id="addtask">+ Add another task</button>
    <?php endif; ?>
  </div>

  <label class="field note">
    <span>Notes for tomorrow or anything blocking you <em>(optional)</em></span>
    <textarea name="note" rows="3" maxlength="1500" <?= $inWindow ? '' : 'disabled' ?>><?= h($form['note']) ?></textarea>
  </label>

  <div class="savebar">
    <div class="savebar__total"><span class="tiny muted">Total today</span><strong id="totalhours"><?= h(fmt_hours($total)) ?></strong> <span class="tiny muted">hours</span></div>
    <?php if ($inWindow): ?>
      <?php if ($forOther && $existing): ?><button class="btn btn--ghost" type="submit" name="delete" value="1" formnovalidate data-confirm="Delete this whole report permanently?">Delete report</button><?php endif; ?>
      <button class="btn" type="submit" id="savebtn"><?= $existing ? 'Update report' : 'Submit report' ?></button>
    <?php endif; ?>
  </div>
</form>

<template id="tasktpl">
  <fieldset class="task">
    <legend class="sr">Task</legend>
    <label class="field field--title"><span>What did you work on?</span>
      <textarea name="title[]" rows="2" maxlength="300" placeholder="e.g. Published 2 blogs on whey protein, built backlinks on 10 sites"></textarea>
    </label>
    <div class="task__row">
      <label class="field"><span>Category</span>
        <select name="category[]"><?php foreach ($cats as $c): ?><option <?= $c === 'Execution' ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field"><span>Hours</span>
        <input type="number" name="hours[]" min="0" max="<?= h((string)cfg('max_hours_task', 16)) ?>" step="0.25" inputmode="decimal" placeholder="0">
      </label>
      <label class="field"><span>Status</span>
        <select name="status[]"><option value="done" selected>Done</option><option value="in_progress">In progress</option><option value="blocked">Blocked</option></select>
      </label>
    </div>
    <label class="field"><span>Remarks <em>(optional)</em></span>
      <input type="text" name="remarks[]" maxlength="500" placeholder="Result, link, or what is stopping you">
    </label>
    <button type="button" class="task__remove" data-remove aria-label="Remove this task">Remove task</button>
  </fieldset>
</template>
<?php layout_foot();
