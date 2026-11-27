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
    $pdo->exec("CREATE TABLE IF NOT EXISTS offline_orders (
        no TEXT PRIMARY KEY, day TEXT NOT NULL, customer TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', seller TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', total REAL NOT NULL DEFAULT 0, advance REAL NOT NULL DEFAULT 0, balance REAL NOT NULL DEFAULT 0
    )");
    $oc = array_column($pdo->query('PRAGMA table_info(offline_orders)')->fetchAll(), 'name');
    if (!in_array('stream', $oc, true)) { $pdo->exec("ALTER TABLE offline_orders ADD COLUMN stream TEXT NOT NULL DEFAULT 'offline'"); }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_off_day ON offline_orders(day)');
    // One-time: the team's offline channel is simply called "Offline"; Bhanu's sheet line is "Bhanu credit offline" and counts as offline
    try {
        $pdo->exec("UPDATE sales_channels SET name = 'Offline' WHERE name = 'Offline: gyms and supplement stores' AND NOT EXISTS (SELECT 1 FROM sales_channels WHERE name = 'Offline')");
        // Bhanu Verma in the main sheet is the Inside Sales Bhanu: an earlier version wrongly renamed him "Bhanu credit offline" and made him offline
        if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'fix_bhanu_inside'")->fetchColumn()) {
            $pdo->exec("DELETE FROM settings WHERE k = 'track_alias:bhanuverma'");
            $pdo->exec("DELETE FROM track_cls WHERE name_key = 'bhanuverma'");
            $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('fix_bhanu_inside', '1')");
        }
    } catch (Throwable $e) { /* never block start-up */ }
    if ((int)$pdo->query('SELECT COUNT(*) FROM sales_channels')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO sales_channels (name, kind, web) VALUES (?,?,?)');
        $ins->execute(['Shopify (website)', 'online', 1]);
        $ins->execute(['Offline', 'offline', 0]);
    }
}

const OFFLINE_STREAMS = ['offline' => 'Offline', 'bhanu' => 'Bhanu credit offline'];

/** Channel id for an offline stream ('offline' = the offline team, 'bhanu' = Bhanu credit offline); created if missing. */
function offline_channel_id(string $stream = 'offline'): int
{
    $pdo = db();
    $name = OFFLINE_STREAMS[$stream] ?? 'Offline';
    $st = $pdo->prepare("SELECT id FROM sales_channels WHERE name = ? AND web = 0"); $st->execute([$name]);
    $id = (int)$st->fetchColumn();
    if (!$id) { $pdo->prepare("INSERT INTO sales_channels (name, kind, web, active) VALUES (?, 'offline', 0, 1)")->execute([$name]); $id = (int)$pdo->lastInsertId(); }
    return $id;
}

/** "2026-11-24", "24/11/2026", "24-11-26", "24 Nov 2026" => "2026-11-24" or ''. */
function offline_date(string $v): string
{
    $v = trim($v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) { return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : ''; }
    if (preg_match('/^(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2}|\d{4})(?!\d)/', $v, $m)) {   // 5/11/2026, 05-11-26, 26.11.2026 14:30 (day first, as in India)
        $y = (int)$m[3]; if ($y < 100) { $y += 2000; }
        if (checkdate((int)$m[2], (int)$m[1], $y)) { return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]); }
        return '';
    }
    foreach (['j M Y', 'j-M-Y', 'j F Y', 'j-M-y'] as $f) {
        $d = DateTime::createFromFormat($f, $v);
        if ($d) { return $d->format('Y-m-d'); }
    }
    return '';
}

/**
 * Import an invoice CSV for an offline stream. Columns are matched by name, so both the offline team's file
 * (No, Date, Customer, Phone, Seller, Order Status, Courier / Note, Total, Advance, Balance) and a credit-sale file work.
 * Invoices in the file's date range are replaced by the file, then the stream's daily totals are rebuilt.
 */
function offline_import(string $path, string $stream = 'offline'): array
{
    $pdo = db();
    $stream = isset(OFFLINE_STREAMS[$stream]) ? $stream : 'offline';
    $fh = fopen($path, 'r'); if (!$fh) { throw new RuntimeException('The file could not be opened.'); }
    $syn = ['no' => ['no', 'invoice', 'invoiceno', 'invoicenumber', 'bill', 'billno', 'ref', 'refno'], 'date' => ['date', 'invoicedate', 'orderdate', 'saledate'],
        'customer' => ['customer', 'party', 'partyname', 'customername', 'client', 'name', 'shop'], 'phone' => ['phone', 'mobile', 'contact', 'phoneno', 'mobileno'],
        'seller' => ['seller', 'salesperson', 'executive', 'soldby', 'salesman'], 'status' => ['orderstatus', 'status'], 'note' => ['couriernote', 'note', 'notes', 'remarks', 'courier'],
        'total' => ['total', 'amount', 'sale', 'saleamount', 'billamount', 'invoicevalue', 'invoiceamount', 'creditamount', 'totalamount'],
        'advance' => ['advance', 'received', 'paid', 'collected', 'paidamount', 'receivedamount', 'advancereceived'],
        'balance' => ['balance', 'due', 'outstanding', 'pending', 'balanceamount', 'dueamount', 'pendingamount']];
    $hdr = null; $rows = []; $skipped = 0; $seq = 0; $skipWhy = [];
    $num = fn($v) => round((float)str_replace([',', '₹', ' '], '', (string)$v), 2);
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($hdr === null) {
            $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($r[0] ?? ''));
            $names = array_map(fn($c) => strtolower(preg_replace('/[^a-z]+/i', '', (string)$c)), $r);
            $m = [];
            foreach ($syn as $f => $list) { foreach ($list as $alias) { $i = array_search($alias, $names, true); if ($i !== false && !in_array($i, $m, true)) { $m[$f] = $i; break; } } }
            if (isset($m['date'], $m['total'])) { $hdr = $m; }
            continue;
        }
        $g = fn($k) => isset($hdr[$k], $r[$hdr[$k]]) ? trim((string)$r[$hdr[$k]]) : '';
        $day = offline_date($g('date')); $tot = $num($g('total'));
        if ($day === '' || $tot <= 0) { $skipped++; if (count($skipWhy) < 3) { $skipWhy[] = ($day === '' ? 'date "' . mb_substr($g('date'), 0, 20) . '"' : 'total ' . $g('total')) . ' (' . mb_substr($g('no'), 0, 20) . ')'; } continue; }
        $adv = isset($hdr['advance']) ? $num($g('advance')) : (isset($hdr['balance']) ? max(0.0, $tot - $num($g('balance'))) : 0.0);
        $adv = min($adv, $tot);
        $no = $g('no'); if ($no === '') { $no = 'AUTO-' . substr(md5($day . '|' . $g('customer') . '|' . $tot . '|' . (++$seq)), 0, 8); }
        $key = ($stream === 'bhanu' ? 'B:' : '') . mb_substr($no, 0, 40);
        $rows[$key] = [$key, $day, mb_substr($g('customer'), 0, 120), preg_replace('/[^0-9+]/', '', $g('phone')), mb_substr($g('seller'), 0, 60), mb_substr($g('status'), 0, 40),
            mb_substr($g('note'), 0, 120), $tot, $adv, max(0.0, round($tot - $adv, 2)), $stream];
    }
    fclose($fh);
    if ($hdr === null) { throw new RuntimeException('This does not look like an invoice file. It needs at least a Date column and a Total (or Amount) column.'); }
    if (!$rows) { throw new RuntimeException('No invoices were found in the file.'); }
    $days = array_column($rows, 1); $from = min($days); $to = max($days);
    $cid = offline_channel_id($stream);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM offline_orders WHERE stream = ? AND day BETWEEN ? AND ?')->execute([$stream, $from, $to]);
        $ins = $pdo->prepare('INSERT OR REPLACE INTO offline_orders (no, day, customer, phone, seller, status, note, total, advance, balance, stream) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $r) { $ins->execute($r); }
        $pdo->prepare('DELETE FROM channel_daily WHERE channel_id = ? AND day BETWEEN ? AND ?')->execute([$cid, $from, $to]);
        $pdo->prepare("INSERT INTO channel_daily (channel_id, day, sales, orders, user_id, note, updated_at) SELECT ?, day, ROUND(SUM(total),2), COUNT(*), 0, 'Imported invoices', ? FROM offline_orders WHERE stream = ? AND day BETWEEN ? AND ? GROUP BY day")
            ->execute([$cid, date('Y-m-d H:i:s'), $stream, $from, $to]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return ['rows' => count($rows), 'from' => $from, 'to' => $to, 'sales' => array_sum(array_column($rows, 7)), 'skipped' => $skipped, 'why' => $skipWhy];
}

/** All invoices of a stream between two dates. */
function offline_orders_between(string $from, string $to, string $stream = 'offline'): array
{
    $st = db()->prepare('SELECT * FROM offline_orders WHERE stream = ? AND day BETWEEN ? AND ? ORDER BY day DESC, no DESC'); $st->execute([$stream, $from, $to]);
    return $st->fetchAll();
}

/** Offline invoices by seller between two dates: [['seller','orders','sales','advance','balance']] biggest first. */
function offline_by_seller(string $from, string $to, string $stream = 'offline'): array
{
    $st = db()->prepare("SELECT seller, COUNT(*) orders, SUM(total) sales, SUM(advance) advance, SUM(balance) balance FROM offline_orders WHERE stream = ? AND day BETWEEN ? AND ? GROUP BY seller ORDER BY sales DESC");
    $st->execute([$stream, $from, $to]);
    return $st->fetchAll();
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
        $row = ['key' => 'c:' . $r['id'], 'label' => $r['name'], 'group' => $r['kind'] === 'online' ? 'Other online' : 'Offline', 'kind' => $r['kind'], 'sales' => (float)$r['s'], 'orders' => (int)$r['o'],
            'spend' => 0.0, 'days' => (int)$r['n'], 'last' => $r['last'], 'platform' => ''];
        if ($r['name'] === 'Offline') {
            $subs = [];
            foreach (offline_by_seller($from, $to) as $sl) { $subs[] = ['key' => 'sub:off:' . track_key($sl['seller']), 'label' => $sl['seller'] ?: 'No seller', 'sales' => (float)$sl['sales'], 'orders' => (int)$sl['orders']]; }
            if ($subs) { $row['subs'] = $subs; }
        }
        $rows[] = $row;
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
            $ak = track_key(track_alias($e['name'])); $spendBy[$ak] = ($spendBy[$ak] ?? 0.0) + $sp * $frac;
        }
    }
    foreach (track_sales_between($from, $to) as $tk => $t) {
        if ($t['sales'] <= 0) { continue; }
        // A line fed by uploaded invoices (e.g. Bhanu credit offline) replaces its sheet figure, so it is never counted twice
        foreach ($rows as $r0) { if (($r0['group'] ?? '') === 'Offline' && str_starts_with((string)$r0['key'], 'c:') && track_key($r0['label']) === $tk && $r0['sales'] > 0) { continue 2; } }
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


/** Reads the first tab of a workbook that has an invoice table (Date and Total columns) and imports it like the CSV upload. */
function offline_import_xlsx(string $path, string $stream = 'offline'): array
{
    foreach (xlsx_read($path) as $t) {
        $hi = -1;
        foreach (array_slice($t['rows'], 0, 8, true) as $i => $r) {
            $n = array_map(fn($c) => strtolower(preg_replace('/[^a-z]+/i', '', (string)$c)), $r);
            if (in_array('date', $n, true) && (in_array('total', $n, true) || in_array('amount', $n, true))) { $hi = $i; $dateCol = array_search('date', $n, true); break; }
        }
        if ($hi < 0) { continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'of'); $fh = fopen($tmp, 'w');
        foreach (array_slice($t['rows'], $hi) as $k => $r) {
            if ($k > 0 && isset($r[$dateCol]) && is_numeric($r[$dateCol]) && (float)$r[$dateCol] > 30000) { $r[$dateCol] = gmdate('d-m-Y', ((int)floor((float)$r[$dateCol]) - 25569) * 86400); }
            fputcsv($fh, array_map(fn($c) => is_float($c) ? rtrim(rtrim(sprintf('%.3F', $c), '0'), '.') : (string)$c, $r), ',', '"', '');
        }
        fclose($fh);
        try { return offline_import($tmp, $stream); } finally { @unlink($tmp); }
    }
    throw new RuntimeException('No invoice table found. The sheet needs a header row with Date and Total columns.');
}
