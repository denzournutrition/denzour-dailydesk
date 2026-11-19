<?php
defined('DD') or exit;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $c['path'], $c['domain'] ?? '', (bool)$c['secure'], true);
    }
    session_destroy();
}
redirect(url('login'));
