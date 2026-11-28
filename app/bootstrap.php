<?php
declare(strict_types=1);
defined("DD") or exit;

$CFG = require __DIR__ . '/../config.php';
date_default_timezone_set($CFG['timezone']);
mb_internal_encoding('UTF-8');
require __DIR__ . '/sales.php';
require __DIR__ . '/attendance.php';
require __DIR__ . '/ecom.php';
require __DIR__ . '/gsync.php';
require __DIR__ . '/track.php';
require __DIR__ . '/routine.php';
require __DIR__ . '/conn.php';
require __DIR__ . '/profile.php';
require __DIR__ . '/channels.php';
require __DIR__ . '/b2b.php';
require __DIR__ . '/inf.php';
require __DIR__ . '/calltrack.php';
require __DIR__ . '/khushboo.php';
require __DIR__ . '/adupload.php';
require __DIR__ . '/perf.php';

function cfg(string $k, $d = null)
{
    global $CFG;
    if ($k === 'designations') {
        $list = $CFG['designations'] ?? [];
        foreach (['Inside Sales', 'E-commerce Specialist'] as $extra) {
            if (!in_array($extra, $list, true)) {
                $i = array_search('Other', $list, true);
                array_splice($list, $i === false ? count($list) : $i, 0, [$extra]);
            }
        }
        return $list;
    }
    return $CFG[$k] ?? $d;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- security headers and session ---------- */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store, max-age=0');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
session_name('dd_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $BASE === '' ? '/' : $BASE,
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

/* ---------- database ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $path = cfg('db_path');
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0750, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    try {   // faster writes and reads; WAL lets a sync write while pages are being read
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA cache_size = -20000');
        $pdo->exec('PRAGMA mmap_size = 134217728');
    } catch (Throwable $e) { /* keep the defaults */ }
    // The table checks and one-time seeds run once after each update (VERSION changes), not on every page view.
    $want = max(1, crc32(trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'))) & 0x7fffffff);
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() !== $want) {
        migrate($pdo);
        $pdo->exec('PRAGMA user_version = ' . $want);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('admin','ceo','employee')),
        designation TEXT NOT NULL DEFAULT 'Other',
        active INTEGER NOT NULL DEFAULT 1,
        must_change INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        last_login TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        report_date TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'work' CHECK (type IN ('work','leave')),
        note TEXT NOT NULL DEFAULT '',
        submitted_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE (user_id, report_date)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        category TEXT NOT NULL DEFAULT 'Other',
        hours REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'done' CHECK (status IN ('done','in_progress','blocked')),
        remarks TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
        hdate TEXT PRIMARY KEY,
        label TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ident TEXT NOT NULL,
        at INTEGER NOT NULL
    )");
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('dash_access', $cols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN dash_access INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('ecom_access', $cols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN ecom_access INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('sales_access', $cols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN sales_access INTEGER NOT NULL DEFAULT 0');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_date ON reports(report_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_report ON tasks(report_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts ON login_attempts(ident, at)');
    sales_migrate($pdo);
    ecom_migrate($pdo);
    gsync_migrate($pdo);
    track_migrate($pdo);
    routine_migrate($pdo);
    conn_migrate($pdo);
    profile_migrate($pdo);
    channels_migrate($pdo);
    b2b_migrate($pdo);
    inf_migrate($pdo);
    ct_migrate($pdo);
    kb_migrate($pdo);
    perf_migrate($pdo);
    att_migrate($pdo);
}

/* ---------- routing helpers ---------- */
function url(string $p = '', array $q = []): string
{
    global $BASE;
    $qs = $p !== '' ? ['p' => $p] + $q : $q;
    return $BASE . '/index.php' . ($qs ? '?' . http_build_query($qs) : '');
}

function google_enabled(): bool
{
    return cfg('google_client_id', '') !== '' && cfg('google_client_secret', '') !== '';
}

function google_redirect_uri(): string
{
    global $BASE, $https;
    $o = cfg('google_redirect_uri', '');
    return $o !== '' ? $o : ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $BASE . '/google-callback.php';
}

function asset(string $f): string
{
    global $BASE;
    $v = @filemtime(__DIR__ . '/../assets/' . $f) ?: 1;
    return $BASE . '/assets/' . $f . '?v=' . $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/* ---------- flash messages ---------- */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- csrf ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

/* ---------- auth ---------- */
function user(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return $u = null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $st->execute([(int)$id]);
    $row = $st->fetch();
    if (!$row) {
        unset($_SESSION['uid']);
        return $u = null;
    }
    return $u = $row;
}

function has_users(): bool
{
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function files_reports(?array $u = null): bool
{
    $u = $u ?? user();
    return $u && in_array($u['role'], ['employee', 'admin'], true);
}

function can_view_dashboard(?array $u = null): bool
{
    $u = $u ?? user();
    return $u && (in_array($u['role'], ['ceo', 'admin'], true) || !empty($u['dash_access']));
}

function home_for(array $u): string
{
    return $u['role'] === 'ceo' ? url('ceo') : (!files_reports($u) ? url('dashboard') : url('report'));
}

/* ---------- dates ---------- */
function valid_date(?string $d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function today(): string
{
    return date('Y-m-d');
}

function add_days(string $d, int $n): string
{
    return (new DateTime($d))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}

function fmt_date(string $d, bool $year = false): string
{
    return (new DateTime($d))->format($year ? 'D, j M Y' : 'D, j M');
}

function fmt_short(string $d): string
{
    return (new DateTime($d))->format('j M');
}

function holidays_between(string $from, string $to): array
{
    $st = db()->prepare('SELECT hdate, label FROM holidays WHERE hdate BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['hdate']] = $r['label'];
    }
    return $out;
}

/** Working days between two dates, skipping weekly offs and holidays. */
function working_days(string $from, string $to): array
{
    if ($from > $to) {
        return [];
    }
    $hol = holidays_between($from, $to);
    $off = cfg('weekly_off', [0]);
    $days = [];
    for ($d = $from; $d <= $to; $d = add_days($d, 1)) {
        $dow = (int)(new DateTime($d))->format('w');
        if (in_array($dow, $off, true) || isset($hol[$d])) {
            continue;
        }
        $days[] = $d;
    }
    return $days;
}

function fmt_hours(float $h): string
{
    $s = rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function status_label(string $s): string
{
    return ['done' => 'Done', 'in_progress' => 'In progress', 'blocked' => 'Blocked'][$s] ?? $s;
}

/* ---------- layout ---------- */
function icon(string $n): string
{
    static $p = [
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'grid'      => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'chev-l'    => '<path d="m15 6-6 6 6 6"/>',
        'chev-r'    => '<path d="m9 6 6 6-6 6"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'eye'       => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eyeoff'    => '<path d="M9.9 5.2A9.7 9.7 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.4-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m3 3 18 18"/>',
        'upload'    => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M5 20h14"/>',
        'trend'     => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'target'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'bag'       => '<path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
        'coffee'    => '<path d="M4 8h13v6a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V8Z"/><path d="M17 10h2a2 2 0 0 1 0 4h-2"/><path d="M8 3v2M12 3v2"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'widget'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v7M14 17.5h7"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'timer'     => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/>',
        'check'     => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'alert'     => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    if (count($parts) > 1) {
        $s .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    }
    return $s;
}

function layout_head(string $title, string $bodyClass = ''): void
{
    $u = user();
    $page = $_GET['p'] ?? '';
    $nav = [];
    if ($u) {
        $my = [];
        if (files_reports($u)) {
            $my[] = ['report', 'Daily report', 'edit'];
            $my[] = ['history', 'My history', 'clock'];
        }
        if ($u['role'] === 'employee') {
            $my[] = ['attendance', 'My day', 'coffee'];
        }
        if ($u['role'] === 'employee' && track_has($u)) {
            $my[] = ['track', 'My target', 'target'];
        }
        if (can_enter_channels($u)) {
            $my[] = ['channels', 'Other sales', 'bag'];
        }
        $desks = [];
        if (can_sales_desk($u)) {
            $cb = is_sales($u) || !empty($u['sales_access']) ? count(sales_callbacks((int)$u['id'])) : 0;
            $desks[] = ['salesdesk', 'Sales desk', 'upload', $cb];
        }
        if (can_ecom_desk($u)) {
            $desks[] = ['ecomdesk', 'E-commerce desk', 'bag'];
        }
        if (!can_view_dashboard($u) && conn_can($u, 'ads')) { $desks[] = ['perfdash', 'Performance', 'trend']; $desks[] = ['ads', 'Ads and creatives', 'trend']; }
        if (!can_view_dashboard($u) && conn_can($u, 'social')) { $desks[] = ['social', 'Instagram', 'users']; }
        $boards = [];
        if (can_view_dashboard($u)) {
            $boards[] = ['ceo', 'Sales overview', 'trend'];
            $boards[] = ['dashboard', 'Team dashboard', 'grid'];
            $boards[] = ['salesdash', 'Inside Sales', 'trend'];
            $boards[] = ['ecomdash', 'E-commerce', 'bag'];
            $boards[] = ['offlinedash', 'Offline', 'users'];
            $boards[] = ['calldash', 'Calling tracker', 'users'];
            $boards[] = ['kbdash', 'Order desk', 'bag'];
            $boards[] = ['infdash', 'Influencers', 'users'];
            $boards[] = ['perfdash', 'Performance', 'trend'];
            $boards[] = ['ads', 'Ads and creatives', 'trend'];
            $boards[] = ['social', 'Instagram', 'users'];
            $boards[] = ['attboard', 'Attendance', 'check'];
            $boards[] = ['exports', 'Downloads', 'download'];
        }
        if (in_array($u['role'], ['admin', 'ceo'], true)) {
            $boards[] = ['track', 'Targets', 'target'];
            $boards[] = ['team', 'Team', 'users'];
        }
        if ($my) {
            $nav[] = ['My work', $my];
        }
        if ($desks) {
            $nav[] = ['My desks', $desks];
        }
        if ($boards) {
            $nav[] = [$my || $desks ? 'Dashboards' : 'Workspace', $boards];
        }
        if ($u['role'] === 'admin') {
            $nav[] = ['Admin', [['people', 'People', 'users'], ['allreports', 'All reports', 'list'], ['salestargets', 'Sales targets', 'target'], ['routine', 'Daily routine', 'list'], ['holidays', 'Holidays', 'calendar'], ['gsync', 'Google Sheets', 'upload'], ['conn', 'Connections', 'widget'], ['update', 'Update', 'upload']]];
        }
    }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · <?= h(cfg('app_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Albert+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800;900&display=swap">
<link rel="icon" type="image/png" href="<?= h(asset('logo-mark.png')) ?>">
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<?php if ($u): ?>
<aside class="side" id="side" aria-label="Sidebar">
  <a class="brand" href="<?= h(home_for($u)) ?>"><img class="brand__img" src="<?= h(asset('logo-mark.png')) ?>" alt=""><span class="brand__name"><?= h(cfg('app_name')) ?><small><?= h(cfg('company')) ?></small></span></a>
  <nav class="nav" aria-label="Main">
    <?php foreach ($nav as [$group, $items]): ?>
      <div class="nav__h"><?= h($group) ?></div>
      <?php foreach ($items as $it): [$k, $label, $ic] = $it; $badge = (int)($it[3] ?? 0); ?>
        <a href="<?= h(url($k)) ?>" class="<?= $page === $k ? 'is-on' : '' ?>"><?= icon($ic) ?><span><?= h($label) ?></span><?php if ($badge): ?><b class="nbadge" title="Calls to make today"><?= $badge ?></b><?php endif; ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="side__foot">
    <div class="me">
      <?= avatar_html($u, 38) ?>
      <span class="me__t"><a href="<?= h(url('profile')) ?>" title="My profile"><?= h($u['name']) ?></a><small><?= h($u['role'] === 'ceo' ? 'CEO (view only)' : ucfirst($u['role'])) ?></small></span>
    </div>
    <form class="logout" method="post" action="<?= h(url('logout')) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= icon('logout') ?>Log out</button></form>
  </div>
</aside>
<div class="scrim" data-menu></div>
<div class="shell">
<header class="top">
  <button class="iconbtn" type="button" data-menu aria-label="Open menu"><?= icon('menu') ?></button>
  <span class="top__sp"></span>
  <span class="pill pill--date"><?= icon('calendar') ?><?= h(fmt_date(today(), true)) ?></span>
  <a class="pill" href="<?= h(url('profile')) ?>"><?= avatar_html($u, 24) ?><span class="pill__n"><?= h($u['name']) ?></span></a>
</header>
<?php endif; ?>
<main class="wrap<?= $u ? '' : ' wrap--auth' ?>">
<?php if ($u && $u['role'] === 'employee' && $page !== 'report'): foreach (report_reminders($u) as [$rt, $rl]): ?>
  <div class="flash flash--info remind" role="status"><?= icon('clock') ?><span><?= h($rt) ?></span><a class="btn btn--sm" href="<?= h($rl) ?>">Open daily report</a></div>
<?php endforeach; endif; ?>
<?php if ($u && $u['role'] === 'employee' && in_array($page, ['report', 'attendance', 'salesdesk', 'ecomdesk', 'history', 'channels'], true)):
    $hr = (int)date('G'); $greet = $hr < 12 ? 'Good morning' : ($hr < 17 ? 'Good afternoon' : 'Good evening');
    $first = explode(' ', trim((string)$u['name']))[0]; ?>
  <section class="hello">
    <div class="hello__t"><span class="hello__eye"><?= h(fmt_date(today(), true)) ?></span><h2><?= h($greet) ?>, <?= h($first) ?></h2><p><?= h($u['designation'] ?: 'Team') ?> · Denzour Nutrition</p></div>
    <div class="hello__a"><a class="btn btn--sm" href="<?= h(url('report')) ?>"><?= icon('edit') ?>Daily report</a><a class="btn btn--ghost btn--sm" href="<?= h(url('attendance')) ?>"><?= icon('coffee') ?>My day</a></div>
  </section>
  <?php track_strip($u); ?>
<?php endif; ?>
<?php foreach (take_flash() as [$type, $msg]): ?>
  <div class="flash flash--<?= h($type) ?>" role="status"><?= h($msg) ?></div>
<?php endforeach; ?>
<?php
}

function layout_foot(): void
{
    $in = user() !== null;
    ?>
</main>
<?php if ($in): ?>
<footer class="foot"><?= h(cfg('company')) ?> · <?= h(cfg('app_name')) ?></footer>
</div>
<?php endif; ?>
<script src="<?= h(asset('app.js')) ?>" defer></script>
</body>
</html>
<?php
}
