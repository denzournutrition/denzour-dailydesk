<?php
defined('DD') or exit;

// Step 2: Google sends the person back here with a one-time code.
function g_fail(string $msg): never
{
    $_SESSION['login_error'] = $msg;
    redirect(url('login'));
}

$state = (string)($_GET['state'] ?? '');
$want  = (string)($_SESSION['g_state'] ?? '');
$nonce = (string)($_SESSION['g_nonce'] ?? '');
unset($_SESSION['g_state'], $_SESSION['g_nonce']);

if (!google_enabled()) {
    g_fail('Google sign-in is not set up yet.');
}
if (isset($_GET['error'])) {
    g_fail('Google sign-in was cancelled.');
}
if ($want === '' || !hash_equals($want, $state) || empty($_GET['code'])) {
    g_fail('Google sign-in could not be verified. Please try again.');
}

// Exchange the code for the signed-in person's details (server to server, over https).
$body = http_build_query([
    'code'          => (string)$_GET['code'],
    'client_id'     => cfg('google_client_id'),
    'client_secret' => cfg('google_client_secret'),
    'redirect_uri'  => google_redirect_uri(),
    'grant_type'    => 'authorization_code',
]);
$endpoint = cfg('google_token_url', 'https://oauth2.googleapis.com/token');
$resp = null;
if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
} else {
    $resp = @file_get_contents($endpoint, false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
    ]]));
}
$tok = is_string($resp) ? json_decode($resp, true) : null;
if (!is_array($tok) || empty($tok['id_token'])) {
    g_fail('Google did not accept the sign-in. Please try again.');
}

$parts = explode('.', (string)$tok['id_token']);
$claims = count($parts) === 3 ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
if (!is_array($claims)
    || !in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
    || ($claims['aud'] ?? '') !== cfg('google_client_id')
    || (int)($claims['exp'] ?? 0) < time()
    || !hash_equals($nonce, (string)($claims['nonce'] ?? ''))
    || empty($claims['email_verified'])) {
    g_fail('Google sign-in could not be verified. Please try again.');
}

$email = strtolower(trim((string)($claims['email'] ?? '')));
$st = db()->prepare('SELECT * FROM users WHERE lower(email) = ? AND active = 1');
$st->execute([$email]);
$row = $st->fetch();
if (!$row) {
    g_fail($email . ' is not added in DailyDesk. Ask your admin to add this email, then try again.');
}

session_regenerate_id(true);
$_SESSION['uid'] = (int)$row['id'];
unset($_SESSION['csrf']);
db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $row['id']]);
redirect(home_for($row));
