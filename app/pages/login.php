<?php
defined('DD') or exit;

if (user()) {
    redirect(home_for(user()));
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0';
    $ident = hash('sha256', $email . '|' . $ip);
    $pdo   = db();

    $pdo->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([time() - 3600]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ident = ? AND at > ?');
    $st->execute([$ident, time() - 900]);
    if ((int)$st->fetchColumn() >= 6) {
        $error = 'Too many wrong attempts. Please wait 15 minutes and try again.';
    } else {
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $st->execute([$email]);
        $row = $st->fetch();
        // Always run a hash check so timing does not reveal whether the email exists.
        $hash = $row['password_hash'] ?? password_hash('placeholder', PASSWORD_DEFAULT);
        if ($row && password_verify($pass, $hash)) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            unset($_SESSION['csrf']);
            $pdo->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $row['id']]);
            $pdo->prepare('DELETE FROM login_attempts WHERE ident = ?')->execute([$ident]);
            redirect(home_for($row));
        }
        $pdo->prepare('INSERT INTO login_attempts (ident, at) VALUES (?, ?)')->execute([$ident, time()]);
        $error = 'Email or password is not correct.';
    }
}

if (!$error && !empty($_SESSION['login_error'])) {
    $error = (string)$_SESSION['login_error'];
}
unset($_SESSION['login_error']);

layout_head('Log in', 'login');
?>
<main class="lg">
  <section class="lg__hero">
    <img class="lg__mark" src="<?= h(asset('logo-mark.png')) ?>" alt="">
    <span class="lg__tag"><?= icon('edit') ?><?= h(cfg('app_name')) ?></span>
    <h2>File.<br>Track.<br>Repeat.</h2>
    <p>Fill your daily work report in two minutes. Hours, tasks and blockers stay in one place for the whole team.</p>
    <div class="lg__dots" aria-hidden="true"><i></i><i></i><i></i></div>
  </section>

  <section class="lg__panel">
    <div class="lg__form">
      <img class="lg__logo" src="<?= h(asset('logo-light.png')) ?>" alt="<?= h(cfg('company')) ?>" width="210" height="65">
      <h1>Welcome Back!</h1>
      <p class="lg__sub">Log in to file your daily report, track your hours and keep the team in sync.</p>
      <?php if ($error): ?><div class="lg__err" role="alert"><?= icon('alert') ?><span><?= h($error) ?></span></div><?php endif; ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <label class="lg__f"><span>Email</span>
          <input type="email" name="email" value="<?= h($email) ?>" placeholder="you@denzour.com" required autofocus autocomplete="username" inputmode="email">
        </label>
        <label class="lg__f"><span>Password</span>
          <span class="lg__pw">
            <input type="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="lg__eye" data-eye aria-label="Show password" aria-pressed="false"><?= icon('eye') ?><?= icon('eyeoff') ?></button>
          </span>
        </label>
        <span class="lg__forgot">Forgot password? Ask your admin.</span>
        <button class="lg__btn" type="submit">Login</button>
      </form>
      <?php if (google_enabled()): ?>
      <div class="lg__or"><span>or</span></div>
      <a class="lg__google" href="<?= h(url('google')) ?>">
        <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.6 5.9c4.4-4.1 7-10.1 7-17.6z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.9 2.3-8.3 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
        Continue with Google
      </a>
      <?php endif; ?>
      <p class="lg__help">Need access? <strong>Ask your admin to add you.</strong></p>
    </div>
  </section>
</main>
<?php layout_foot();
