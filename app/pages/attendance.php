<?php
defined('DD') or exit;

$pdo = db();
$u = user();
$uid = (int)$u['id'];
$today = today();
$c = att_cfg();
$self = url('attendance');

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'punch') {
        $kind = (string)($_POST['kind'] ?? '');
        $ev = att_events([$uid], $today, $today)[$uid][$today] ?? [];
        $a = att_calc($ev, null, $today, $c);
        $allowed = [
            'working'  => ['lunch_start', 'tea_start', 'out'],
            'on_lunch' => ['lunch_end'],
            'on_tea'   => ['tea_end'],
            'done'     => ['in'],
            'not_in'   => ['in'],
        ][$a['state']] ?? [];
        if (in_array($kind, $allowed, true)) {
            att_add($uid, $kind);
            $msg = ['lunch_start' => 'Lunch break started. Enjoy your meal.', 'lunch_end' => 'Welcome back from lunch.', 'tea_start' => 'Tea break started.',
                    'tea_end' => 'Welcome back from your tea break.', 'out' => 'Day ended. Thank you for today.', 'in' => 'You are back at work.'][$kind];
            flash('success', $msg);
        }
        redirect($self);
    }

    if ($act === 'reason') {
        $kind = (string)($_POST['kind'] ?? '');
        $day = (string)($_POST['day'] ?? '');
        $reason = (string)($_POST['reason'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));
        $ci = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['claimed_in'] ?? '')) ? (string)$_POST['claimed_in'] : '';
        $co = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['claimed_out'] ?? '')) ? (string)$_POST['claimed_out'] : '';
        $gate = att_gate($u);
        $valid = ($kind === 'late' && $gate['late'] && $day === $today) || ($kind === 'missing' && in_array($day, $gate['missing'], true));
        if (!$valid) {
            flash('error', 'That is not waiting for a reason any more.');
        } elseif (!in_array($reason, ATT_REASONS, true)) {
            flash('error', 'Please choose a reason.');
        } elseif ($reason === 'Other' && $note === '') {
            flash('error', 'Please write a short note for "Other".');
        } else {
            $pdo->prepare('INSERT OR IGNORE INTO att_reasons (user_id, day, kind, reason, note, claimed_in, claimed_out, created_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$uid, $day, $kind, $reason, mb_substr($note, 0, 500), $ci, $co, date('Y-m-d H:i:s')]);
            flash('success', 'Thank you. Your reason is saved.');
        }
        redirect($self);
    }
}

/* ---------- data ---------- */
$gate = att_gate($u);
$pending = $gate['late'] || $gate['missing'];
$isWd = is_working_day($today);
$trackFrom = max($c['since'], substr((string)$u['created_at'], 0, 10));
$days = array_values(array_filter(array_slice(array_reverse(working_days(add_days($today, -30), $today)), 0, 14), fn($d) => $d >= $trackFrom));
$from = $days ? end($days) : $today;
$ev = att_events([$uid], $from, $today);
$seen = att_seen([$uid], $from, $today);
$rs = att_reasons([$uid], $from, $today);
$calc = [];
foreach ($days as $d) { $calc[$d] = att_apply_forgot(att_calc($ev[$uid][$d] ?? [], $seen[$uid][$d] ?? null, $d, $c), $rs[$uid][$d] ?? [], $d, $c); }
$now = att_apply_forgot(att_calc($ev[$uid][$today] ?? [], null, $today, $c), $rs[$uid][$today] ?? [], $today, $c);
$timeline = $ev[$uid][$today] ?? [];
$labels = ['in' => 'Started work', 'lunch_start' => 'Lunch started', 'lunch_end' => 'Back from lunch', 'tea_start' => 'Tea break started', 'tea_end' => 'Back from tea', 'out' => 'Ended the day'];
$stateTxt = ['not_in' => 'Not started', 'working' => 'Working', 'on_lunch' => 'On lunch break', 'on_tea' => 'On tea break', 'done' => 'Day ended'][$now['state']];
$lunchOver = $c['lunch'] > 0 && $now['lunch'] > $c['lunch'];
$teaOver = $c['tea'] > 0 && $now['tea'] > $c['tea'];
$csrf = csrf_field();
$btn = function (string $kind, string $label, string $cls = 'btn--ghost') use ($csrf, $self) {
    return '<form method="post" action="' . h($self) . '" class="punch">' . $csrf . '<input type="hidden" name="action" value="punch"><input type="hidden" name="kind" value="' . h($kind) . '"><button class="btn ' . $cls . '" type="submit">' . h($label) . '</button></form>';
};

layout_head('My day');
?>
<div class="pagehead">
  <div><h1>My day</h1><p class="muted"><?= h(fmt_date($today, true)) ?> · Start <?= h($c['start']) ?> · Lunch up to <?= (int)$c['lunch'] ?> min · Tea up to <?= (int)$c['tea'] ?> min</p></div>
</div>

<?php if ($pending): ?>
<section class="card card--alert" aria-label="Before you continue">
  <div class="card__h"><h2>Before you continue</h2></div>
  <p class="muted">Please tell us why. It takes ten seconds and is saved for your manager.</p>
  <?php
  $forms = [];
  if ($gate['late']) { $forms[] = ['late', $today, 'You logged in at ' . att_clock($now['in']) . ', which is ' . (int)$now['late'] . ' minutes after the ' . $c['start'] . ' start.']; }
  foreach ($gate['missing'] as $d) { $forms[] = ['missing', $d, 'No start was recorded on ' . fmt_date($d) . '.']; }
  foreach ($forms as [$kind, $day, $text]): ?>
    <form method="post" action="<?= h($self) ?>" class="reasonform">
      <?= $csrf ?><input type="hidden" name="action" value="reason"><input type="hidden" name="kind" value="<?= h($kind) ?>"><input type="hidden" name="day" value="<?= h($day) ?>">
      <p><strong><?= h($text) ?></strong></p>
      <div class="formgrid formgrid--3">
        <label class="field"><span>Reason</span><select name="reason" required><option value="">Choose…</option><?php foreach (ATT_REASONS as $r): ?><option><?= h($r) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Actual start <em>if you were working</em></span><input type="time" name="claimed_in"></label>
        <?php if ($kind === 'missing'): ?><label class="field"><span>Actual end <em>optional</em></span><input type="time" name="claimed_out"></label><?php endif; ?>
      </div>
      <label class="field"><span>Note <em>needed for "Other"</em></span><input type="text" name="note" maxlength="500" placeholder="A few words"></label>
      <div class="actions"><button class="btn" type="submit">Save reason</button></div>
    </form>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (!$isWd): ?>
  <section class="card"><h2>Today is an off day</h2><p class="muted">No start time is recorded on weekly offs or holidays.</p></section>
<?php else: ?>
<section class="card dayc" aria-label="Today">
  <div class="dayc__top">
    <div><span class="tgt__l">Right now</span>
      <div class="bignum"><strong><?= h($stateTxt) ?></strong>
        <?php if ($now['in']): ?><span class="muted">since <?= h(att_clock($now['open_since'])) ?></span><?php endif; ?>
        <?php if ($now['in'] && $now['is_late']): ?><span class="chip chip--warn">Late <?= (int)$now['late'] ?> min</span><?php elseif ($now['in']): ?><span class="chip chip--ok">On time</span><?php endif; ?></div></div>
  </div>
  <div class="stat4">
    <div><b><?= h(att_clock($now['in'])) ?></b><span>Started</span></div>
    <div><b><?= h(fmt_hm($now['net'])) ?></b><span>Working time so far</span></div>
    <div><b class="<?= $lunchOver ? 'bad' : '' ?>"><?= (int)$now['lunch'] ?> / <?= (int)$c['lunch'] ?> min</b><span>Lunch used</span></div>
    <div><b class="<?= $teaOver ? 'bad' : '' ?>"><?= (int)$now['tea'] ?> / <?= (int)$c['tea'] ?> min</b><span>Tea used (<?= (int)$now['teas'] ?> break<?= $now['teas'] === 1 ? '' : 's' ?>)</span></div>
  </div>
  <div class="punchrow">
    <?php
    switch ($now['state']) {
        case 'working':  echo $btn('lunch_start', 'Start lunch') . $btn('tea_start', 'Start tea break') . $btn('out', 'End my day', 'btn--danger'); break;
        case 'on_lunch': echo $btn('lunch_end', 'End lunch break', ''); break;
        case 'on_tea':   echo $btn('tea_end', 'End tea break', ''); break;
        case 'done':     echo $btn('in', 'I am back at work', ''); break;
        default:         echo $btn('in', 'Start my day', '');
    } ?>
  </div>
  <?php if ($timeline): ?>
    <ol class="timeline"><?php foreach ($timeline as $e): ?><li><b><?= h(att_clock($e['at'])) ?></b> <?= h($labels[$e['kind']] ?? $e['kind']) ?><?= $e['src'] === 'login' ? ' <span class="muted tiny">(first login)</span>' : '' ?></li><?php endforeach; ?></ol>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" aria-label="My last days">
  <div class="card__h"><h2>My last days</h2><span class="muted tiny">Your start, breaks and working time</span></div>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th>Date</th><th>Start</th><th>End</th><th class="r">Late</th><th class="r">Lunch</th><th class="r">Tea</th><th class="r">Working</th><th>Reason</th></tr></thead>
    <tbody>
    <?php foreach ($days as $d): $a = $calc[$d]; $why = $rs[$uid][$d]['late'] ?? ($rs[$uid][$d]['missing'] ?? null); ?>
      <tr>
        <td><?= h(fmt_date($d)) ?></td>
        <?php if ($a['in'] === null): ?>
          <td colspan="6" class="muted"><?= $d === $today ? 'Not started yet' : 'No start recorded' ?></td>
        <?php else: ?>
          <td><?= h(att_clock($a['in'])) ?></td>
          <td><?= $d === $today && $a['state'] !== 'done' ? '<span class="muted">Working</span>' : h(att_clock($a['out'])) . ($a['out_auto'] ? ' <span class="muted tiny" title="You did not press End my day">auto</span>' : '') ?></td>
          <td class="r <?= $a['is_late'] ? 'is-low' : '' ?>"><?= $a['is_late'] ? (int)$a['late'] . ' min' : '–' ?></td>
          <td class="r <?= ($c['lunch'] > 0 && $a['lunch'] > $c['lunch']) ? 'is-low' : '' ?>"><?= (int)$a['lunch'] ?>m</td>
          <td class="r <?= ($c['tea'] > 0 && $a['tea'] > $c['tea']) ? 'is-low' : '' ?>"><?= (int)$a['tea'] ?>m</td>
          <td class="r"><?= h(fmt_hm($a['net'])) ?></td>
        <?php endif; ?>
        <td class="tiny"><?= $why ? h($why['reason']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php layout_foot();
