<?php
declare(strict_types=1);
define("DD", true);
require __DIR__ . '/app/bootstrap.php';

$routes = [
    'install'   => ['file' => 'install',   'roles' => null,                       'auth' => false],
    'login'     => ['file' => 'login',     'roles' => null,                       'auth' => false],
    'google'    => ['file' => 'google',    'roles' => null,                       'auth' => false],
    'logout'    => ['file' => 'logout',    'roles' => null,                       'auth' => false],
    'report'    => ['file' => 'report',    'roles' => ['employee', 'admin'],      'auth' => true],
    'history'   => ['file' => 'history',   'roles' => ['employee', 'admin'],      'auth' => true],
    'dashboard' => ['file' => 'dashboard', 'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'export'    => ['file' => 'export',    'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'allreports'=> ['file' => 'allreports','roles' => ['admin'],                  'auth' => true],
    'people'    => ['file' => 'people',    'roles' => ['admin'],                  'auth' => true],
    'conn'      => ['file' => 'conn',      'roles' => ['admin'],                  'auth' => true],
    'ads'       => ['file' => 'ads',       'roles' => null,                       'auth' => true],
    'social'    => ['file' => 'social',    'roles' => null,                       'auth' => true],
    'routine'   => ['file' => 'routine',   'roles' => ['admin'],                  'auth' => true],
    'holidays'  => ['file' => 'holidays',  'roles' => ['admin'],                  'auth' => true],
    'gsync'     => ['file' => 'gsync',     'roles' => ['admin'],                  'auth' => true],
    'mysheet'   => ['file' => 'mysheet',   'roles' => ['employee', 'admin'],      'auth' => true],
    'gsynccron' => ['file' => 'gsynccron', 'roles' => null,                       'auth' => false],
    'update'    => ['file' => 'update',    'roles' => ['admin'],                  'auth' => true],
    'ecomdesk'  => ['file' => 'ecomdesk',  'roles' => ['employee', 'admin'],      'auth' => true],
    'ecomdash'  => ['file' => 'ecomdash',  'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'ecomlogo'  => ['file' => 'ecomlogo',  'roles' => null,                       'auth' => true],
    'salesdesk' => ['file' => 'salesdesk', 'roles' => ['employee', 'admin'],      'auth' => true],
    'salesdash' => ['file' => 'salesdash', 'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'salestargets' => ['file' => 'salestargets', 'roles' => ['admin'],            'auth' => true],
    'attendance'=> ['file' => 'attendance','roles' => ['employee'],                   'auth' => true],
    'attboard'  => ['file' => 'attboard',  'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'exports'   => ['file' => 'exports',   'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'track'     => ['file' => 'track',     'roles' => null,                       'auth' => true],
    'ceo'       => ['file' => 'ceo',       'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'kbdash'   => ['file' => 'kbdash',   'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'calldash' => ['file' => 'calldash', 'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'infdash' => ['file' => 'infdash', 'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'offlinedash' => ['file' => 'offlinedash', 'roles' => null, 'cap' => 'dashboard', 'auth' => true],
    'channels'  => ['file' => 'channels',  'roles' => ['employee', 'admin'],      'auth' => true],
    'profile'   => ['file' => 'profile',   'roles' => null,                       'auth' => true],
    'team'      => ['file' => 'team',      'roles' => ['admin', 'ceo'],           'auth' => true],
    'avatar'    => ['file' => 'avatar',    'roles' => null,                       'auth' => true],
    'account'   => ['file' => 'account',   'roles' => null,                       'auth' => true],
];

$p = $_GET['p'] ?? '';
if (!is_string($p)) {
    $p = '';
}

if (!has_users()) {
    $p = 'install';
}

if (!in_array($p, ['avatar', 'ecomlogo', 'export', 'exports', 'gsynccron'], true) && extension_loaded('zlib') && !headers_sent()) {
    @ini_set('zlib.output_compression', '1');   // pages are mostly tables: roughly 6x smaller over the wire
}

$u = user();
if ($p === '' || !isset($routes[$p])) {
    redirect($u ? home_for($u) : url('login'));
}

$route = $routes[$p];
if ($route['auth']) {
    if (!$u) {
        redirect(url('login'));
    }
    // A user who must change a temporary password can only open the account page.
    if (!empty($u['must_change']) && $p !== 'account') {
        flash('info', 'Please set a new password to continue.');
        redirect(url('account'));
    }
    $denied = ($route['roles'] !== null && !in_array($u['role'], $route['roles'], true))
        || (($route['cap'] ?? '') === 'dashboard' && !can_view_dashboard($u));
    if ($denied) {
        http_response_code(403);
        layout_head('Not allowed');
        echo '<div class="card"><h1>Not allowed</h1><p class="muted">Your role does not have access to this page.</p><p><a class="btn" href="' . h(home_for($u)) . '">Go back</a></p></div>';
        layout_foot();
        exit;
    }
}

if ($u && $u['role'] === 'employee' && $route['auth']) {
    att_touch($u);
    // A late start or a missed day must be explained before carrying on.
    if (!in_array($p, ['attendance', 'account', 'ecomlogo', 'avatar', 'profile'], true) && att_gate_open($u)) {
        redirect(url('attendance'));
    }
}

if ($u && $route['auth'] && $_SERVER['REQUEST_METHOD'] === 'GET' && $p !== 'gsync') { gsync_auto(); }
require __DIR__ . '/app/pages/' . $route['file'] . '.php';
