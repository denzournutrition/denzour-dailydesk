<?php
defined('DD') or exit;
$pdo = db(); $me = user();
$desigs = cfg('designations');
$roles = ['employee' => 'Employee', 'ceo' => 'CEO (view only)', 'admin' => 'Admin'];
$newPass = null;

function gen_pass(): string
{
    $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
    for ($i = 0; $i < 10; $i++) { $s .= $c[random_int(0, strlen($c) - 1)]; }
    return $s;
}
function admin_count(PDO $pdo): int { return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn(); }
function role_of(PDO $pdo, int $id): ?string { $s = $pdo->prepare('SELECT role FROM users WHERE id=?'); $s->execute([$id]); $r = $s->fetchColumn(); return $r === false ? null : $r; }
function email_taken(PDO $pdo, string $e, int $except = 0): bool { $s = $pdo->prepare('SELECT 1 FROM users WHERE email=? AND id<>?'); $s->execute([$e, $except]); return (bool)$s->fetchColumn(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? '')); $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $role = array_key_exists($_POST['role'] ?? '', $roles) ? $_POST['role'] : 'employee';
    $des = in_array($_POST['designation'] ?? '', $desigs, true) ? $_POST['designation'] : 'Other';
    $dash = !empty($_POST['dash_access']) ? 1 : 0;
    $salesAcc = !empty($_POST['sales_access']) ? 1 : 0;
    $ecomAcc = !empty($_POST['ecom_access']) ? 1 : 0;
    $pw = (string)($_POST['password'] ?? '');
    $lastAdminGuard = fn(int $uid) => role_of($pdo, $uid) === 'admin' && admin_count($pdo) <= 1;

    if ($act === 'add') {
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a name and a valid email.'); }
        elseif (email_taken($pdo, $email)) { flash('error', 'That email is already added.'); }
        elseif ($pw !== '' && strlen($pw) < 8) { flash('error', 'Password must be at least 8 characters.'); }
        else {
            $given = $pw === '' ? gen_pass() : $pw;
            $pdo->prepare('INSERT INTO users (name,email,password_hash,role,designation,active,must_change,dash_access,sales_access,ecom_access,created_at) VALUES (?,?,?,?,?,1,?,?,?,?,?)')
                ->execute([mb_substr($name, 0, 80), $email, password_hash($given, PASSWORD_DEFAULT), $role, $des, !empty($_POST['must_change']) ? 1 : 0, $dash, $salesAcc, $ecomAcc, date('Y-m-d H:i:s')]);
            $newPass = [$name, $email, $given];
        }
    } elseif ($act === 'update' && $id) {
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a name and a valid email.'); }
        elseif (email_taken($pdo, $email, $id)) { flash('error', 'Another person already uses that email.'); }
        elseif ($role !== 'admin' && $lastAdminGuard($id)) { flash('error', 'There must be at least one admin.'); }
        elseif ($pw !== '' && strlen($pw) < 8) { flash('error', 'New password must be at least 8 characters.'); }
        else {
            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, designation=?, dash_access=?, sales_access=?, ecom_access=? WHERE id=?')->execute([mb_substr($name, 0, 80), $email, $role, $des, $dash, $salesAcc, $ecomAcc, $id]);
            if ($pw !== '') {
                $pdo->prepare('UPDATE users SET password_hash=?, must_change=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), !empty($_POST['must_change']) ? 1 : 0, $id]);
                flash('ok', 'Saved. Password changed.');
            } else { flash('ok', 'Saved.'); }
        }
    } elseif ($act === 'reset' && $id) {
        $given = gen_pass();
        $pdo->prepare('UPDATE users SET password_hash=?, must_change=1 WHERE id=?')->execute([password_hash($given, PASSWORD_DEFAULT), $id]);
        $r = $pdo->prepare('SELECT name,email FROM users WHERE id=?'); $r->execute([$id]); $r = $r->fetch();
        if ($r) { $newPass = [$r['name'], $r['email'], $given]; }
    } elseif ($act === 'deactivate' && $id) {
        if ($id === (int)$me['id'] || $lastAdminGuard($id)) { flash('error', 'You cannot deactivate yourself or the last admin.'); }
        else { $pdo->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$id]); flash('ok', 'Deactivated. They cannot log in; past reports are kept.'); }
    } elseif ($act === 'activate' && $id) {
        $pdo->prepare('UPDATE users SET active=1 WHERE id=?')->execute([$id]); flash('ok', 'Reactivated.');
    } elseif ($act === 'delete' && $id) {
        if ($id === (int)$me['id'] || $lastAdminGuard($id)) { flash('error', 'You cannot delete yourself or the last admin.'); }
        else { $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]); flash('ok', 'Person and all their reports deleted permanently.'); }
    }
    if (!$newPass) { redirect(url('people')); }
}
$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM reports r WHERE r.user_id=u.id) AS nrep FROM users u ORDER BY active DESC, CASE role WHEN 'admin' THEN 0 WHEN 'ceo' THEN 1 ELSE 2 END, name")->fetchAll();
layout_head('People');
?>
<div class="pagehead"><div><h1>People &amp; access</h1><p class="muted">Add, edit, set passwords, give dashboard access, deactivate or delete. You can add as many CEOs as you need.</p></div></div>

<?php if ($newPass): ?>
<div class="card reveal" role="alert">
  <h2>Login for <?= h($newPass[0]) ?></h2>
  <p>Share this safely. It is shown only once.</p>
  <dl class="cred"><dt>Login page</dt><dd><?= h(($GLOBALS['https'] ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . url()) ?></dd><dt>Email</dt><dd><?= h($newPass[1]) ?></dd><dt>Password</dt><dd><code><?= h($newPass[2]) ?></code></dd></dl>
</div>
<?php endif; ?>

<details class="card addbox" <?= $newPass ? '' : 'open' ?>>
  <summary><h2 style="display:inline">+ Add a person</h2></summary>
  <form method="post" class="formrow" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="field grow"><span>Name</span><input name="name" required maxlength="80"></label>
    <label class="field grow"><span>Email</span><input type="email" name="email" required autocomplete="off"></label>
    <label class="field"><span>Role</span><select name="role"><?php foreach ($roles as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Designation</span><select name="designation"><?php foreach ($desigs as $d): ?><option><?= h($d) ?></option><?php endforeach; ?></select></label>
    <label class="field grow"><span>Password <em>(leave empty to auto-generate)</em></span><input type="text" name="password" minlength="8" autocomplete="off"></label>
    <label class="check"><input type="checkbox" name="must_change" value="1" checked> Ask to change at first login</label>
    <label class="check"><input type="checkbox" name="dash_access" value="1"> Can view dashboard</label>
    <label class="check"><input type="checkbox" name="sales_access" value="1"> Can upload sales sheets (Sales desk)</label>
    <label class="check"><input type="checkbox" name="ecom_access" value="1"> Can update e-commerce sales</label>
    <button class="btn" type="submit">Add person</button>
  </form>
</details>

<p class="muted tiny"><?= count($users) ?> people · CEOs and admins always see the dashboard. Tick "Can view dashboard" to give it to any other person.</p>
<div class="list">
<?php foreach ($users as $u): ?>
  <details class="item person <?= $u['active'] ? '' : 'is-off' ?>">
    <summary>
      <span class="person__n"><strong><?= h($u['name']) ?></strong><small><?= h($u['email']) ?></small></span>
      <span class="chip"><?= h($roles[$u['role']]) ?></span>
      <?php if (($u['designation'] === 'Inside Sales' || !empty($u['sales_access'])) && $u['role'] === 'employee'): ?><span class="chip chip--ok">Sales desk</span><?php endif; ?>
      <?php if (($u['designation'] === 'E-commerce Specialist' || !empty($u['ecom_access'])) && $u['role'] === 'employee'): ?><span class="chip chip--ok">E-commerce desk</span><?php endif; ?>
      <?php if ($u['dash_access'] && $u['role'] === 'employee'): ?><span class="chip chip--ok">Dashboard access</span><?php endif; ?>
      <?php if (!$u['active']): ?><span class="chip chip--bad">Inactive</span><?php endif; ?>
      <span class="muted tiny"><?= (int)$u['nrep'] ?> reports · <?= $u['last_login'] ? 'last login ' . h(date('j M, g:i a', strtotime($u['last_login']))) : 'never logged in' ?></span>
    </summary>
    <form method="post" class="item__body formrow">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <label class="field grow"><span>Name</span><input name="name" value="<?= h($u['name']) ?>" required maxlength="80"></label>
      <label class="field grow"><span>Email</span><input type="email" name="email" value="<?= h($u['email']) ?>" required></label>
      <label class="field"><span>Role</span><select name="role"><?php foreach ($roles as $k => $l): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Designation</span><select name="designation"><?php foreach ($desigs as $d): ?><option <?= $u['designation'] === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select></label>
      <label class="field grow"><span>Set new password <em>(optional, 8+ characters)</em></span><input type="text" name="password" minlength="8" autocomplete="off" placeholder="Leave empty to keep current"></label>
      <label class="check"><input type="checkbox" name="must_change" value="1"> Ask to change it at next login</label>
      <label class="check"><input type="checkbox" name="dash_access" value="1" <?= $u['dash_access'] ? 'checked' : '' ?>> Can view dashboard</label>
      <label class="check"><input type="checkbox" name="sales_access" value="1" <?= !empty($u['sales_access']) || $u['designation'] === 'Inside Sales' ? 'checked' : '' ?>> Can upload sales sheets (Sales desk)</label>
      <label class="check"><input type="checkbox" name="ecom_access" value="1" <?= !empty($u['ecom_access']) || $u['designation'] === 'E-commerce Specialist' ? 'checked' : '' ?>> Can update e-commerce sales</label>
      <div class="person__a">
        <button class="btn btn--sm" name="action" value="update">Save changes</button>
        <button class="btn btn--ghost btn--sm" name="action" value="reset" data-confirm="Generate a new random password for <?= h($u['name']) ?>?">Random password</button>
        <?php if ($u['active']): ?><button class="btn btn--ghost btn--sm" name="action" value="deactivate" data-confirm="Deactivate <?= h($u['name']) ?>? They can no longer log in.">Deactivate</button>
        <?php else: ?><button class="btn btn--ghost btn--sm" name="action" value="activate">Reactivate</button><?php endif; ?>
        <button class="btn btn--danger btn--sm" name="action" value="delete" data-confirm="Delete <?= h($u['name']) ?> and ALL their reports permanently? This cannot be undone.">Delete permanently</button>
      </div>
    </form>
  </details>
<?php endforeach; ?>
</div>
<?php layout_foot();
