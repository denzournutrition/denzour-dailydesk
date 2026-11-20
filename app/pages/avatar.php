<?php
defined('DD') or exit;
$id = (int)($_GET['id'] ?? 0);
$me = user();
$pr = $id ? profile_get($id) : null;
$file = $pr && $pr['photo'] !== '' ? avatar_dir() . '/' . basename($pr['photo']) : '';
if (!$file || !is_file($file)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
