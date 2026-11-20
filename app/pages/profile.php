<?php
defined('DD') or exit;
$pdo = db(); $me = user();
$id = (int)($_GET['id'] ?? $me['id']);
if (!can_view_profile($me, $id)) { redirect(url('profile')); }
$st = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]); $person = $st->fetch();
if (!$person) { redirect(url('profile')); }
$isMe = $id === (int)$me['id'];
$isAdmin = $me['role'] === 'admin';
$canEdit = $isMe || $isAdmin;
$pr = profile_get($id);
$roles = ['employee' => 'Employee', 'ceo' => 'CEO', 'admin' => 'Admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrf_verify();
    $act = $_POST['action'] ?? 'save';
    if ($act === 'removephoto') {
        if ($pr['photo'] !== '') { @unlink(avatar_dir() . '/' . basename($pr['photo'])); }
        profile_save($id, ['photo' => '']);
        flash('ok', 'Photo removed.');
    } else {
        $f = [
            'phone' => clean_phone((string)($_POST['phone'] ?? '')),
            'city'  => mb_substr(trim((string)($_POST['city'] ?? '')), 0, 60),
            'bio'   => mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 400),
        ];
        if ($isAdmin) {
            $j = trim((string)($_POST['joined_on'] ?? ''));
            $f['joined_on'] = valid_date($j) ? $j : '';
            $f['emp_code']  = mb_substr(trim((string)($_POST['emp_code'] ?? '')), 0, 20);
            $m = (int)($_POST['manager_id'] ?? 0);
            $f['manager_id'] = ($m && $m !== $id) ? $m : null;
        }
        [$photo, $err] = profile_photo_store($id, $_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if ($err) { flash('error', $err); }
        else {
            if ($photo) { $f['photo'] = $photo; }
            profile_save($id, $f);
            flash('ok', 'Profile saved.');
        }
    }
    redirect(url('profile', $isMe ? [] : ['id' => $id]));
}

$mgr = null;
if ($pr['manager_id']) { $s = $pdo->prepare('SELECT id,name FROM users WHERE id=?'); $s->execute([$pr['manager_id']]); $mgr = $s->fetch() ?: null; }
$managers = $isAdmin ? $pdo->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll() : [];
$month = date('Y-m');
$q = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id=? AND type='work' AND substr(report_date,1,7)=?"); $q->execute([$id, $month]); $nWork = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id=? AND type='leave' AND substr(report_date,1,7)=?"); $q->execute([$id, $month]); $nLeave = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT MAX(report_date) FROM reports WHERE user_id=?"); $q->execute([$id]); $lastRep = $q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id=?"); $q->execute([$id]); $nAll = (int)$q->fetchColumn();
$desks = [];
if (can_sales_desk($person)) { $desks[] = 'Sales desk'; }
if (can_ecom_desk($person)) { $desks[] = 'E-commerce desk'; }
if (can_view_dashboard($person)) { $desks[] = 'Dashboard'; }
$tenure = '';
if ($pr['joined_on'] !== '') {
    $d = (new DateTime($pr['joined_on']))->diff(new DateTime('today'));
    $tenure = $d->y ? $d->y . ' yr ' . $d->m . ' mo' : ($d->m ? $d->m . ' mo' : $d->d . ' days');
}
layout_head($isMe ? 'My profile' : $person['name']);
?>
<div class="pagehead"><div><h1><?= $isMe ? 'My profile' : h($person['name']) ?></h1><p class="muted"><?= $isMe ? 'This is how your team sees you. You can edit your photo and contact details.' : 'Employee profile' ?></p></div>
<div class="pagehead__r"><?php if (!$isMe && in_array($me['role'], ['admin', 'ceo'], true)): ?><a class="btn btn--ghost btn--sm" href="<?= h(url('team')) ?>">All team</a><?php endif; ?>
<?php if ($isMe): ?><a class="btn btn--ghost btn--sm" href="<?= h(url('account')) ?>">Change password</a><?php endif; ?></div></div>

<div class="prof">
  <section class="card prof__id">
    <?= avatar_html($person, 112, $pr['photo'] !== '' ? $pr['photo'] . '|' . $pr['updated_at'] : '') ?>
    <h2><?= h($person['name']) ?></h2>
    <p class="muted"><?= h($person['designation']) ?> · <?= h($roles[$person['role']] ?? '') ?></p>
    <?php if ($pr['bio'] !== ''): ?><p class="prof__bio"><?= nl2br(h($pr['bio'])) ?></p><?php endif; ?>
    <dl class="kv">
      <div><dt>Email</dt><dd><?= h($person['email']) ?></dd></div>
      <div><dt>Phone</dt><dd><?= $pr['phone'] !== '' ? h($pr['phone']) : '<span class="muted">Not added</span>' ?></dd></div>
      <div><dt>City</dt><dd><?= $pr['city'] !== '' ? h($pr['city']) : '<span class="muted">Not added</span>' ?></dd></div>
      <div><dt>Employee ID</dt><dd><?= $pr['emp_code'] !== '' ? h($pr['emp_code']) : '<span class="muted">Not set</span>' ?></dd></div>
      <div><dt>Joined</dt><dd><?= $pr['joined_on'] !== '' ? h(fmt_date($pr['joined_on'], true)) . ' <span class="muted">(' . h($tenure) . ')</span>' : '<span class="muted">Not set</span>' ?></dd></div>
      <div><dt>Reports to</dt><dd><?= $mgr ? (can_view_profile($me, (int)$mgr['id']) ? '<a href="' . h(url('profile', ['id' => (int)$mgr['id']])) . '">' . h($mgr['name']) . '</a>' : h($mgr['name'])) : '<span class="muted">Not set</span>' ?></dd></div>
      <?php if ($desks): ?><div><dt>Desks</dt><dd><?= h(implode(', ', $desks)) ?></dd></div><?php endif; ?>
    </dl>
  </section>

  <div class="prof__r">
    <?php if ($person['role'] !== 'ceo'): ?>
    <section class="kpis kpis--3">
      <div class="kpi"><span class="kpi__l">Reports this month</span><strong class="kpi__v"><?= $nWork ?></strong><span class="muted tiny"><?= $nLeave ?> leave day<?= $nLeave === 1 ? '' : 's' ?></span></div>
      <div class="kpi"><span class="kpi__l">Last report</span><strong class="kpi__v"><?= $lastRep ? h(fmt_date($lastRep)) : '-' ?></strong><span class="muted tiny"><?= $nAll ?> in total</span></div>
      <div class="kpi"><span class="kpi__l">With the team</span><strong class="kpi__v"><?= $tenure !== '' ? h($tenure) : '-' ?></strong><span class="muted tiny"><?= $tenure !== '' ? 'since ' . h(fmt_date($pr['joined_on'], true)) : 'joining date not set' ?></span></div>
    </section>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <section class="card">
      <h2>Edit <?= $isMe ? 'my' : h(explode(' ', $person['name'])[0]) . "'s" ?> details</h2>
      <form method="post" enctype="multipart/form-data" class="formrow">
        <?= csrf_field() ?><input type="hidden" name="action" value="save">
        <label class="field grow"><span>Photo <em>(JPG, PNG or WebP, up to 5 MB)</em></span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
        <label class="field"><span>Phone</span><input name="phone" value="<?= h($pr['phone']) ?>" maxlength="20" inputmode="tel" autocomplete="tel"></label>
        <label class="field"><span>City</span><input name="city" value="<?= h($pr['city']) ?>" maxlength="60"></label>
        <label class="field grow" style="flex-basis:100%"><span>About me <em>(max 400 characters)</em></span><textarea name="bio" rows="3" maxlength="400"><?= h($pr['bio']) ?></textarea></label>
        <?php if ($isAdmin): ?>
          <label class="field"><span>Employee ID</span><input name="emp_code" value="<?= h($pr['emp_code']) ?>" maxlength="20"></label>
          <label class="field"><span>Joining date</span><input type="date" name="joined_on" value="<?= h($pr['joined_on']) ?>"></label>
          <label class="field"><span>Reports to</span><select name="manager_id"><option value="0">Nobody</option><?php foreach ($managers as $m): if ((int)$m['id'] === $id) { continue; } ?><option value="<?= (int)$m['id'] ?>" <?= (int)$pr['manager_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <button class="btn" type="submit">Save profile</button>
      </form>
      <?php if ($pr['photo'] !== ''): ?>
      <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="removephoto"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Remove this photo?">Remove photo</button></form>
      <?php endif; ?>
      <?php if (!$isAdmin): ?><p class="muted tiny" style="margin-top:12px">Employee ID, joining date and manager are set by the admin.</p><?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php layout_foot();
