<?php
defined('DD') or exit;

/* Website and offline sales channels, plus the CEO sales roll-up across every platform. */

function channels_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        kind TEXT NOT NULL CHECK (kind IN ('online','offline')),
        web INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_daily (
        channel_id INTEGER NOT NULL REFERENCES sales_channels(id) ON DELETE CASCADE,
        day TEXT NOT NULL,
        sales REAL NOT NULL DEFAULT 0,
        orders INTEGER NOT NULL DEFAULT 0,
        user_id INTEGER NOT NULL DEFAULT 0,
        note TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (channel_id, day)
    )");
    $cols = array_column($pdo->query('PRAGMA table_info(channel_daily)')->fetchAll(), 'name');
    foreach (['meta', 'infl'] as $c) {
        if (!in_array($c, $cols, true)) { $pdo->exec("ALTER TABLE channel_daily ADD COLUMN $c REAL NOT NULL DEFAULT 0"); }
    }
    $pdo->exec("UPDATE sales_channels SET name = 'Shopify (website)' WHERE web = 1 AND name LIKE 'Website%'");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chd_day ON channel_daily(day)');
    if ((int)$pdo->query('SELECT COUNT(*) FROM sales_channels')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO sales_channels (name, kind, web) VALUES (?,?,?)');
        $ins->execute(['Shopify (website)', 'online', 1]);
        $ins->execute(['Offline: gyms and supplement stores', 'offline', 0]);
    }
}

function channels_all(bool $onlyActive = true): array
{
    return db()->query('SELECT * FROM sales_channels' . ($onlyActive ? ' WHERE active = 1' : '') . " ORDER BY kind DESC, name")->fetchAll();
}

function can_enter_channels(?array $u = null): bool
{
    $u = $u ?? user();
    return $u && ($u['role'] === 'admin' || ($u['role'] === 'employee' && (can_ecom_desk($u) || can_sales_desk($u))));
}

/**
 * Sales for every platform between two dates.
 * Online = Shopify (website) + marketplaces + any other online channel.
 * Shopify is the website total entered under "Other sales"; until it is entered, Inside Sales orders stand in for it.
 * Shopify is split into Meta ads, influencer codes, Inside Sales and others (the rest).
 */
function ceo_sales(string $from, string $to): array
{
    $pdo = db();
    $q = function (string $sql, array $a) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); };
    $rows = [];
    $eco = [];
    foreach ($q('SELECT platform, SUM(sales) s, SUM(orders) o, SUM(spend) sp, COUNT(*) n, MAX(day) last FROM ecom_daily WHERE day BETWEEN ? AND ? GROUP BY platform', [$from, $to]) as $r) { $eco[$r['platform']] = $r; }
    foreach (ECOM_PLATFORMS as $k => $p) {
        $r = $eco[$k] ?? null;
        $rows[] = ['key' => 'p:' . $k, 'label' => $p[0], 'group' => 'Marketplace', 'kind' => 'online', 'sales' => (float)($r['s'] ?? 0), 'orders' => (int)($r['o'] ?? 0),
            'spend' => (float)($r['sp'] ?? 0), 'days' => (int)($r['n'] ?? 0), 'last' => $r['last'] ?? null, 'platform' => $k];
    }
    $web = ['s' => 0.0, 'o' => 0, 'meta' => 0.0, 'infl' => 0.0, 'last' => null];
    foreach ($q('SELECT c.id, c.name, c.kind, c.web, SUM(d.sales) s, SUM(d.orders) o, SUM(d.meta) meta, SUM(d.infl) infl, COUNT(d.day) n, MAX(d.day) last FROM sales_channels c LEFT JOIN channel_daily d ON d.channel_id = c.id AND d.day BETWEEN ? AND ? WHERE c.active = 1 GROUP BY c.id ORDER BY c.kind DESC, c.name', [$from, $to]) as $r) {
        if ($r['web']) {
            $web['s'] += (float)$r['s']; $web['o'] += (int)$r['o']; $web['meta'] += (float)$r['meta']; $web['infl'] += (float)$r['infl'];
            if ($r['last'] && ($web['last'] === null || $r['last'] > $web['last'])) { $web['last'] = $r['last']; }
            continue;
        }
        $rows[] = ['key' => 'c:' . $r['id'], 'label' => $r['name'], 'group' => $r['kind'] === 'online' ? 'Other online' : 'Offline', 'kind' => $r['kind'], 'sales' => (float)$r['s'], 'orders' => (int)$r['o'],
            'spend' => 0.0, 'days' => (int)$r['n'], 'last' => $r['last'], 'platform' => ''];
    }
    $in = $q('SELECT SUM(amount) s, COUNT(*) o, MAX(order_date) last FROM sales_orders WHERE order_date BETWEEN ? AND ?', [$from, $to])[0];
    $names = array_column($q('SELECT DISTINCT u.name FROM sales_orders o JOIN users u ON u.id = o.user_id WHERE o.order_date BETWEEN ? AND ? ORDER BY u.name', [$from, $to]), 'name');
    $inside = (float)$in['s'];
    if ($web['meta'] <= 0) { $web['meta'] = ads_meta_sales($from, $to); }
    $entered = $web['s'] > 0;
    $shTotal = $entered ? $web['s'] : $inside;
    $others = $entered ? max(0.0, $web['s'] - $web['meta'] - $web['infl'] - $inside) : 0.0;
    $shopify = ['key' => 'shopify', 'label' => 'Shopify (website)', 'group' => 'Shopify', 'kind' => 'online', 'sales' => $shTotal, 'orders' => $entered ? $web['o'] : (int)$in['o'],
        'spend' => 0.0, 'last' => $entered ? $web['last'] : $in['last'], 'platform' => '', 'entered' => $entered,
        'subs' => [
            ['key' => 'sub:meta', 'label' => 'From Meta ads', 'sales' => $web['meta'], 'orders' => null],
            ['key' => 'sub:infl', 'label' => 'From influencer codes', 'sales' => $web['infl'], 'orders' => null],
            ['key' => 'sub:inside', 'label' => 'From Inside Sales' . ($names ? ' (' . implode(', ', $names) . ')' : ''), 'sales' => $inside, 'orders' => (int)$in['o']],
            ['key' => 'sub:others', 'label' => 'Others (rest of Shopify)', 'sales' => $others, 'orders' => null],
        ]];
    array_unshift($rows, $shopify);
    // The main sale sheet (Targets tracker) is the source of every total. Names tagged Online / Offline take over their line;
    // the platform-wise sheets keep supplying the split and the ad spend, shown but never added a second time.
    $used = []; $skip = [];
    $ents = track_load(substr($from, 0, 7))['ents'];
    // Total spend per name from the main sheet (Summary tab "Total Spending"), shared out over the days asked for (month by month)
    $spendBy = []; $sheetSpend = false;
    for ($mm = substr($from, 0, 7), $g = 0; $mm <= substr($to, 0, 7) && $g < 36; $g++, $mm = date('Y-m', strtotime($mm . '-01 +1 month'))) {
        $mStart = $mm . '-01'; $dim = (int)date('t', strtotime($mStart)); $mEnd = $mm . '-' . $dim;
        $a = max($from, $mStart); $b = min($to, $mEnd);
        $frac = $b >= $a ? ((strtotime($b) - strtotime($a)) / 86400 + 1) / $dim : 0.0;
        foreach (track_load($mm)['ents'] as $e) {
            $sp = (float)($e['spend'] ?? 0);
            if ($sp > 0) { $sheetSpend = true; }
            $spendBy[track_key($e['name'])] = ($spendBy[track_key($e['name'])] ?? 0.0) + $sp * $frac;
        }
    }
    foreach (track_sales_between($from, $to) as $tk => $t) {
        if ($t['sales'] <= 0) { continue; }
        $hit = null;
        foreach ($rows as $i => $r) {
            $rk = track_key($r['label']);
            if ($rk === $tk || str_starts_with($rk, $tk)) { $hit = $i; break; }
        }
        if ($hit !== null) {
            if ($sheetSpend) { $rows[$hit]['detail_spend'] = $rows[$hit]['spend']; $rows[$hit]['spend'] = $spendBy[$tk] ?? 0.0; }
            $rows[$hit]['detail'] = $rows[$hit]['sales']; $rows[$hit]['sales'] = $t['sales']; $rows[$hit]['kind'] = $t['kind']; $rows[$hit]['src'] = 'tracker';
            if ($rows[$hit]['key'] === 'shopify') {
                $skip['web'] = true;
                foreach ($rows[$hit]['subs'] as &$sb) { if ($sb['key'] === 'sub:others') { $sb['sales'] = max(0.0, $t['sales'] - $web['meta'] - $web['infl'] - $inside); } } unset($sb);
            }
            if (!empty($rows[$hit]['platform'])) { $skip[$rows[$hit]['platform']] = true; }
            $used[$tk] = $t['days'];
        } else {
            $rows[] = ['key' => 't:' . $tk, 'label' => $t['label'], 'group' => $t['kind'] === 'online' ? 'Other online' : 'Offline', 'kind' => $t['kind'], 'sales' => $t['sales'], 'orders' => 0,
                'spend' => $sheetSpend ? ($spendBy[$tk] ?? 0.0) : 0.0, 'days' => count($t['days']), 'last' => max(array_keys($t['days'])), 'platform' => '', 'src' => 'tracker'];
            $used[$tk] = $t['days'];
        }
    }
    ceo_skip(substr($from, 0, 7) . '|u', $used);
    // Platform-sheet rows that belong to a counted person (Ritik's Blinkit, Myntra, Hyugalife, Tata 1mg): shown, not added.
    foreach ($rows as $i => $r) {
        if (empty($r['platform']) || !empty($r['src'])) { continue; }
        $own = track_platform_owner($r['platform'], $ents);
        if ($own !== '' && isset($used[$own])) {
            $label = ''; foreach ($ents as $e) { if (track_key($e['name']) === $own) { $label = $e['name']; } }
            $rows[$i]['in_total'] = false; $rows[$i]['part_of'] = $label; $skip[$r['platform']] = true;
        }
    }
    // Names that are already part of another line (for example Sourabh inside Shopify): shown, not added.
    foreach (track_sales_between($from, $to, true) as $t) {
        if ($t['sales'] <= 0) { continue; }
        $rows[] = ['key' => 't:' . track_key($t['label']), 'label' => $t['label'], 'group' => 'Included', 'kind' => $t['kind'], 'sales' => $t['sales'], 'orders' => 0, 'spend' => 0.0,
            'days' => count($t['days']), 'last' => max(array_keys($t['days'])), 'platform' => '', 'src' => 'tracker', 'in_total' => false, 'part_of' => $t['parent']];
    }
    ceo_skip(substr($from, 0, 7), $skip);
    $tot = ['online' => 0.0, 'offline' => 0.0, 'orders' => 0, 'spend' => 0.0, 'mp_sales' => 0.0];
    foreach ($rows as $r) {
        if (!($sheetSpend && ($r['in_total'] ?? true) === false)) { $tot['spend'] += $r['spend']; } // spend of lines inside another line is already in that line's figure

        if ($r['group'] === 'Marketplace') { $tot['mp_sales'] += $r['detail'] ?? $r['sales']; }
        if (($r['in_total'] ?? true) === false) { continue; }
        $tot[$r['kind']] += $r['sales'];
        $tot['orders'] += $r['orders'];
    }
    $tot['all'] = $tot['online'] + $tot['offline'];
    $tot['sheet_spend'] = $sheetSpend;
    return ['rows' => $rows, 'tot' => $tot, 'web_entered' => $entered];
}

/** Lines whose own sheet data must not be added to daily totals because the main sale sheet already covers them. */
function ceo_skip(string $key, ?array $set = null): array
{
    static $s = [];
    if ($set !== null) { $s[$key] = $set; }
    return $s[$key] ?? [];
}

/** Per-day online and offline totals for a date range (same counting rules as ceo_sales). */
function ceo_daily(string $from, string $to, bool $webEntered): array
{
    $pdo = db();
    $d = [];
    $add = function (string $day, string $kind, float $v) use (&$d) { $d[$day] ??= ['online' => 0.0, 'offline' => 0.0]; $d[$day][$kind] += $v; };
    $skip = ceo_skip(substr($from, 0, 7));
    $st = $pdo->prepare('SELECT day, platform, SUM(sales) s FROM ecom_daily WHERE day BETWEEN ? AND ? GROUP BY day, platform'); $st->execute([$from, $to]);
    foreach ($st as $r) { if (empty($skip[$r['platform']])) { $add($r['day'], 'online', (float)$r['s']); } }
    $st = $pdo->prepare('SELECT d.day, c.kind, SUM(d.sales) s FROM channel_daily d JOIN sales_channels c ON c.id = d.channel_id WHERE c.active = 1 ' . (!empty($skip['web']) ? 'AND c.web = 0 ' : '') . 'AND d.day BETWEEN ? AND ? GROUP BY d.day, c.kind'); $st->execute([$from, $to]);
    foreach ($st as $r) { $add($r['day'], $r['kind'], (float)$r['s']); }
    if (!$webEntered && empty($skip['web'])) {
        $st = $pdo->prepare('SELECT order_date day, SUM(amount) s FROM sales_orders WHERE order_date BETWEEN ? AND ? GROUP BY order_date'); $st->execute([$from, $to]);
        foreach ($st as $r) { $add($r['day'], 'online', (float)$r['s']); }
    }
    foreach (ceo_skip(substr($from, 0, 7) . '|u') as $tk => $days) { foreach ($days as $date => $v) { if ($date >= $from && $date <= $to) { $kind = track_classes()[$tk] ?? 'online'; $add($date, $kind === 'offline' ? 'offline' : 'online', (float)$v); } } }
    krsort($d);
    return $d;
}

/** Month-by-month team task summary: one row per person. */
function tasks_month(string $month): array
{
    $pdo = db();
    [$s, $e] = s_month_range($month);
    $people = $pdo->query("SELECT id, name, designation FROM users WHERE active = 1 AND role = 'employee' ORDER BY name")->fetchAll();
    $per = [];
    foreach ($people as $p) { $per[(int)$p['id']] = $p + ['work' => 0, 'leave' => 0, 'tasks' => 0, 'done' => 0, 'prog' => 0, 'blocked' => 0, 'hours' => 0.0]; }
    $st = $pdo->prepare('SELECT user_id, type, COUNT(*) n FROM reports WHERE report_date BETWEEN ? AND ? GROUP BY user_id, type'); $st->execute([$s, $e]);
    foreach ($st as $r) { if (isset($per[(int)$r['user_id']])) { $per[(int)$r['user_id']][$r['type'] === 'leave' ? 'leave' : 'work'] = (int)$r['n']; } }
    $st = $pdo->prepare('SELECT r.user_id, t.status, COUNT(*) n, SUM(t.hours) h FROM tasks t JOIN reports r ON r.id = t.report_id WHERE r.report_date BETWEEN ? AND ? GROUP BY r.user_id, t.status'); $st->execute([$s, $e]);
    foreach ($st as $r) {
        if (!isset($per[(int)$r['user_id']])) { continue; }
        $x = &$per[(int)$r['user_id']];
        $x['tasks'] += (int)$r['n']; $x['hours'] += (float)$r['h'];
        if ($r['status'] === 'done') { $x['done'] += (int)$r['n']; } elseif ($r['status'] === 'blocked') { $x['blocked'] += (int)$r['n']; } else { $x['prog'] += (int)$r['n']; }
        unset($x);
    }
    $cat = [];
    $st = $pdo->prepare('SELECT t.category c, SUM(t.hours) h FROM tasks t JOIN reports r ON r.id = t.report_id WHERE r.report_date BETWEEN ? AND ? GROUP BY t.category ORDER BY h DESC'); $st->execute([$s, $e]);
    foreach ($st as $r) { $cat[$r['c']] = (float)$r['h']; }
    return ['per' => array_values($per), 'cat' => $cat, 'wd' => count(working_days($s, min($e, today())))];
}


/** The period a dashboard shows: today, yesterday, 7 days, 30 days or one month (?range= and ?month=). */
function period_pick(): array
{
    $today = today();
    $k = (string)($_GET['range'] ?? 'month');
    if (!in_array($k, ['today', 'yesterday', '7d', '30d', 'month'], true)) { $k = 'month'; }
    $month = s_month($_GET['month'] ?? null);
    switch ($k) {
        case 'today':     $f = $t = $today; $label = 'Today'; break;
        case 'yesterday': $f = $t = add_days($today, -1); $label = 'Yesterday'; break;
        case '7d':        $f = add_days($today, -6); $t = $today; $label = 'Last 7 days'; break;
        case '30d':       $f = add_days($today, -29); $t = $today; $label = 'Last 30 days'; break;
        default:
            [$f, $t] = s_month_range($month); $label = date('F Y', strtotime($month . '-01'));
    }
    if ($k === 'month') {
        $pm = date('Y-m', strtotime($month . '-01 -1 month')); [$pf, $pt] = s_month_range($pm); $plabel = date('M', strtotime($pm . '-01'));
    } else {
        $n = (int)round((strtotime($t) - strtotime($f)) / 86400) + 1; $pf = add_days($f, -$n); $pt = add_days($f, -1);
        $plabel = $k === 'today' ? 'yesterday' : ($k === 'yesterday' ? 'day before' : 'previous ' . $n . ' days');
    }
    $mon = $k === 'month' ? $month : substr($t, 0, 7);
    $days = (int)round((strtotime(min($t, $today)) - strtotime($f)) / 86400) + 1;
    return ['key' => $k, 'from' => $f, 'to' => $t, 'label' => $label, 'month' => $mon, 'pf' => $pf, 'pt' => $pt, 'plabel' => $plabel, 'days' => max(1, $days), 'isMonth' => $k === 'month'];
}

/** Today / Yesterday / 7 days / 30 days / Month switch (a small form). */
function period_bar(string $page, array $P, array $keep = []): string
{
    $o = '<form method="get" class="filters" style="margin:0"><input type="hidden" name="p" value="' . h($page) . '">';
    foreach ($keep as $k => $v) { $o .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">'; }
    $o .= '<div class="seg" role="group" aria-label="Period">';
    foreach (['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days', 'month' => 'Month'] as $k => $l) {
        $o .= '<label><input type="radio" name="range" value="' . $k . '"' . ($P['key'] === $k ? ' checked' : '') . ' data-autosubmit><span>' . $l . '</span></label>';
    }
    $o .= '</div>';
    if ($P['key'] === 'month') {
        $o .= '<label class="field"><span class="sr">Month</span><select name="month" data-autosubmit>';
        $opts = []; for ($i = 0; $i < 12; $i++) { $opts[] = date('Y-m', strtotime(substr(today(), 0, 7) . '-01 -' . $i . ' month')); }
        if (!in_array($P['month'], $opts, true)) { array_unshift($opts, $P['month']); }
        foreach ($opts as $m) { $o .= '<option value="' . h($m) . '"' . ($m === $P['month'] ? ' selected' : '') . '>' . h(date('F Y', strtotime($m . '-01'))) . '</option>'; }
        $o .= '</select></label>';
    }
    return $o . '</form>';
}
