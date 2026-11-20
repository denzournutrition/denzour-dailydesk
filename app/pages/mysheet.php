<?php
defined('DD') or exit;

$pdo = db();
$me = user();
$back = in_array($_POST['back'] ?? '', ['salesdesk', 'ecomdesk'], true) ? (string)$_POST['back'] : home_for($me);
$kind = ($_POST['kind'] ?? '') === 'ecom' ? 'ecom' : 'sales';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(in_array($back, ['salesdesk', 'ecomdesk'], true) ? url($back) : $back); }
csrf_verify();
$isAdmin = $me['role'] === 'admin';
$uid = $isAdmin ? (int)($_POST['uid'] ?? 0) : (int)$me['id'];
$go = fn() => redirect(in_array($back, ['salesdesk', 'ecomdesk'], true) ? url($back, $isAdmin && $uid ? ['who' => $uid] : []) . '#gsheet' : $back);
$act = (string)($_POST['action'] ?? '');

if ($act === 'add') {
    $url = trim((string)($_POST['url'] ?? ''));
    $sid = gsync_sheet_id($url);
    if ($sid === '' || !$uid) { flash('error', 'That does not look like a Google Sheets link. Copy it from the browser address bar.'); $go(); }
    $every = in_array((int)($_POST['every'] ?? 15), [5, 15, 30, 60], true) ? (int)$_POST['every'] : 15;
    $pdo->prepare('INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80), $kind, $uid, $sid, mb_substr($url, 0, 400), $every, date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    $m = gsync_run($id);
    flash($pdo->query('SELECT last_ok FROM gsync_links WHERE id = ' . $id)->fetchColumn() ? 'success' : 'error', 'Connected. ' . $m);
    $go();
}

// the other actions only work on the person's own links (admins: any)
$id = (int)($_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM gsync_links WHERE id = ?'); $st->execute([$id]);
$l = $st->fetch();
if (!$l || (!$isAdmin && (int)$l['user_id'] !== (int)$me['id'])) { flash('error', 'That link was not found.'); $go(); }
if ($act === 'now') {
    $m = gsync_run($id);
    flash($pdo->query('SELECT last_ok FROM gsync_links WHERE id = ' . $id)->fetchColumn() ? 'success' : 'error', $m);
} elseif ($act === 'toggle') {
    $pdo->prepare('UPDATE gsync_links SET enabled = 1 - enabled WHERE id = ?')->execute([$id]);
} elseif ($act === 'delete') {
    $pdo->prepare('DELETE FROM gsync_links WHERE id = ?')->execute([$id]);
    flash('success', 'Link removed. Data already imported stays on the dashboard.');
}
$go();
