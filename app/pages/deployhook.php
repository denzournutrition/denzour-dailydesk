<?php
defined('DD') or exit;
/* GitHub webhook: runs on every push, so the live site updates in a few seconds.
   The webhook address carries a secret token; only its hash is kept here. */
header('Content-Type: text/plain; charset=utf-8');
$t = (string)($_GET['t'] ?? '');
if ($t === '' || !hash_equals('49b3e9eb768601186d8da21c2cbd9a3b088a7f7d5d339fc9060f31158a897cb7', hash('sha256', $t))) { http_response_code(403); echo "no\n"; exit; }
$ev = (string)($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
if ($ev === 'ping') { echo "pong\n"; exit; }
if ($ev === 'push') {
    $b = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($b) && ($b['ref'] ?? '') !== 'refs/heads/main') { echo "ignored\n"; exit; }
}
ignore_user_abort(true); @set_time_limit(120);
echo autodeploy_run(true) . "\n";
