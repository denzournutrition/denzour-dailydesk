<?php
defined('DD') or exit;

/* Targets and daily tracker: read from a connected Google Sheet.
   Expected sheet: a "Daily Tracker" tab (rows "Day 1".."Day 31", columns "<Name> Achieved" and "<Name> Daily Target")
   and an optional "Summary" tab (Name, Monthly Target, ...). People and platforms are both supported. */

const TRACK_PLATFORMS = ['amazon', 'flipkart', 'shopify', 'myntra', 'blinkit', 'hyugalife', 'hyuga', 'hugu life', 'hugu', 'tata 1mg', '1mg', 'website', 'offline', 'meesho', 'nykaa'];

function track_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_targets (
        month TEXT NOT NULL, name TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'person',
        monthly REAL NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (month, name))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_daily (
        month TEXT NOT NULL, name TEXT NOT NULL, day INTEGER NOT NULL,
        achieved REAL, target REAL NOT NULL DEFAULT 0,
        PRIMARY KEY (month, name, day))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_map (name_key TEXT PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_cls (name_key TEXT PRIMARY KEY, channel TEXT NOT NULL DEFAULT 'none')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_part (name_key TEXT PRIMARY KEY, parent_key TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_src (name_key TEXT PRIMARY KEY, src TEXT NOT NULL DEFAULT 'sheet')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_manual (
        month TEXT NOT NULL, name TEXT NOT NULL, day INTEGER NOT NULL, achieved REAL NOT NULL DEFAULT 0, user_id INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (month, name, day))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_meta (month TEXT PRIMARY KEY, synced_at TEXT NOT NULL DEFAULT '')");
    $tc = array_column($pdo->query('PRAGMA table_info(track_targets)')->fetchAll(), 'name');
    if ($tc && !in_array('spend', $tc, true)) { $pdo->exec('ALTER TABLE track_targets ADD COLUMN spend REAL NOT NULL DEFAULT 0'); }
    $cols = array_column($pdo->query('PRAGMA table_info(gsync_links)')->fetchAll(), 'name');
    if ($cols && !in_array('month', $cols, true)) {
        $pdo->exec("ALTER TABLE gsync_links ADD COLUMN month TEXT NOT NULL DEFAULT ''");
    }
    // One-time: connect the Nov 2026 main sale sheet (month left empty = follows the current month, so Dec/Jan just work)
    try {
        $sid = '1SbtZgufnNUcODi_aC9nzzbDyVDsamU9YYXU4wfokyGc';
        $seeded = $pdo->query("SELECT 1 FROM settings WHERE k = 'seed_main_sheet'")->fetchColumn();
        if (!$seeded) {
            $has = $pdo->prepare('SELECT 1 FROM gsync_links WHERE sheet_id = ?'); $has->execute([$sid]);
            if (!$has->fetchColumn()) {
                $pdo->prepare("INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at, month) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(['Main sale sheet', 'targets', 0, $sid, 'https://docs.google.com/spreadsheets/d/' . $sid . '/edit', 5, date('Y-m-d H:i:s'), '']);
            }
            $pdo->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES ('seed_main_sheet', '1')")->execute();
        }
    } catch (Throwable $e) { /* never block start-up */ }
}

/** Display name for a sheet name (set once for Bhanu: "Bhanu credit offline"); the sheet itself is not changed. */
function track_alias(string $name): string
{
    return track_alias_map()[track_key($name)] ?? $name;
}

/** name_key => the line name it is shown as (the designation). Several sheet names, over the months, can share one line name. */
function track_alias_map(): array
{
    static $a = null;
    if ($a === null) { $a = []; foreach (db()->query("SELECT k, v FROM settings WHERE k LIKE 'track_alias:%'") as $r) { $a[substr($r['k'], 12)] = $r['v']; } }
    return $a;
}

function track_key(string $n): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $n));
}

function track_clean(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', str_replace(["\xC2\xA0", "\n", "\r"], ' ', $s)));
}

/** Parses the workbook. Returns ['ents' => [name => ['kind','monthly','days'=>[d=>['a'=>?float,'t'=>float]]]]]. */
function track_parse(string $path): array
{
    $sheets = xlsx_read($path);
    $tracker = null; $summary = null;
    foreach ($sheets as $sh) {
        if ($summary === null && preg_match('/summary/i', $sh['name'])) { $summary = $sh; }
    }
    foreach ($sheets as $sh) {
        foreach (array_slice($sh['rows'], 0, 6) as $r) {
            $n = 0;
            foreach ($r as $c) { if (preg_match('/^[^%]*\S\s*achieved/i', trim((string)$c))) { $n++; } }
            if ($n >= 2) { $tracker = $sh; break 2; }
        }
    }
    if (!$tracker) { throw new RuntimeException('No daily tracker found. The sheet needs a tab with columns like "Name Achieved" and "Name Daily Target", and rows "Day 1", "Day 2"…'); }

    $hdrRow = null;
    foreach ($tracker['rows'] as $ri => $r) {
        $n = 0;
        foreach ($r as $c) { if (preg_match('/^[^%]*\S\s*achieved/i', trim((string)$c))) { $n++; } }
        if ($n >= 2) { $hdrRow = $ri; break; }
    }
    $hdr = $tracker['rows'][$hdrRow];
    $ach = []; $tgt = [];
    foreach ($hdr as $ci => $c) {
        $c = track_clean((string)$c);
        if ($c === '') { continue; }
        if (preg_match('/^(.*?)\s*daily\s*target$/i', $c, $m) && !preg_match('/^total$/i', trim($m[1]))) { $tgt[] = [$ci, trim($m[1])]; }
        elseif (preg_match('/^(.*?)\s*achieved$/i', $c, $m) && !preg_match('/^total$/i', trim($m[1]))) { $ach[] = [$ci, trim($m[1])]; }
    }
    if (!$ach) { throw new RuntimeException('Could not find the "Achieved" columns in the Daily Tracker tab.'); }

    // names: prefer the Summary tab's names (same order) when the counts match
    $names = array_map(fn($a) => $a[1], $ach);
    $monthly = []; $spend = [];
    if ($summary) {
        $sh = null; $sumNames = [];
        foreach ($summary['rows'] as $ri => $r) {
            foreach ($r as $ci => $c) { if (preg_match('/monthly\s*target/i', (string)$c)) { $sh = [$ri, $ci]; break 2; } }
        }
        $spCol = null;
        if ($sh) { foreach ($summary['rows'][$sh[0]] as $ci => $c) { if (preg_match('/spend|spending/i', (string)$c)) { $spCol = $ci; break; } } }
        if ($sh) {
            for ($ri = $sh[0] + 1; $ri < count($summary['rows']); $ri++) {
                $r = $summary['rows'][$ri];
                $nm = track_clean((string)($r[0] ?? ''));
                if ($nm === '' || preg_match('/^total/i', $nm)) { if (preg_match('/^total/i', $nm)) { break; } continue; }
                $sumNames[] = $nm;
                $monthly[track_key($nm)] = s_amount($r[$sh[1]] ?? 0);
                if ($spCol !== null) { $spend[track_key($nm)] = s_amount($r[$spCol] ?? 0); }
            }
            if (count($sumNames) === count($ach)) { $names = $sumNames; }
        }
    }

    $ents = [];
    foreach ($ach as $i => [$ci, $_]) {
        $nm = $names[$i];
        $ti = $tgt[$i][0] ?? null;
        $ents[$nm] = ['kind' => in_array(strtolower($nm), TRACK_PLATFORMS, true) ? 'platform' : 'person', 'monthly' => 0.0, 'days' => [], 'ac' => $ci, 'tc' => $ti, 'sort' => $i];
    }
    foreach (array_slice($tracker['rows'], $hdrRow + 1) as $r) {
        if (!preg_match('/^day\s*(\d{1,2})\b/i', track_clean((string)($r[0] ?? '')), $m)) { continue; }
        $d = (int)$m[1];
        if ($d < 1 || $d > 31) { continue; }
        foreach ($ents as $nm => &$e) {
            $raw = $r[$e['ac']] ?? '';
            $a = (is_string($raw) && trim($raw) === '') || $raw === null ? null : s_amount($raw);
            $t = $e['tc'] !== null ? s_amount($r[$e['tc']] ?? 0) : 0.0;
            $e['days'][$d] = ['a' => $a, 't' => $t];
        }
        unset($e);
    }
    foreach ($ents as $nm => &$e) {
        $sum = 0.0; foreach ($e['days'] as $x) { $sum += $x['t']; }
        $e['spend'] = $spend[track_key($nm)] ?? 0.0;
        $e['monthly'] = $monthly[track_key($nm)] ?? $sum;
        if ($e['monthly'] <= 0) { $e['monthly'] = $sum; }
    }
    unset($e);
    if (!$ents) { throw new RuntimeException('The Daily Tracker tab has no "Day 1…" rows.'); }
    return ['ents' => $ents];
}

/** Replaces one month's targets and daily figures with what is in the sheet. */
function track_import(string $path, string $month): string
{
    $p = track_parse($path);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM track_targets WHERE month = ?')->execute([$month]);
        $pdo->prepare('DELETE FROM track_daily WHERE month = ?')->execute([$month]);
        $it = $pdo->prepare('INSERT INTO track_targets (month, name, kind, monthly, sort, spend) VALUES (?,?,?,?,?,?)');
        $id = $pdo->prepare('INSERT INTO track_daily (month, name, day, achieved, target) VALUES (?,?,?,?,?)');
        foreach ($p['ents'] as $nm => $e) {
            $it->execute([$month, $nm, $e['kind'], $e['monthly'], $e['sort'], (float)($e['spend'] ?? 0)]);
            foreach ($e['days'] as $d => $x) { $id->execute([$month, $nm, $d, $x['a'], $x['t']]); }
        }
        $pdo->prepare('INSERT INTO track_meta (month, synced_at) VALUES (?,?) ON CONFLICT(month) DO UPDATE SET synced_at = excluded.synced_at')->execute([$month, date('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    try { ecom_blinkit_derive($month); } catch (Throwable $e) { /* never block the sync */ }
    $people = 0; $plat = 0;
    foreach ($p['ents'] as $e) { $e['kind'] === 'person' ? $people++ : $plat++; }
    return 'Read targets for ' . $people . ' people and ' . $plat . ' platforms (' . date('F Y', strtotime($month . '-01')) . ').';
}

/** The user an entity name belongs to: saved link first, else a close first-name match. */
function track_user_for(string $name, ?array $users = null): int
{
    $k = track_key($name);
    $st = db()->prepare('SELECT user_id FROM track_map WHERE name_key = ?'); $st->execute([$k]);
    $v = $st->fetchColumn();
    if ($v !== false) { return (int)$v; }
    $users ??= db()->query("SELECT id, name FROM users WHERE active = 1 AND role IN ('employee','admin')")->fetchAll();
    $first = strtolower(explode(' ', trim($name))[0] ?? '');
    $best = 0; $bestD = 99; $tie = false;
    foreach ($users as $u) {
        $uf = strtolower(explode(' ', trim((string)$u['name']))[0] ?? '');
        if ($uf === '' || $first === '') { continue; }
        $d = $uf === $first ? 0 : levenshtein($uf, $first);
        if ($d <= 2 && $d < $bestD) { $best = (int)$u['id']; $bestD = $d; $tie = false; }
        elseif ($d <= 2 && $d === $bestD) { $tie = true; }
    }
    return $tie ? 0 : $best;
}

/** name_key => 'online' | 'offline' | 'none'. Platforms default to online, people to not counted. */
function track_classes(): array
{
    static $c = null;
    if ($c === null) {
        $c = []; foreach (db()->query('SELECT name_key, channel FROM track_cls') as $r) { $c[$r['name_key']] = $r['channel']; }
        foreach (track_alias_map() as $nk => $label) { if (isset($c[$nk])) { $c[track_key($label)] = $c[$nk]; } } // the line name carries the same class
    }
    return $c;
}

/** name_key (a sheet name, or 'p:<platform>' for a platform-sheet row) => the sheet name it is included in. */
function track_parts(): array
{
    static $p = null;
    if ($p === null) { $p = []; foreach (db()->query("SELECT name_key, parent_key FROM track_part WHERE parent_key <> ''") as $r) { $p[$r['name_key']] = $r['parent_key']; } }
    return $p;
}

function track_channel(string $name, string $kind): string
{
    $c = track_classes();
    $k = track_key($name);
    return $c[$k] ?? 'online'; // the main sheet's total counts everyone unless you mark a name Not counted
}

function track_saved_user(string $name): int
{
    $st = db()->prepare('SELECT user_id FROM track_map WHERE name_key = ?'); $st->execute([track_key($name)]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Where a name's daily figures come from: 'sheet' (default), 'auto' (DailyDesk's own data) or 'manual' (the employee types them). */
function track_src_of(string $name): string
{
    static $m = null;
    if ($m === null) { $m = []; foreach (db()->query('SELECT name_key, src FROM track_src') as $r) { $m[$r['name_key']] = $r['src']; } }
    $v = $m[track_key($name)] ?? 'sheet';
    return in_array($v, ['auto', 'manual'], true) ? $v : 'sheet';
}

/** Daily sales DailyDesk already knows about: [day number => amount]. */
function track_auto_series(string $name, string $kind, int $uid, string $month): array
{
    $pdo = db();
    [$from, $to] = s_month_range($month);
    $out = [];
    $add = function (string $date, float $v) use (&$out) { $d = (int)substr($date, 8, 2); $out[$d] = ($out[$d] ?? 0.0) + $v; };
    if ($kind === 'person') {
        if (!$uid) { return []; }
        $st = $pdo->prepare('SELECT order_date d, SUM(amount) s FROM sales_orders WHERE user_id = ? AND order_date BETWEEN ? AND ? GROUP BY order_date'); $st->execute([$uid, $from, $to]);
        foreach ($st as $r) { $add($r['d'], (float)$r['s']); }
        return $out;
    }
    $pk = ecom_platform_key($name);
    if ($pk !== '') {
        $st = $pdo->prepare('SELECT day d, SUM(sales) s FROM ecom_daily WHERE platform = ? AND day BETWEEN ? AND ? GROUP BY day'); $st->execute([$pk, $from, $to]);
        foreach ($st as $r) { $add($r['d'], (float)$r['s']); }
        return $out;
    }
    if (stripos($name, 'shopify') !== false || stripos($name, 'website') !== false) {
        $st = $pdo->prepare('SELECT d.day d, SUM(d.sales) s FROM channel_daily d JOIN sales_channels c ON c.id = d.channel_id WHERE c.web = 1 AND d.day BETWEEN ? AND ? GROUP BY d.day'); $st->execute([$from, $to]);
        foreach ($st as $r) { $add($r['d'], (float)$r['s']); }
        if (!$out) {
            $st = $pdo->prepare('SELECT order_date d, SUM(amount) s FROM sales_orders WHERE order_date BETWEEN ? AND ? GROUP BY order_date'); $st->execute([$from, $to]);
            foreach ($st as $r) { $add($r['d'], (float)$r['s']); }
        }
    }
    return $out;
}

/** Applies the chosen source on top of what the sheet gave for one name. */
function track_overlay(string $month, string $name, string $kind, int $uid, array $days, float $monthly, int $dim): array
{
    $src = track_src_of($name);
    $today = today();
    $fill = function (int $d) use (&$days, $monthly, $dim) {
        if (!isset($days[$d])) { $days[$d] = ['a' => null, 't' => $dim > 0 ? $monthly / $dim : 0.0]; }
    };
    if ($src === 'manual') {
        $st = db()->prepare('SELECT day, achieved FROM track_manual WHERE month = ? AND name = ?'); $st->execute([$month, $name]);
        foreach ($st as $r) { $fill((int)$r['day']); $days[(int)$r['day']]['a'] = (float)$r['achieved']; }
    } elseif ($src === 'auto') {
        $series = track_auto_series($name, $kind, $uid, $month);
        if ($series) {
            for ($d = 1; $d <= $dim; $d++) {
                $fill($d);
                $date = $month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                if (isset($series[$d])) { $days[$d]['a'] = $series[$d]; }
                elseif ($date < $today) { $days[$d]['a'] = 0.0; }
                else { $days[$d]['a'] = null; }
            }
        }
    }
    ksort($days);
    return $days;
}

function track_load(string $month): array
{
    static $cache = [];
    if (isset($cache[$month])) { return $cache[$month]; }
    return $cache[$month] = track_load_raw($month);
}

function track_load_raw(string $month): array
{
    $pdo = db();
    $dim = (int)date('t', strtotime($month . '-01'));
    $cur = substr(today(), 0, 7);
    $todayDay = $month === $cur ? (int)date('j') : ($month < $cur ? $dim : 0);
    $st = $pdo->prepare('SELECT * FROM track_targets WHERE month = ? ORDER BY sort, name'); $st->execute([$month]);
    $ents = [];
    foreach ($st->fetchAll() as $r) { $ents[$r['name']] = ['name' => $r['name'], 'kind' => $r['kind'], 'monthly' => (float)$r['monthly'], 'spend' => (float)($r['spend'] ?? 0), 'days' => []]; }
    $st = $pdo->prepare('SELECT * FROM track_daily WHERE month = ?'); $st->execute([$month]);
    foreach ($st->fetchAll() as $r) {
        if (isset($ents[$r['name']])) { $ents[$r['name']]['days'][(int)$r['day']] = ['a' => $r['achieved'] === null ? null : (float)$r['achieved'], 't' => (float)$r['target']]; }
    }
    $users = $pdo->query("SELECT id, name FROM users WHERE active = 1 AND role IN ('employee','admin')")->fetchAll();
    foreach ($ents as $nm => &$e0) {
        $e0['user_id'] = $e0['kind'] === 'person' ? track_user_for($nm, $users) : track_saved_user($nm);
        $e0['src'] = track_src_of($nm);
        $e0['days'] = track_overlay($month, $nm, $e0['kind'], (int)$e0['user_id'], $e0['days'], $e0['monthly'], $dim);
    }
    unset($e0);
    // the last day anybody has numbers for (not in the future)
    $asof = 0;
    foreach ($ents as $e) { foreach ($e['days'] as $d => $x) { if ($x['a'] !== null && $d <= max($todayDay, 0) && $d > $asof) { $asof = $d; } } }
    foreach ($ents as $nm => &$e) {
        $ach = 0.0; $exp = 0.0;
        foreach ($e['days'] as $d => $x) { if ($x['a'] !== null) { $ach += $x['a']; } if ($d <= $asof) { $exp += $x['t']; } }
        $e['achieved'] = $ach; $e['expected'] = $exp; $e['asof'] = $asof; $e['dim'] = $dim;
        $e['ach_pct'] = $e['monthly'] > 0 ? $ach / $e['monthly'] * 100 : 0;
        $e['pace_pct'] = $exp > 0 ? $ach / $exp * 100 : 0;
        $e['gap'] = $ach - $exp;                                   // vs where they should be today
        $e['remaining'] = max(0.0, $e['monthly'] - $ach);
        $left = max(1, $dim - $asof);
        $e['need_day'] = $e['monthly'] > $ach ? $e['remaining'] / $left : 0.0;
        $e['projected'] = $asof > 0 ? $ach / $asof * $dim : 0.0;
        $pk0 = track_parts()[track_key($nm)] ?? '';
        $e['parent'] = ($pk0 !== '' && $pk0 !== track_key($nm)) ? (function () use ($ents, $pk0) { foreach ($ents as $x) { if (track_key($x['name']) === $pk0) { return $x['name']; } } return ''; })() : '';
        $e['channel'] = track_channel($nm, $e['kind']);
        if ($e['monthly'] > 0 && $ach >= $e['monthly']) { $e['state'] = 'hit'; }
        elseif ($asof === 0) { $e['state'] = 'none'; }
        elseif ($e['pace_pct'] >= 100) { $e['state'] = 'ahead'; }
        elseif ($e['pace_pct'] >= 90) { $e['state'] = 'on'; }
        elseif ($e['pace_pct'] >= 70) { $e['state'] = 'behind'; }
        else { $e['state'] = 'off'; }
    }
    unset($e);
    $tot = ['monthly' => 0.0, 'achieved' => 0.0, 'expected' => 0.0, 'projected' => 0.0, 'remaining' => 0.0];
    foreach ($ents as $e) { if ($e['channel'] === 'none' || $e['parent'] !== '') { continue; } foreach (array_keys($tot) as $k) { $tot[$k] += $e[$k]; } }
    $tot['ach_pct'] = $tot['monthly'] > 0 ? $tot['achieved'] / $tot['monthly'] * 100 : 0;
    $tot['pace_pct'] = $tot['expected'] > 0 ? $tot['achieved'] / $tot['expected'] * 100 : 0;
    $tot['gap'] = $tot['achieved'] - $tot['expected'];
    $left = max(1, $dim - $asof);
    $tot['need_day'] = $tot['monthly'] > $tot['achieved'] ? $tot['remaining'] / $left : 0.0;
    $ms = $pdo->prepare('SELECT synced_at FROM track_meta WHERE month = ?'); $ms->execute([$month]);
    return ['ents' => $ents, 'tot' => $tot, 'asof' => $asof, 'dim' => $dim, 'month' => $month, 'synced' => (string)($ms->fetchColumn() ?: '')];
}

function track_for_user(array $data, int $uid): ?array
{
    return track_entities_for_user($data, $uid)[0] ?? null;
}

/** Everything assigned to this employee: their own target first, then any platforms given to them. */
function track_entities_for_user(array $data, int $uid): array
{
    $out = [];
    foreach ($data['ents'] as $e) { if ($e['kind'] === 'person' && $e['user_id'] === $uid) { $out[] = $e; } }
    foreach ($data['ents'] as $e) { if ($e['kind'] === 'platform' && $e['user_id'] === $uid) { $out[] = $e; } }
    return $out;
}

/** One combined figure for several targets (used in the one-line strip). */
function track_combine(array $list, array $d): array
{
    if (count($list) === 1) { return $list[0]; }
    $c = ['name' => 'Your targets', 'monthly' => 0.0, 'achieved' => 0.0, 'expected' => 0.0];
    foreach ($list as $e) { foreach (['monthly', 'achieved', 'expected'] as $k) { $c[$k] += $e[$k]; } }
    $c['ach_pct'] = $c['monthly'] > 0 ? $c['achieved'] / $c['monthly'] * 100 : 0;
    $c['pace_pct'] = $c['expected'] > 0 ? $c['achieved'] / $c['expected'] * 100 : 0;
    $c['gap'] = $c['achieved'] - $c['expected'];
    $p = $c['pace_pct'];
    $c['state'] = $c['monthly'] > 0 && $c['achieved'] >= $c['monthly'] ? 'hit' : ($d['asof'] === 0 ? 'none' : ($p >= 100 ? 'ahead' : ($p >= 90 ? 'on' : ($p >= 70 ? 'behind' : 'off'))));
    return $c;
}

function track_state_chip(string $s): string
{
    $m = ['hit' => ['ok', 'Target hit'], 'ahead' => ['ok', 'Ahead of pace'], 'on' => ['ok', 'On track'], 'behind' => ['warn', 'Behind pace'], 'off' => ['bad', 'Far behind'], 'none' => ['', 'No data yet']];
    [$c, $t] = $m[$s] ?? ['', ''];
    return '<span class="chip' . ($c ? ' chip--' . $c : '') . '">' . h($t) . '</span>';
}

function track_asof_text(array $d): string
{
    return $d['asof'] > 0 ? 'as of ' . date('j M', strtotime($d['month'] . '-' . str_pad((string)$d['asof'], 2, '0', STR_PAD_LEFT))) : 'no figures yet';
}

/** Big card: one person's target, achieved and pace. */
function track_hero(array $e, array $d, string $title = ''): void
{
    $pct = (int)round(min(100, $e['ach_pct']));
    $pace = $e['asof'] > 0 ? (int)round($e['expected'] / max(1, $e['monthly']) * 100) : 0;
    ?>
<section class="card thero">
  <div class="thero__ring" style="background:conic-gradient(var(--pri) 0 <?= $pct ?>%,#E4EBE4 0)"><div><strong class="num"><?= (int)round($e['ach_pct']) ?>%</strong><span>of target</span></div></div>
  <div class="thero__body">
    <div class="thero__top"><h2><?= h($title !== '' ? $title : $e['name']) ?></h2><?= track_state_chip($e['state']) ?></div>
    <div class="thero__nums"><strong class="num"><?= h(inr_short($e['achieved'])) ?></strong><span class="muted">achieved of <?= h(inr_short($e['monthly'])) ?> target</span></div>
    <div class="tbar" aria-hidden="true"><i style="width:<?= $pct ?>%"></i><?php if ($pace > 0): ?><b style="left:<?= min(100, $pace) ?>%" title="Where you should be today"></b><?php endif; ?></div>
    <p class="muted tiny" style="margin:6px 0 0">The line marks where you should be today (<?= h(track_asof_text($d)) ?>).</p>
    <div class="tstats">
      <div><span>Should be at</span><strong class="num"><?= h(inr_short($e['expected'])) ?></strong></div>
      <div><span><?= $e['gap'] >= 0 ? 'Ahead by' : 'Behind by' ?></span><strong class="num <?= $e['gap'] >= 0 ? 'good' : 'bad' ?>"><?= h(inr_short(abs($e['gap']))) ?></strong></div>
      <div><span>Remaining</span><strong class="num"><?= h(inr_short($e['remaining'])) ?></strong></div>
      <div><span>Needed per day</span><strong class="num"><?= h(inr_short($e['need_day'])) ?></strong></div>
      <div><span>Projected month end</span><strong class="num"><?= h(inr_short($e['projected'])) ?></strong></div>
    </div>
  </div>
</section>
<?php
}

/** Daily bars (achieved) against the daily target line. */
function track_chart(array $e): string
{
    $dim = $e['dim']; $W = 760; $H = 200; $pl = 8; $pr = 8; $pt = 14; $pb = 26;
    $max = 1.0;
    foreach ($e['days'] as $x) { $max = max($max, (float)$x['t'], (float)($x['a'] ?? 0)); }
    $max *= 1.1;
    $bw = ($W - $pl - $pr) / $dim;
    $y = fn($v) => $pt + ($H - $pt - $pb) * (1 - $v / $max);
    $o = '<svg class="tchart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Daily achieved against daily target" preserveAspectRatio="xMidYMid meet">';
    $tline = '';
    for ($d = 1; $d <= $dim; $d++) {
        $x = $e['days'][$d] ?? ['a' => null, 't' => 0];
        $bx = $pl + ($d - 1) * $bw + $bw * 0.16; $w = $bw * 0.68;
        if ($x['a'] !== null) {
            $r = $x['t'] > 0 ? $x['a'] / $x['t'] : 1;
            $col = $r >= 1 ? '#2F6F55' : ($r >= 0.7 ? '#C27A12' : '#B42318');
            $top = $y($x['a']);
            $o .= '<rect x="' . round($bx, 1) . '" y="' . round($top, 1) . '" width="' . round($w, 1) . '" height="' . round(max(1, $H - $pb - $top), 1) . '" rx="3" fill="' . $col . '"><title>Day ' . $d . ': ' . h(inr($x['a'])) . ' of ' . h(inr($x['t'])) . '</title></rect>';
        } else {
            $o .= '<rect x="' . round($bx, 1) . '" y="' . ($H - $pb - 3) . '" width="' . round($w, 1) . '" height="3" rx="1.5" fill="#E4EBE4"/>';
        }
        if ($d === 1 || $d % 5 === 0) { $o .= '<text x="' . round($bx + $w / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle" font-size="10" fill="#5C6F65">' . $d . '</text>'; }
        $tline .= ($tline === '' ? 'M' : 'L') . round($pl + ($d - 0.5) * $bw, 1) . ' ' . round($y($x['t']), 1) . ' ';
    }
    $o .= '<path d="' . trim($tline) . '" fill="none" stroke="#16241D" stroke-width="1.5" stroke-dasharray="4 4" opacity=".55"/></svg>';
    return $o;
}

function track_daily_table(array $e, array $d): void
{
    ?>
<div class="tblwrap"><table class="tbl tbl--tight">
  <thead><tr><th>Day</th><th class="r">Target</th><th class="r">Achieved</th><th class="r">Difference</th><th>Result</th></tr></thead>
  <tbody>
  <?php for ($i = 1; $i <= $d['dim']; $i++):
      $x = $e['days'][$i] ?? null; if (!$x) { continue; }
      if ($x['a'] === null && $i > $d['asof']) { continue; }
      $future = $x['a'] === null; $diff = $future ? 0 : $x['a'] - $x['t'];
      $label = date('D, j M', strtotime($d['month'] . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT))); ?>
    <tr class="<?= $future ? 'is-dim' : '' ?>"><td><?= h($label) ?></td><td class="r num"><?= h(inr($x['t'])) ?></td>
      <td class="r num"><strong><?= $future ? '–' : h(inr($x['a'])) ?></strong></td>
      <td class="r num <?= $future ? '' : ($diff >= 0 ? 'good' : 'bad') ?>"><?= $future ? '' : ($diff >= 0 ? '+' : '−') . h(inr(abs($diff))) ?></td>
      <td><?php if (!$future): ?><span class="chip <?= $diff >= 0 ? 'chip--ok' : ($x['a'] >= $x['t'] * 0.7 ? 'chip--warn' : 'chip--bad') ?>"><?= $diff >= 0 ? 'Target met' : 'Below target' ?></span><?php endif; ?></td></tr>
  <?php endfor; ?>
  </tbody>
</table></div>
<?php if ($d['asof'] < $d['dim']): ?><p class="muted tiny" style="margin:10px 0 0">Days after <?= h(date('j M', strtotime($d['month'] . '-' . str_pad((string)$d['asof'], 2, '0', STR_PAD_LEFT)))) ?> are not filled in the sheet yet.</p><?php endif;
}

/** Team table for admin / CEO. $compact drops the drill-down links' extra columns. */
function track_team_table(array $d, string $kind, bool $compact = false): void
{
    $rows = array_filter($d['ents'], fn($e) => $e['kind'] === $kind);
    if (!$rows) { return; }
    ?>
<div class="tblwrap"><table class="tbl ttable">
  <thead><tr><th><?= $kind === 'person' ? 'Person' : 'Platform' ?></th><th class="r">Target</th><th class="r">Achieved</th><th style="min-width:150px">Progress</th><?php if (!$compact): ?><th class="r hm">Should be at</th><?php endif; ?><th class="r">Ahead / behind</th><?php if (!$compact): ?><th class="r hm">Needed / day</th><th class="r hm">Projected</th><?php endif; ?><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $e): $pace = $e['monthly'] > 0 && $d['asof'] > 0 ? min(100, $e['expected'] / $e['monthly'] * 100) : 0; ?>
    <tr>
      <td><a href="<?= h(url('track', ['month' => $d['month'], 'who' => $e['name']])) ?>"><strong><?= h($e['name']) ?></strong></a></td>
      <td class="r num"><?= h(inr_short($e['monthly'])) ?></td>
      <td class="r num"><strong><?= h(inr_short($e['achieved'])) ?></strong></td>
      <td><div class="tbar tbar--s"><i style="width:<?= round(min(100, $e['ach_pct'])) ?>%"></i><?php if ($pace > 0): ?><b style="left:<?= round($pace) ?>%"></b><?php endif; ?></div><span class="tiny muted num"><?= (int)round($e['ach_pct']) ?>% of target</span></td>
      <?php if (!$compact): ?><td class="r num hm"><?= h(inr_short($e['expected'])) ?></td><?php endif; ?>
      <td class="r num <?= $e['gap'] >= 0 ? 'good' : 'bad' ?>"><?= $d['asof'] > 0 ? ($e['gap'] >= 0 ? '+' : '−') . h(inr_short(abs($e['gap']))) : '–' ?></td>
      <?php if (!$compact): ?><td class="r num hm"><?= $e['need_day'] > 0 ? h(inr_short($e['need_day'])) : '–' ?></td><td class="r num hm"><?= $e['projected'] > 0 ? h(inr_short($e['projected'])) : '–' ?></td><?php endif; ?>
      <td><?= track_state_chip($e['state']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php
}

/** Slim card for an employee's pages: their own target in one line. Returns '' when they have none. */
function track_strip(array $u): void
{
    static $cache = null;
    $month = substr(today(), 0, 7);
    $cache ??= track_load($month);
    if (!$cache['ents']) { return; }
    $list = track_entities_for_user($cache, (int)$u['id']);
    if (!$list) { return; }
    $e = track_combine($list, $cache);
    $pct = (int)round(min(100, $e['ach_pct']));
    $pace = $e['monthly'] > 0 && $cache['asof'] > 0 ? min(100, $e['expected'] / $e['monthly'] * 100) : 0;
    ?>
  <a class="tstrip" href="<?= h(url('track')) ?>">
    <div class="tstrip__t"><span class="tstrip__k">Your target · <?= h(date('F', strtotime($month . '-01'))) ?></span><strong class="num"><?= h(inr_short($e['achieved'])) ?> <span class="muted">of <?= h(inr_short($e['monthly'])) ?></span></strong></div>
    <div class="tstrip__b"><div class="tbar tbar--s"><i style="width:<?= $pct ?>%"></i><?php if ($pace > 0): ?><b style="left:<?= round($pace) ?>%"></b><?php endif; ?></div><span class="tiny muted num"><?= (int)round($e['ach_pct']) ?>% done · <?= $e['gap'] >= 0 ? 'ahead by ' : 'behind by ' ?><?= h(inr_short(abs($e['gap']))) ?></span></div>
    <?= track_state_chip($e['state']) ?>
  </a>
<?php
}

/** Whether this employee has a target linked to them this month (cheap, cached). */
function track_has(array $u): bool
{
    static $r = [];
    $id = (int)$u['id'];
    if (!isset($r[$id])) {
        $d = track_load(substr(today(), 0, 7));
        $r[$id] = $d['ents'] ? track_entities_for_user($d, $id) !== [] : false;
    }
    return $r[$id];
}

/** Tracker achieved figures that fall inside a date range, for names tagged Online / Offline: [name_key => ['label','kind','sales','days']]. */
function track_sales_between(string $from, string $to, bool $children = false): array
{
    $out = [];
    $m = substr($from, 0, 7);
    $end = substr($to, 0, 7);
    for ($guard = 0; $m <= $end && $guard < 36; $guard++, $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        foreach (track_load($m)['ents'] as $e) {
            if ($children ? $e['parent'] === '' : ($e['channel'] === 'none' || $e['parent'] !== '')) { continue; }
            foreach ($e['days'] as $day => $x) {
                if ($x['a'] === null) { continue; }
                $date = $m . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                if ($date < $from || $date > $to) { continue; }
                $k = track_key(track_alias($e['name']));
                $out[$k] ??= ['label' => track_alias($e['name']), 'kind' => $e['channel'] === 'none' ? 'online' : $e['channel'], 'sales' => 0.0, 'days' => [], 'parent' => $e['parent']];
                $out[$k]['sales'] += (float)$x['a'];
                $out[$k]['days'][$date] = ($out[$k]['days'][$date] ?? 0.0) + (float)$x['a'];
            }
        }
    }
    return $out;
}

/** Names whose tracker figures are used on the Sales overview (set by ceo_sales, read by ceo_daily). */
function track_used(?array $set = null): array
{
    static $u = [];
    if ($set !== null) { $u = $set; }
    return $u;
}


/** Which ecom-sheet platforms are the work of one counted person (their sales are already inside that person's figure). */
function track_platform_owner(string $platform, array $ents): string
{
    $k = 'p:' . $platform;
    $saved = track_parts()[$k] ?? null;
    if ($saved !== null) { return $saved === '-' ? '' : $saved; }
    if (!in_array($platform, ['blinkit', 'myntra', 'hugulife', 'tata1mg'], true)) { return ''; }
    // Ritik runs Blinkit, Myntra, Hyugalife and Tata 1mg: by default his sheet line already contains them (change under "Already included in")
    foreach ($ents as $e) { if ($e['kind'] === 'person' && track_key($e['name']) === 'ritik' && $e['channel'] !== 'none' && $e['parent'] === '') { return 'ritik'; } }
    $st = db()->prepare("SELECT id FROM users WHERE active = 1 AND designation = ? ORDER BY id"); $st->execute([ECOM_DESIG]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ents as $e) { if ($e['kind'] === 'person' && in_array((int)$e['user_id'], $ids, true) && $e['channel'] !== 'none' && $e['parent'] === '') { return track_key($e['name']); } }
    return '';
}


/** What each person sold between two dates (from the main sale sheet), with the daily targets of those days. */
function track_people_between(string $from, string $to): array
{
    $out = [];
    $m = substr($from, 0, 7); $end = substr($to, 0, 7);
    for ($g = 0; $m <= $end && $g < 36; $g++, $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        foreach (track_load($m)['ents'] as $e) {
            if ($e['kind'] !== 'person') { continue; }
            $k = track_key($e['name']);
            $out[$k] ??= ['name' => $e['name'], 'sales' => 0.0, 'target' => 0.0, 'filled' => 0, 'days' => 0, 'user_id' => (int)($e['user_id'] ?? 0), 'order' => count($out)];
            foreach ($e['days'] as $day => $x) {
                $date = $m . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                if ($date < $from || $date > $to) { continue; }
                $out[$k]['days']++;
                $out[$k]['target'] += (float)$x['t'];
                if ($x['a'] !== null) { $out[$k]['sales'] += (float)$x['a']; $out[$k]['filled']++; }
            }
        }
    }
    return array_values($out);
}
