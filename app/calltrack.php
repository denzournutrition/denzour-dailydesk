<?php
defined('DD') or exit;

/* Offline team daily calling trackers (Tanya, Bivakar, Shabik): one Google Sheet each, a date heading per day, then one row per call.
   Columns differ by person and some rows are shifted, so every row is read by content: phone = the number cell, city = a place cell,
   status = the strongest label found, remark = the rest. Later calls to the same number replace earlier ones (that is the live status). */

const CT_SHEETS = [
    ['Calling · Tanya', '1MvFGU0i285f5OwzGThTEjUzamdS6BEEYCOz99I8RLG0'],
    ['Calling · Bivakar', '1uVVu4opLeOXHmWqbZdsTlQEuNDcbxtlCC1QjYNCUlng'],
    ['Calling · Shabik', '1paw9JX2ByTiU3GfYVdtlgPUKNiWiYMIPRcYB8behjnU'],
    ['Calling · Shajadi', '1z9Ok3W-sv4-vhK6AhNl3trezKqWe8N4Yo_MmYFSkSq0'],
    ['Calling · Ankit', '1W0_gP50x1Af5-_2TuR28TVjdvyPHrPl5GaGesJzEHHA'],
];

const CT_CATS = [
    'deal' => 'Order / 100%', 'hot' => 'Interested', 'connected' => 'Details shared', 'callback' => 'Call back',
    'noanswer' => 'No answer', 'off' => 'Switched off', 'notint' => 'Not interested', 'wrong' => 'Wrong number', 'todo' => 'Not called yet',
];

function ct_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_call (
        person TEXT NOT NULL, day TEXT NOT NULL DEFAULT '', rown INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '', cat TEXT NOT NULL DEFAULT 'todo', disp TEXT NOT NULL DEFAULT '', remark TEXT NOT NULL DEFAULT '', due TEXT NOT NULL DEFAULT '', hint TEXT NOT NULL DEFAULT '')");
    $pdo->exec('CREATE INDEX IF NOT EXISTS ct_call_p ON ct_call (person, phone, cat, day, rown)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ct_call_d ON ct_call (person, day)');
    try {
        if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'seed_call_sheets_v2'")->fetchColumn()) {
            foreach (CT_SHEETS as [$label, $id]) {
                $has = $pdo->prepare('SELECT 1 FROM gsync_links WHERE sheet_id = ?'); $has->execute([$id]);
                if (!$has->fetchColumn()) {
                    $pdo->prepare('INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at, month) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$label, 'calltrack', 0, $id, 'https://docs.google.com/spreadsheets/d/' . $id . '/edit', 5, date('Y-m-d H:i:s'), '']);
                }
            }
            $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('seed_call_sheets_v2', '1')");
        }
    } catch (Throwable $e) { /* never block start-up */ }
}

function ct_month(string $s): int
{
    static $m = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    return $m[strtolower(substr($s, 0, 3))] ?? 0;
}

/** "26th Novemeber 2026" (typos allowed), "26/11/2026" or a date serial in the first cell. Returns Y-m-d or ''. */
function ct_day_head($v): string
{
    if (is_float($v) || is_int($v)) {
        $ts = ((int)floor((float)$v) - 25569) * 86400;
        return ($v > 40000 && $v < 60000 && $ts > 0) ? gmdate('Y-m-d', $ts) : '';
    }
    $t = trim((string)$v);
    if (preg_match('/^(\d{1,2})\s*(?:st|nd|rd|th)?\s+([A-Za-z]{3,})\.?\s*,?\s*(\d{4})$/', $t, $m) && ct_month($m[2]) && checkdate(ct_month($m[2]), (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], ct_month($m[2]), $m[1]);
    }
    if (preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$~', $t, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return '';
}

function ct_phone($v): string
{
    if (is_float($v) || is_int($v)) { $s = sprintf('%.0f', $v); }
    else { $s = trim((string)$v); if ($s === '' || !preg_match('/^[\d\s+\-()]+$/', $s)) { return ''; } }
    $d = preg_replace('/\D/', '', $s);
    if (strlen($d) < 10 || strlen($d) > 13) { return ''; }
    return substr($d, -10);
}

/** Strongest status label in one cell, '' when the cell is not a status. */
function ct_cat(string $t): string
{
    $t = strtolower(trim($t));
    if ($t === '' || strlen($t) > 48) { return ''; }
    if (preg_match('/not\s+int|deal with other|other brand/', $t)) { return 'notint'; }
    if (str_contains($t, '100%')) { return 'deal'; }
    if (preg_match('/50%|interest|interstead|follow-?\s?up required/', $t)) { return 'hot'; }
    if (str_contains($t, 'connected') || preg_match('/^share details/', $t)) { return 'connected'; }
    if (preg_match('/call\s*back|callback/', $t)) { return 'callback'; }
    if (str_contains($t, 'wrong number')) { return 'wrong'; }
    if (preg_match('/switched off|not reachable|unreachable/', $t)) { return 'off'; }
    if (preg_match('/did not answer|not answering|no answer|ringing|rnr|busy|not picking|ivr/', $t)) { return 'noanswer'; }
    return '';
}

/** When the next call is due. Reads "tomorrow", a weekday, "30th November", "next month", "after 5pm"; otherwise a sensible default. Returns [date, hint]. */
function ct_due(string $cat, string $text, string $day): array
{
    if ($day === '' || in_array($cat, ['notint', 'wrong', 'todo', 'deal'], true)) { return ['', '']; }
    $t = strtolower($text); $base = strtotime($day);
    if (preg_match('/(\d{1,2})\s*(?:st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*/', $t, $m)) {
        $mo = ct_month($m[2]); $y = (int)date('Y', $base);
        if (checkdate($mo, (int)$m[1], $y)) {
            $d = sprintf('%04d-%02d-%02d', $y, $mo, $m[1]);
            if ($d < $day) { $d = sprintf('%04d-%02d-%02d', $y + 1, $mo, $m[1]); }
            return [$d, 'date in remark'];
        }
    }
    if (preg_match('/tomorrow|tmrw|tommorow|tommoroow/', $t)) { return [date('Y-m-d', $base + 86400), 'tomorrow']; }
    if (preg_match('/\b(mon|tues?|wed(?:nes)?|thu(?:rs)?|fri|sat(?:ur)?|sun)(?:day)?\b/', $t, $m)) {
        $want = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0][substr($m[1], 0, 3)];
        for ($i = 1; $i <= 7; $i++) { if ((int)date('w', $base + $i * 86400) === $want) { return [date('Y-m-d', $base + $i * 86400), ucfirst($m[0])]; } }
    }
    if (preg_match('/next month/', $t)) { return [date('Y-m-01', strtotime('first day of next month', $base)), 'next month']; }
    if (preg_match('/today|evening|\bafter\s*\d|\d\s*(?:am|pm)\b|free after/', $t)) { return [$day, 'same day']; }
    if (preg_match('/monday|next week/', $t)) { return [date('Y-m-d', strtotime('next monday', $base)), 'next week']; }
    if (in_array($cat, ['hot', 'connected'], true)) { return [date('Y-m-d', $base + 2 * 86400), 'follow up']; }
    return [date('Y-m-d', $base + 86400), $cat === 'callback' ? 'call back' : 'try again'];
}

/** Reads one person's sheet and replaces their saved calls. Returns a status line. */
function ct_import(string $path, string $person): string
{
    $tabs = xlsx_read($path);
    $rows = $tabs[0]['rows'] ?? [];
    foreach ($tabs as $t) { if (count($t['rows']) > count($rows)) { $rows = $t['rows']; } }
    $day = ''; $out = []; $n = 0; $skipped = 0;
    foreach ($rows as $ri => $r) {
        $cells = array_values($r);
        $first = $cells[0] ?? '';
        $rest = array_filter(array_slice($cells, 1, 4), fn($c) => trim((string)$c) !== '');
        $dh = ct_day_head($first);
        if ($dh !== '' && !$rest) { $day = $dh; continue; }
        $name = trim((string)$first);
        $phone = ''; $pi = -1;
        for ($i = 1; $i <= 3; $i++) { $p = ct_phone($cells[$i] ?? ''); if ($p !== '') { $phone = $p; $pi = $i; break; } }
        if ($phone === '') { if (preg_match('/\\w/', $name) && $dh === '' && strtolower($name) !== 'name') { $skipped++; } continue; }
        if ($day === '') { continue; }
        $cat = ''; $dispCell = -1; $city = ''; $texts = [];
        $rank = array_flip(['deal', 'hot', 'connected', 'callback', 'notint', 'wrong', 'off', 'noanswer']);
        for ($i = 1; $i <= 4; $i++) {
            if ($i === $pi) { continue; }
            $c = $cells[$i] ?? ''; if (!is_string($c)) { continue; } $c = trim($c); if ($c === '') { continue; }
            $k = ct_cat($c);
            if ($k !== '') { if ($cat === '' || $rank[$k] < $rank[$cat]) { $cat = $k; $dispCell = $i; } continue; }
            if ($i <= 2 && $city === '' && strlen($c) <= 60 && !preg_match('/call|tomorrow|rnr|whatsapp|will |order/i', $c)) { $city = $c; continue; }
            $texts[] = $c;
        }
        $disp = $dispCell >= 0 ? trim((string)$cells[$dispCell]) : '';
        // other status cells (for example "Ringing" next to a note) are part of the note
        for ($i = 1; $i <= 4; $i++) {
            if ($i === $pi || $i === $dispCell) { continue; }
            $c = $cells[$i] ?? ''; if (is_string($c) && trim($c) !== '' && ct_cat($c) !== '') { array_unshift($texts, trim($c)); }
        }
        $remark = implode(' · ', array_values(array_unique($texts)));
        if ($cat === '') { $cat = $remark !== '' ? 'callback' : 'todo'; if ($remark !== '') { $disp = 'Note only'; } }
        [$due, $hint] = ct_due($cat, $remark . ' ' . $disp, $day);
        $out[] = [$person, $day, $ri, mb_substr($name, 0, 120), $phone, mb_substr($city, 0, 80), $cat, mb_substr($disp, 0, 80), mb_substr($remark, 0, 400), $due, $hint];
        $n++;
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM ct_call WHERE person = ?')->execute([$person]);
    $ins = $pdo->prepare('INSERT INTO ct_call (person, day, rown, name, phone, city, cat, disp, remark, due, hint) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($out as $o) { $ins->execute($o); }
    $pdo->commit();
    $todo = count(array_filter($out, fn($o) => $o[6] === 'todo'));
    $dials = $n - $todo;
    return 'Read ' . $dials . ' calls' . ($todo ? ' and ' . $todo . ' numbers still to call' : '') . ' for ' . $person . '.' . ($skipped ? ' Skipped ' . $skipped . ' rows with no phone number.' : '');
}

/** SQL for the live record of every number: its latest real call (or the uncalled row when it has never been dialled). */
function ct_cur_sql(): string
{
    return "SELECT c.*, (SELECT COUNT(*) FROM ct_call x WHERE x.person = c.person AND x.phone = c.phone AND x.cat != 'todo') AS tries FROM ct_call c
        WHERE (c.cat != 'todo' AND NOT EXISTS (SELECT 1 FROM ct_call n WHERE n.person = c.person AND n.phone = c.phone AND n.cat != 'todo' AND (n.day > c.day OR (n.day = c.day AND n.rown > c.rown))))
           OR (c.cat = 'todo' AND NOT EXISTS (SELECT 1 FROM ct_call n WHERE n.person = c.person AND n.phone = c.phone AND n.cat != 'todo')
               AND NOT EXISTS (SELECT 1 FROM ct_call n WHERE n.person = c.person AND n.phone = c.phone AND n.cat = 'todo' AND (n.day > c.day OR (n.day = c.day AND n.rown > c.rown))))";
}

/** Which calling-tracker person (if any) is this login? Matches on the first word of the name, either direction. */
function ct_person_for(array $u): string
{
    $w = mb_strtolower(trim(explode(' ', trim((string)($u['name'] ?? '')))[0] ?? ''));
    if (mb_strlen($w) < 3) { return ''; }
    foreach (CT_SHEETS as [$label]) {
        $p = trim(preg_replace('/^Calling\s*[·\-:]\s*/u', '', $label));
        $pl = mb_strtolower($p);
        if ($pl === $w || strpos($pl, $w) === 0 || strpos($w, $pl) === 0) { return $p; }
    }
    return '';
}

/** What this caller actually did on a day, read from their sheet. */
function ct_day_stats(string $person, string $day): array
{
    $st = db()->prepare("SELECT cat, COUNT(*) n FROM ct_call WHERE person = ? AND day = ? AND cat != 'todo' GROUP BY cat");
    $st->execute([$person, $day]);
    $c = array_fill_keys(array_keys(CT_CATS), 0);
    foreach ($st->fetchAll() as $r) { $c[$r['cat']] = (int)$r['n']; }
    $calls = array_sum($c);
    return [
        'calls' => $calls,
        'picked' => $c['deal'] + $c['hot'] + $c['connected'] + $c['callback'] + $c['notint'],
        'interested' => $c['deal'] + $c['hot'] + $c['connected'],
        'orders' => $c['deal'],
        'noans' => $c['noanswer'] + $c['off'] + $c['wrong'],
    ];
}
