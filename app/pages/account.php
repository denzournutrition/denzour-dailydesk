<?php
defined('DD') or exit;
$u = user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $cur  = (string)($_POST['current'] ?? '');
    $new  = (string)($_POST['new'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');
    if (!password_verify($cur, $u['password_hash'])) {
        $errors[] = 'Your current password is not correct.';
    }
    if (mb_strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $new2) {
        $errors[] = 'The two new passwords do not match.';
    }
    if ($new === $cur) {
        $errors[] = 'Choose a password different from the current one.';
    }
    if (!$errors) {
        db()->prepare('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        flash('ok', 'Password changed.');
        redirect(home_for($u));
    }
}

layout_head('Account');
?>
<div class="card card--narrow">
  <h1>Your account</h1>
  <p><a class="btn btn--ghost btn--sm" href="<?= h(url('profile')) ?>">Edit my profile and photo</a></p>
  <dl class="kv">
    <div><dt>Name</dt><dd><?= h($u['name']) ?></dd></div>
    <div><dt>Email</dt><dd><?= h($u['email']) ?></dd></div>
    <div><dt>Role</dt><dd><?= h($u['role'] === 'ceo' ? 'CEO (view only)' : ucfirst($u['role'])) ?></dd></div>
    <div><dt>Designation</dt><dd><?= h($u['designation']) ?></dd></div>
  </dl>
  <h2>Change password</h2>
  <?php if (!empty($u['must_change'])): ?><div class="flash flash--info">Your password was set by the admin. Choose your own to continue.</div><?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="flash flash--error" role="alert"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label class="field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
    <label class="field"><span>New password</span><input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
    <label class="field"><span>Repeat new password</span><input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn" type="submit">Save password</button>
  </form>
</div>
<?php layout_foot();
