<?php
defined('DD') or exit;
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals(gsync_key(), (string)($_GET['key'] ?? ''))) { http_response_code(403); echo "no\n"; exit; }
@touch(dirname(__DIR__, 2) . '/data/gsync.cron');
echo 'ok ' . gsync_run_due() . " link(s) synced\n";
