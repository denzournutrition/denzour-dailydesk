<?php
defined('DD') or exit;

// Step 1: send the person to Google to choose an account.
if (user()) {
    redirect(home_for(user()));
}
if (!google_enabled()) {
    $_SESSION['login_error'] = 'Google sign-in is not set up yet.';
    redirect(url('login'));
}
$_SESSION['g_state'] = bin2hex(random_bytes(16));
$_SESSION['g_nonce'] = bin2hex(random_bytes(16));
redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => cfg('google_client_id'),
    'redirect_uri'  => google_redirect_uri(),
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $_SESSION['g_state'],
    'nonce'         => $_SESSION['g_nonce'],
    'prompt'        => 'select_account',
]));
