<?php
defined('DD') or exit;
/* Auto-update: pulls new code from GitHub and copies it into the live site, so nobody opens cPanel.
   Runs from the 5-minute sheet-sync ping (and, at most every 5 minutes, when an admin opens the app).
   It only acts when GitHub (branch main) is ahead of the server's repository. Log: data/autodeploy.log */

function autodeploy_repo(): string
{
    foreach (['/home/denzour1/repositories/dd-web', dirname(__DIR__, 2) . '/../repositories/dd-web'] as $r) {
        if (is_dir($r . '/.git')) { return realpath($r) ?: $r; }
    }
    return '';
}

function autodeploy_log(string $m): void
{
    $f = dirname(__DIR__) . '/data/autodeploy.log';
    $old = is_file($f) ? array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -40) : [];
    $old[] = date('Y-m-d H:i:s') . ' ' . $m;
    @file_put_contents($f, implode("\n", $old) . "\n");
}

function autodeploy_sh(string $cmd): array
{
    $out = []; $rc = 1;
    @exec('HOME=/home/denzour1 GIT_TERMINAL_PROMPT=0 ' . $cmd . ' 2>&1', $out, $rc);
    return [$rc, trim(implode("\n", $out))];
}

/** Returns a short status string. $force skips the 5-minute throttle. */
function autodeploy_run(bool $force = false): string
{
    $stamp = dirname(__DIR__) . '/data/autodeploy.last';
    if (!$force && is_file($stamp) && time() - (int)@filemtime($stamp) < 290) { return 'throttled'; }
    @touch($stamp);
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $dis, true)) { autodeploy_log('exec is disabled on this host; auto-update cannot run'); return 'no exec'; }
    $repo = autodeploy_repo();
    if ($repo === '') { autodeploy_log('server repository not found'); return 'no repo'; }
    $g = 'git -C ' . escapeshellarg($repo);
    [$rc, $o] = autodeploy_sh($g . ' fetch origin main');
    if ($rc !== 0) { autodeploy_log('fetch failed: ' . mb_substr($o, 0, 300)); return 'fetch failed'; }
    [, $local] = autodeploy_sh($g . ' rev-parse HEAD');
    [, $remote] = autodeploy_sh($g . ' rev-parse origin/main');
    if ($remote === '' || $local === $remote) { return 'up to date'; }
    [$rc, $o] = autodeploy_sh($g . ' reset --hard origin/main');
    if ($rc !== 0) { autodeploy_log('reset failed: ' . mb_substr($o, 0, 300)); return 'reset failed'; }
    [$rc, $o] = autodeploy_sh('sh ' . escapeshellarg($repo . '/deploy.sh'));
    autodeploy_log(($rc === 0 ? 'updated to ' : 'copy failed at ') . substr($remote, 0, 7) . ($rc === 0 ? '' : ': ' . mb_substr($o, 0, 300)));
    return $rc === 0 ? 'updated ' . substr($remote, 0, 7) : 'copy failed';
}
