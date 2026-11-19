<?php
defined('DD') or exit;

if (has_users()) {
    redirect(url('login'));
}

$errors = [];
$v = ['name' => '', 'email' => '', 'designation' => 'Management'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $key   = (string)($_POST['install_key'] ?? '');
    $v['name']        = trim((string)($_POST['name'] ?? ''));
    $v['email']       = strtolower(trim((string)($_POST['email'] ?? '')));
    $v['designation'] = (string)($_POST['designation'] ?? 'Management');
    $pass  = (string)($_POST['password'] ?? '');

    if (!hash_equals((string)cfg('install_key'), $key)) {
        $errors[] = 'The setup key is not correct. It is in config.php.';
    }
    if ($v['name'] === '' || mb_strlen($v['name']) > 80) {
        $errors[] = 'Enter your name.';
    }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email.';
    }
    if (mb_strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!in_array($v['designation'], cfg('designations'), true)) {
        $v['designation'] = 'Other';
    }
    if (!$errors) {
        db()->prepare('INSERT INTO users (name,email,password_hash,role,designation,active,must_change,created_at) VALUES (?,?,?,?,?,1,0,?)')
            ->execute([$v['name'], $v['email'], password_hash($pass, PASSWORD_DEFAULT), 'admin', $v['designation'], date('Y-m-d H:i:s')]);
        flash('ok', 'Setup done. Log in with your new admin account.');
        redirect(url('login'));
    }
}

layout_head('First-time setup', 'auth');
?>
<section class="auth">
  <div class="auth__card auth__card--wide">
    <div class="auth__brand"><span class="brand__mark" aria-hidden="true">D</span></div>
    <h1>First-time setup</h1>
    <p class="muted">Create the admin account. The admin adds employees and the CEO.</p>
    <?php foreach ($errors as $e): ?><div class="flash flash--error" role="alert"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label class="field"><span>Setup key <em>(from config.php)</em></span>
        <input type="text" name="install_key" required autocomplete="off">
      </label>
      <label class="field"><span>Your name</span>
        <input type="text" name="name" value="<?= h($v['name']) ?>" required maxlength="80">
      </label>
      <label class="field"><span>Email (used to log in)</span>
        <input type="email" name="email" value="<?= h($v['email']) ?>" required>
      </label>
      <label class="field"><span>Your designation</span>
        <select name="designation">
          <?php foreach (cfg('designations') as $d): ?>
            <option <?= $d === $v['designation'] ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Password <em>(8+ characters)</em></span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
      </label>
      <button class="btn btn--block" type="submit">Create admin account</button>
    </form>
  </div>
</section>
<?php layout_foot();
