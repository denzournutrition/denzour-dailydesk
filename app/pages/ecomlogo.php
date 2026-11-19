<?php
defined('DD') or exit;
$k = (string)($_GET['k'] ?? '');
$f = isset(ECOM_PLATFORMS[$k]) ? ecom_logo_path($k) : null;
if (!$f) { http_response_code(404); exit; }
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($f, PATHINFO_EXTENSION)]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($f));
readfile($f);
exit;
