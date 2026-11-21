<?php
defined('DD') or exit;

function routine_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS routine (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mode TEXT NOT NULL DEFAULT 'daily',      -- daily = pre-filled every day, extra = one-click add
        scope TEXT NOT NULL DEFAULT 'all',       -- all | designation | user
        ref TEXT NOT NULL DEFAULT '',
        title TEXT NOT NULL,
        category TEXT NOT NULL DEFAULT 'Execution',
        hours REAL NOT NULL DEFAULT 0,
        sort INTEGER NOT NULL DEFAULT 0
    )");
}

/** Routine rows that apply to one person: [daily => [...], extra => [...]] */
function routine_for(array $u): array
{
    $st = db()->prepare("SELECT * FROM routine WHERE scope = 'all' OR (scope = 'designation' AND ref = ?) OR (scope = 'user' AND ref = ?) ORDER BY sort, id");
    $st->execute([(string)($u['designation'] ?? ''), (string)(int)$u['id']]);
    $out = ['daily' => [], 'extra' => []];
    foreach ($st->fetchAll() as $r) { $out[$r['mode'] === 'extra' ? 'extra' : 'daily'][] = $r; }
    return $out;
}
