<?php
defined('DD') or exit;

$root = dirname(__DIR__, 2);
$bdir = $root . '/data/backups';
const UPD_MAX = 30 * 1024 * 1024;

/** Files the updater may write. Never config.php, data/ or any .htaccess. */
function upd_allowed(string $p): bool
{
    if ($p === '' || $p[0] === '/' || strpos($p, '..') !== false || strpos($p, "\\") !== false || strpos($p, "\0") !== false) { return false; }
    if ($p === 'config.php' || strpos($p, 'data/') === 0 || basename($p) === '.htaccess' || strpos($p, '.git') === 0 || basename($p) === '.cpanel.yml') { return false; }
    if (substr($p, -1) === '/') { return false; }
    return (bool)preg_match('/\.(php|css|js|png|jpg|jpeg|webp|svg|txt|md)$/i', $p) || $p === 'VERSION';
}

function upd_files(ZipArchive $z): array
{
    $files = []; $prefix = '';
    // Zips made from a folder have one top-level folder: strip it.
    if ($z->locateName('index.php') === false) {
        for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('#^([^/]+)/index\.php$#', (string)$n, $m)) { $prefix = $m[1] . '/'; break; } }
    }
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = (string)$z->getNameIndex($i);
        if ($prefix !== '') { if (strpos($n, $prefix) !== 0) { continue; } $n = substr($n, strlen($prefix)); }
        if (upd_allowed($n)) { $files[$n] = $i; }
    }
    return $files;
}

function upd_backup(string $root, string $bdir): ?string
{
    if (!is_dir($bdir) && !@mkdir($bdir, 0750, true)) { return null; }
    $ts = time();
    while (is_file($bdir . '/code_' . date('Ymd_His', $ts) . '.zip')) { $ts++; }
    $name = $bdir . '/code_' . date('Ymd_His', $ts) . '.zip';
    $z = new ZipArchive();
    if ($z->open($name, ZipArchive::CREATE) !== true) { return null; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = ltrim(str_replace('\\', '/', substr((string)$f, strlen($root))), '/');
        if ($f->isFile() && upd_allowed($rel)) { $z->addFile((string)$f, $rel); }
    }
    $z->close();
    $all = glob($bdir . '/code_*.zip') ?: [];
    rsort($all);
    foreach (array_slice($all, 8) as $old) { @unlink($old); }
    return $name;
}

function upd_apply(string $zipPath, string $root): array
{
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) { throw new RuntimeException('This is not a valid zip file.'); }
    $files = upd_files($z);
    if (!isset($files['index.php']) || !isset($files['app/bootstrap.php'])) {
        throw new RuntimeException('This zip does not look like a DailyDesk update (index.php or app/bootstrap.php is missing).');
    }
    $n = 0;
    foreach ($files as $path => $i) {
        $data = $z->getFromIndex($i);
        if ($data === false) { continue; }
        if (preg_match('/\.php$/', $path) && strpos($data, '<?php') === false) { throw new RuntimeException('A PHP file in the zip looks damaged: ' . $path); }
        $dest = $root . '/' . $path;
        if (!is_dir(dirname($dest))) { @mkdir(dirname($dest), 0755, true); }
        if (@file_put_contents($dest, $data) === false) { throw new RuntimeException('Could not write ' . $path . '. Check the folder permissions in cPanel.'); }
        $n++;
    }
    $z->close();
    if (function_exists('opcache_reset')) { @opcache_reset(); }
    return [$n];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'update') {
            $f = $_FILES['pkg'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) { throw new RuntimeException($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? 'The file is bigger than this server allows.' : 'Please choose the zip file first.'); }
            if ($f['size'] > UPD_MAX) { throw new RuntimeException('The file is too big for an update zip.'); }
            $bk = upd_backup($root, $bdir);
            if (!$bk) { throw new RuntimeException('Could not make a safety backup, so nothing was changed. Check that the data folder is writable.'); }
            [$n] = upd_apply($f['tmp_name'], $root);
            flash('success', 'Updated ' . $n . ' files. A safety copy of the old version was saved, so you can go back below.');
        } elseif ($act === 'rollback') {
            $name = basename((string)($_POST['backup'] ?? ''));
            $path = $bdir . '/' . $name;
            if (!preg_match('/^code_\d{8}_\d{6}\.zip$/', $name) || !is_file($path)) { throw new RuntimeException('That backup was not found.'); }
            upd_backup($root, $bdir);
            [$n] = upd_apply($path, $root);
            flash('success', 'Went back to the older version (' . $n . ' files).');
        }
    } catch (Throwable $e) {
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The update failed. Nothing more was changed.');
    }
    redirect(url('update'));
}

$ver = is_file($root . '/VERSION') ? trim((string)file_get_contents($root . '/VERSION')) : 'older than v10';
$backups = glob($bdir . '/code_*.zip') ?: [];
rsort($backups);
$canZip = class_exists('ZipArchive');
$writable = is_writable($root) && is_writable($root . '/app');

layout_head('Update');
?>
<div class="pagehead"><div><h1>Update DailyDesk</h1><p class="muted">Current version: <strong><?= h($ver) ?></strong></p></div></div>

<section class="card card--wide">
  <div class="card__h"><h2>Install a new version</h2></div>
  <?php if (!$canZip): ?><p class="muted">This server cannot open zip files. Ask MumuHost to enable the PHP "zip" extension.</p>
  <?php else: ?>
  <ol class="steps">
    <li>Download the <strong>UPDATE zip</strong> I send you. Do not unzip it.</li>
    <li>Choose it below and press <strong>Update now</strong>.</li>
    <li>Open the pages that changed and check. If anything looks wrong, press <strong>Go back</strong> below.</li>
  </ol>
  <form method="post" enctype="multipart/form-data" class="upform" action="<?= h(url('update')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <label class="field grow"><span>Update zip</span><input type="file" name="pkg" accept=".zip" required></label>
    <button class="btn" type="submit" data-confirm="Install this update now? A safety copy of the current version is saved first.">Update now</button>
  </form>
  <p class="muted tiny">Your settings (config.php), all data, people, reports and logos are never touched. The database fields needed by a new version are added automatically.</p>
  <?php if (!$writable): ?><p class="muted tiny bad">The site folder is not writable by the app, so updates may fail. If it does, use the File Manager once and tell me.</p><?php endif; ?>
  <?php endif; ?>
</section>

<section class="card card--wide">
  <div class="card__h"><h2>Go back to an older version</h2></div>
  <?php if (!$backups): ?><p class="muted">No safety copies yet. One is saved automatically before each update.</p>
  <?php else: ?>
  <ul class="plain">
  <?php foreach (array_slice($backups, 0, 5) as $b): $nm = basename($b); preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $nm, $m); ?>
    <li><form method="post" action="<?= h(url('update')) ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="action" value="rollback"><input type="hidden" name="backup" value="<?= h($nm) ?>">
      <span>Version saved <?= h(date('j M Y, g:i a', strtotime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]"))) ?></span>
      <button class="btn btn--ghost btn--sm" type="submit" data-confirm="Go back to this older version?">Go back to this</button></form></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php layout_foot();
