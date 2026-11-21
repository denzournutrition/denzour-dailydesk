<?php
defined('DD') or exit;

/* =====================================================================
   Attendance: first login = start of day, lunch and tea breaks,
   late / missing reasons, and CSV exports
   ===================================================================== */

const ATT_REASONS = [
    'Traffic or commute problem',
    'Not well',
    'Field visit or meeting',
    'Internet or system problem',
    'Forgot to log in (I was working)',
    'Approved leave',
    'Personal emergency',
    'Other',
];

function att_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS att_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        day TEXT NOT NULL,
        kind TEXT NOT NULL,
        at TEXT NOT NULL,
        src TEXT NOT NULL DEFAULT 'manual'
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_ev ON att_events(user_id, day)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS att_seen (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        day TEXT NOT NULL,
        last_seen TEXT NOT NULL,
        PRIMARY KEY (user_id, day)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS att_reasons (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        day TEXT NOT NULL,
        kind TEXT NOT NULL,
        reason TEXT NOT NULL,
        note TEXT NOT NULL DEFAULT '',
        claimed_in TEXT NOT NULL DEFAULT '',
        claimed_out TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        review TEXT NOT NULL DEFAULT 'pending',
        reviewed_by INTEGER,
        reviewed_at TEXT,
        PRIMARY KEY (user_id, day, kind)
    )");
    $pdo->exec("INSERT OR IGNORE INTO settings (k, v) VALUES ('att_since', '" . date('Y-m-d') . "')");
}

/* ---------- settings ---------- */
function setting(string $k, string $d = ''): string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $d : (string)$v;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

function att_cfg(): array
{
    return [
        'start' => preg_match('/^\d{2}:\d{2}$/', setting('att_start_time')) ? setting('att_start_time') : '10:00',
        'grace' => (int)setting('att_grace', '15'),
        'lunch' => (int)setting('att_lunch', '30'),
        'tea'   => (int)setting('att_tea', '15'),
        'hours' => (float)setting('att_hours', '8.25'),
        'end'   => preg_match('/^\d{2}:\d{2}$/', setting('att_end')) ? setting('att_end') : '19:00',
        'lunch_at' => preg_match('/^\d{2}:\d{2}$/', setting('att_lunch_at')) ? setting('att_lunch_at') : '14:00',
        'tea_at'   => preg_match('/^\d{2}:\d{2}$/', setting('att_tea_at')) ? setting('att_tea_at') : '17:00',
        'since' => setting('att_since', date('Y-m-d')),
        'remind' => preg_match('/^\d{2}:\d{2}$/', setting('att_remind')) ? setting('att_remind') : '17:00',
    ];
}

/* ---------- formatting ---------- */
function fmt_hm(?int $min): string
{
    if ($min === null) { return '–'; }
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h > 0 ? $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm' : $m . 'm';
}

function att_clock(?string $dt): string
{
    return $dt ? date('g:i a', strtotime($dt)) : '–';
}

function is_working_day(string $d): bool
{
    return (bool)working_days($d, $d);
}

/* ---------- reading and calculating ---------- */
/** @return array<int, array<string, list<array>>> events[user][day] */
function att_events(array $uids, string $from, string $to): array
{
    if (!$uids) { return []; }
    $in = implode(',', array_map('intval', $uids));
    $st = db()->prepare("SELECT * FROM att_events WHERE user_id IN ($in) AND day BETWEEN ? AND ? ORDER BY at, id");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['user_id']][$r['day']][] = $r; }
    return $out;
}

function att_seen(array $uids, string $from, string $to): array
{
    if (!$uids) { return []; }
    $in = implode(',', array_map('intval', $uids));
    $st = db()->prepare("SELECT user_id, day, last_seen FROM att_seen WHERE user_id IN ($in) AND day BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['user_id']][$r['day']] = $r['last_seen']; }
    return $out;
}

function att_reasons(array $uids, string $from, string $to): array
{
    if (!$uids) { return []; }
    $in = implode(',', array_map('intval', $uids));
    $st = db()->prepare("SELECT * FROM att_reasons WHERE user_id IN ($in) AND day BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['user_id']][$r['day']][$r['kind']] = $r; }
    return $out;
}

/** Turn one person's events for one day into numbers. */
function att_calc(array $events, ?string $lastSeen, string $day, array $c): array
{
    $now = date('Y-m-d H:i:s');
    $isToday = $day === today();
    $r = ['in' => null, 'out' => null, 'out_auto' => false, 'state' => 'not_in', 'lunch' => 0, 'tea' => 0, 'teas' => 0,
          'gross' => null, 'net' => null, 'late' => 0, 'is_late' => false, 'incomplete' => false, 'open_since' => null];
    foreach ($events as $e) { if ($e['kind'] === 'in') { $r['in'] = $e['at']; break; } }
    if ($r['in'] === null) { return $r; }

    $state = 'working'; $since = $r['in']; $open = null; $lastOut = null;
    foreach ($events as $e) {
        $k = $e['kind'];
        if ($k === 'in') { $state = 'working'; $since = $e['at']; $lastOut = null; }
        elseif ($k === 'lunch_start') { $state = 'on_lunch'; $open = ['lunch', $e['at']]; $since = $e['at']; }
        elseif ($k === 'tea_start') { $state = 'on_tea'; $open = ['tea', $e['at']]; $since = $e['at']; }
        elseif ($k === 'lunch_end' || $k === 'tea_end') {
            if ($open) {
                $m = max(0, (int)round((strtotime($e['at']) - strtotime($open[1])) / 60));
                $r[$open[0]] += $m;
                if ($open[0] === 'tea') { $r['teas']++; }
            }
            $open = null; $state = 'working'; $since = $e['at'];
        } elseif ($k === 'out') {
            if ($open) {
                $m = max(0, (int)round((strtotime($e['at']) - strtotime($open[1])) / 60));
                $r[$open[0]] += $m; $open = null;
            }
            $state = 'done'; $lastOut = $e['at']; $since = $e['at'];
        }
    }
    $end = null;
    if ($state === 'done') {
        $end = $lastOut; $r['out'] = $lastOut;
    } elseif ($isToday) {
        $end = $now;
    } else {
        // Forgot to press End day: use the last time they were seen in DailyDesk.
        $last = $r['in'];
        foreach ($events as $e) { $last = max($last, $e['at']); }
        if ($lastSeen) { $last = max($last, $lastSeen); }
        $end = $last; $r['out'] = $last; $r['out_auto'] = true; $r['incomplete'] = true;
    }
    if ($open) {
        $openEnd = $end ?? $now;
        $r[$open[0]] += max(0, (int)round((strtotime($openEnd) - strtotime($open[1])) / 60));
    }
    $r['state'] = ($isToday || $state === 'done') ? $state : 'done';
    $r['open_since'] = $since;
    $gross = max(0, (int)round((strtotime($end) - strtotime($r['in'])) / 60));
    $r['gross'] = $gross;
    $r['net'] = max(0, $gross - $r['lunch'] - $r['tea']);
    $startTs = strtotime($day . ' ' . $c['start'] . ':00');
    $r['late'] = max(0, (int)round((strtotime($r['in']) - $startTs) / 60));
    $r['is_late'] = $r['late'] > $c['grace'];
    return $r;
}

/** Everything about attendance for a set of people over a date range. */
const ATT_FORGOT = 'Forgot to log in (I was working)';

/** "Forgot to log in": the day counts from the official start, with the fixed lunch and tea breaks taken off. */
function att_apply_forgot(array $r, array $reasons, string $day, array $c): array
{
    $why = null;
    foreach (['late', 'missing'] as $k) { if (isset($reasons[$k]) && $reasons[$k]['reason'] === ATT_FORGOT) { $why = $reasons[$k]; break; } }
    if (!$why) { return $r; }
    $start = $day . ' ' . $c['start'] . ':00';
    $endPlan = $day . ' ' . $c['end'] . ':00';
    $now = date('Y-m-d H:i:s');
    $isToday = $day === today();
    if ($isToday && $now < $start) { return $r; }
    $end = $isToday ? min($now, $endPlan) : $endPlan;
    if (!empty($why['claimed_out']) && !$isToday) { $end = min($endPlan, $day . ' ' . $why['claimed_out'] . ':00'); }
    if ($r['in'] !== null && !$r['out_auto'] && $r['out'] !== null && $r['state'] === 'done' && !$isToday) { $end = max($r['out'], $end); }
    $lunchAt = $day . ' ' . $c['lunch_at'] . ':00';
    $teaAt = $day . ' ' . $c['tea_at'] . ':00';
    $lunch = $r['lunch'] > 0 ? $r['lunch'] : ($end >= date('Y-m-d H:i:s', strtotime($lunchAt) + $c['lunch'] * 60) ? $c['lunch'] : 0);
    $tea = $r['tea'] > 0 ? $r['tea'] : ($end >= date('Y-m-d H:i:s', strtotime($teaAt) + $c['tea'] * 60) ? $c['tea'] : 0);
    $r['in'] = $start; $r['late'] = 0; $r['is_late'] = false;
    if ($r['state'] !== 'on_lunch' && $r['state'] !== 'on_tea') { $r['out'] = ($isToday ? null : $end); }
    $r['out_auto'] = false; $r['incomplete'] = false; $r['adjusted'] = true;
    if ($r['state'] === 'not_in') { $r['state'] = $isToday ? 'working' : 'done'; }
    $r['lunch'] = $lunch; $r['tea'] = $tea; $r['teas'] = $tea > 0 ? max(1, $r['teas']) : $r['teas'];
    $gross = max(0, (int)round((strtotime($end) - strtotime($start)) / 60));
    $r['gross'] = $gross; $r['net'] = max(0, $gross - $lunch - $tea);
    return $r;
}

function att_data(array $uids, string $from, string $to): array
{
    $c = att_cfg();
    $ev = att_events($uids, $from, $to);
    $seen = att_seen($uids, $from, $to);
    $rs = att_reasons($uids, $from, $to);
    $out = [];
    foreach ($uids as $uid) {
        for ($d = $from; $d <= $to; $d = add_days($d, 1)) {
            $out[$uid][$d] = att_apply_forgot(att_calc($ev[$uid][$d] ?? [], $seen[$uid][$d] ?? null, $d, $c), $rs[$uid][$d] ?? [], $d, $c);
        }
    }
    return $out;
}

/* ---------- recording ---------- */
function att_add(int $uid, string $kind, string $src = 'manual'): void
{
    db()->prepare('INSERT INTO att_events (user_id, day, kind, at, src) VALUES (?,?,?,?,?)')
        ->execute([$uid, today(), $kind, date('Y-m-d H:i:s'), $src]);
}

/** Called on every page view of a signed-in employee. First activity of a working day = start of day. */
function att_touch(array $u): void
{
    $uid = (int)$u['id'];
    $today = today();
    $pdo = db();
    if (is_working_day($today)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM att_events WHERE user_id = ? AND day = ?');
        $st->execute([$uid, $today]);
        if ((int)$st->fetchColumn() === 0) { att_add($uid, 'in', 'login'); }
    }
    $last = (int)($_SESSION['att_ping'] ?? 0);
    if (time() - $last >= 60) {
        $pdo->prepare('INSERT INTO att_seen (user_id, day, last_seen) VALUES (?,?,?) ON CONFLICT(user_id, day) DO UPDATE SET last_seen = excluded.last_seen')
            ->execute([$uid, $today, date('Y-m-d H:i:s')]);
        $_SESSION['att_ping'] = time();
    }
}

/** What this employee must explain before carrying on. */
function att_gate(array $u): array
{
    $uid = (int)$u['id'];
    $c = att_cfg();
    $today = today();
    $need = ['late' => false, 'missing' => []];
    $from = max($c['since'], add_days($today, -14), substr((string)$u['created_at'], 0, 10));
    $ev = att_events([$uid], $from, $today);
    $rs = att_reasons([$uid], $from, $today);
    $seen = att_seen([$uid], $from, $today);
    if (is_working_day($today) && !isset($rs[$uid][$today]['late'])) {
        $a = att_calc($ev[$uid][$today] ?? [], $seen[$uid][$today] ?? null, $today, $c);
        if ($a['in'] !== null && $a['is_late']) { $need['late'] = true; }
    }
    $yesterday = add_days($today, -1);
    if ($from <= $yesterday) {
        $leave = [];
        $st = db()->prepare("SELECT report_date FROM reports WHERE user_id = ? AND type = 'leave' AND report_date BETWEEN ? AND ?");
        $st->execute([$uid, $from, $yesterday]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $leave[$d] = true; }
        foreach (working_days($from, $yesterday) as $d) {
            if (empty($ev[$uid][$d]) && !isset($leave[$d]) && !isset($rs[$uid][$d]['missing'])) { $need['missing'][] = $d; }
        }
    }
    return $need;
}

function att_gate_open(array $u): bool
{
    $g = att_gate($u);
    return $g['late'] || $g['missing'];
}

/* ---------- exports ---------- */
function csv_string(array $headers, array $rows): string
{
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $headers, ',', '"', '');
    foreach ($rows as $r) { fputcsv($f, $r, ',', '"', ''); }
    rewind($f);
    $s = stream_get_contents($f);
    fclose($f);
    return $s;
}

function export_datasets(): array
{
    return [
        'attendance' => ['Attendance (one row per person per day)', 'Start, end, late minutes, lunch, tea, working hours, reason.'],
        'attendance_events' => ['Attendance punches (every click)', 'Each start, lunch, tea and end time as it happened.'],
        'reasons' => ['Late and missing reasons', 'Reason, note, claimed time and whether you accepted it.'],
        'sales_orders' => ['Sales orders', 'Every order imported from the sales sheets.'],
        'sales_calls' => ['Sales calls', 'Every call row imported, with remark and connected flag.'],
        'sales_minutes' => ['Call minutes', 'Daily call minutes typed by each person.'],
        'sales_all' => ['Sales, all platforms (online and offline)', 'Every day and platform: marketplaces, website, Inside Sales and offline channels, with sales, orders and ad spend.'],
        'tasks_monthly' => ['Monthly task summary per person', 'Days reported, leave, tasks, done, blocked and hours for each person and month.'],
        'reports' => ['Daily work reports and tasks', 'All tasks with hours, category and status.'],
        'ecom_daily' => ['E-commerce daily sales and ad spend', 'Blinkit, Myntra, HUGU Life, Tata 1mg, Amazon and Flipkart: sales, orders, units, ad spend, ROAS.'],
    ];
}

/** @return array{0:string,1:list<string>,2:list<list<mixed>>} */
function export_data(string $key, string $from, string $to): array
{
    $pdo = db();
    $q = function (string $sql, array $a) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); };
    $name = $key . '_' . $from . '_to_' . $to . '.csv';
    switch ($key) {
        case 'attendance':
            $people = $pdo->query("SELECT id, name, designation FROM users WHERE role = 'employee' ORDER BY name")->fetchAll();
            $ids = array_map(fn($p) => (int)$p['id'], $people);
            $to2 = min($to, today());
            $data = $to2 >= $from ? att_data($ids, $from, $to2) : [];
            $rs = att_reasons($ids, $from, $to2);
            $rows = [];
            foreach ($people as $p) {
                for ($d = $from; $d <= $to2; $d = add_days($d, 1)) {
                    $a = $data[(int)$p['id']][$d] ?? null;
                    if (!$a) { continue; }
                    $wd = is_working_day($d);
                    $r = $rs[(int)$p['id']][$d] ?? [];
                    $why = $r['late'] ?? ($r['missing'] ?? null);
                    if ($a['in'] === null && !$wd && !$why) { continue; }
                    $rows[] = [$d, $p['name'], $p['designation'], $wd ? 'Working day' : 'Off day',
                        $a['in'] ? date('H:i', strtotime($a['in'])) : '', $a['out'] ? date('H:i', strtotime($a['out'])) : '',
                        $a['out_auto'] ? 'last seen (End day not pressed)' : ($a['out'] ? 'End day pressed' : ''),
                        $a['in'] ? $a['late'] : '', $a['in'] ? ($a['is_late'] ? 'Yes' : 'No') : ($wd ? 'Not in' : ''),
                        $a['in'] ? $a['lunch'] : '', $a['in'] ? $a['tea'] : '', $a['net'] !== null ? round($a['net'] / 60, 2) : '',
                        $why['reason'] ?? '', $why['note'] ?? '', $why['review'] ?? ''];
                }
            }
            return [$name, ['Date', 'Employee', 'Designation', 'Day type', 'Start', 'End', 'End source', 'Late (min)', 'Late?', 'Lunch (min)', 'Tea (min)', 'Working hours', 'Reason', 'Note', 'Review'], $rows];
        case 'attendance_events':
            $rows = array_map(fn($r) => [$r['day'], $r['name'], $r['kind'], date('H:i:s', strtotime($r['at'])), $r['src']],
                $q('SELECT e.*, u.name FROM att_events e JOIN users u ON u.id = e.user_id WHERE e.day BETWEEN ? AND ? ORDER BY e.day, u.name, e.at', [$from, $to]));
            return [$name, ['Date', 'Employee', 'Event', 'Time', 'How recorded'], $rows];
        case 'reasons':
            $rows = array_map(fn($r) => [$r['day'], $r['name'], $r['kind'], $r['reason'], $r['note'], $r['claimed_in'], $r['claimed_out'], $r['review'], $r['created_at']],
                $q('SELECT r.*, u.name FROM att_reasons r JOIN users u ON u.id = r.user_id WHERE r.day BETWEEN ? AND ? ORDER BY r.day, u.name', [$from, $to]));
            return [$name, ['Date', 'Employee', 'Type', 'Reason', 'Note', 'Claimed start', 'Claimed end', 'Review', 'Submitted at'], $rows];
        case 'sales_orders':
            $rows = array_map(fn($r) => [$r['order_date'], $r['name'], $r['order_id'], $r['customer'], $r['phone'], $r['source'], $r['products'], $r['amount'], $r['code'], $r['payment'], $r['dispatch'], $r['rto']],
                $q('SELECT o.*, u.name FROM sales_orders o JOIN users u ON u.id = o.user_id WHERE o.order_date BETWEEN ? AND ? ORDER BY o.order_date, o.id', [$from, $to]));
            return [$name, ['Date', 'Sales person', 'Order id', 'Customer', 'Phone', 'Source', 'Products', 'Amount', 'Code', 'Payment', 'Dispatch', 'RTO reason'], $rows];
        case 'sales_calls':
            $rows = array_map(fn($r) => [$r['call_date'], $r['name'], $r['customer'], $r['phone'], $r['remark'], $r['connected'] ? 'Yes' : 'No', $r['tab'], $r['ref_code']],
                $q('SELECT c.*, u.name FROM sales_calls c JOIN users u ON u.id = c.user_id WHERE c.call_date BETWEEN ? AND ? ORDER BY c.call_date, c.id', [$from, $to]));
            return [$name, ['Date', 'Sales person', 'Customer', 'Phone', 'Remark', 'Connected', 'Sheet tab', 'Code used'], $rows];
        case 'sales_minutes':
            $rows = array_map(fn($r) => [$r['day'], $r['name'], $r['call_minutes']],
                $q('SELECT d.*, u.name FROM sales_daily d JOIN users u ON u.id = d.user_id WHERE d.day BETWEEN ? AND ? ORDER BY d.day, u.name', [$from, $to]));
            return [$name, ['Date', 'Sales person', 'Call minutes'], $rows];
        case 'ecom_daily':
            $rows = array_map(function ($r) {
                $nm = ECOM_PLATFORMS[$r['platform']][0] ?? $r['platform'];
                return [$r['day'], $nm, $r['sales'], $r['orders'], $r['units'], $r['spend'], $r['spend'] > 0 ? round($r['sales'] / $r['spend'], 2) : '', $r['who'], $r['src']];
            }, $q('SELECT d.*, u.name AS who FROM ecom_daily d LEFT JOIN users u ON u.id = d.user_id WHERE d.day BETWEEN ? AND ? ORDER BY d.day, d.platform', [$from, $to]));
            return [$name, ['Date', 'Platform', 'Sales', 'Orders', 'Units', 'Ad spend', 'ROAS', 'Updated by', 'Source'], $rows];
        case 'sales_all':
            $rows = [];
            foreach ($q('SELECT * FROM ecom_daily WHERE day BETWEEN ? AND ?', [$from, $to]) as $r) {
                $rows[] = [$r['day'], 'Online', 'Marketplace', ECOM_PLATFORMS[$r['platform']][0] ?? $r['platform'], $r['sales'], $r['orders'], $r['spend'], '', '', ''];
            }
            $webDays = [];
            foreach ($q('SELECT d.*, c.name, c.kind, c.web FROM channel_daily d JOIN sales_channels c ON c.id = d.channel_id WHERE d.day BETWEEN ? AND ?', [$from, $to]) as $r) {
                if ($r['web'] && $r['sales'] > 0) { $webDays[$r['day']] = true; }
                $rows[] = [$r['day'], $r['kind'] === 'online' ? 'Online' : 'Offline', $r['web'] ? 'Shopify' : ($r['kind'] === 'online' ? 'Other online' : 'Offline'), $r['name'], $r['sales'], $r['orders'], '',
                    $r['web'] ? $r['meta'] : '', $r['web'] ? $r['infl'] : '', $r['note']];
            }
            foreach ($q('SELECT order_date d, SUM(amount) a, COUNT(*) n FROM sales_orders WHERE order_date BETWEEN ? AND ? GROUP BY order_date', [$from, $to]) as $r) {
                if (isset($webDays[$r['d']])) { $rows[] = [$r['d'], 'Online', 'Shopify (part of Shopify total)', 'Inside Sales (call team)', $r['a'], $r['n'], '', '', '', 'Already inside the Shopify total, not added again']; }
                else { $rows[] = [$r['d'], 'Online', 'Shopify', 'Inside Sales (call team), used as Shopify until the day total is entered', $r['a'], $r['n'], '', '', '', '']; }
            }
            usort($rows, fn($a, $b) => [$a[0], $a[1], $a[3]] <=> [$b[0], $b[1], $b[3]]);
            return [$name, ['Date', 'Type', 'Group', 'Platform / channel', 'Sales', 'Orders', 'Ad spend', 'Shopify: from Meta ads', 'Shopify: from influencer codes', 'Note'], $rows];
        case 'tasks_monthly':
            $rows = [];
            $m = substr($from, 0, 7);
            while ($m <= substr($to, 0, 7)) {
                foreach (tasks_month($m)['per'] as $p) {
                    if (!$p['work'] && !$p['leave'] && !$p['tasks']) { continue; }
                    $rows[] = [$m, $p['name'], $p['designation'], $p['work'], $p['leave'], $p['tasks'], $p['done'], $p['prog'], $p['blocked'], round($p['hours'], 2), $p['work'] ? round($p['hours'] / $p['work'], 2) : ''];
                }
                $m = date('Y-m', strtotime($m . '-01 +1 month'));
            }
            return [$name, ['Month', 'Employee', 'Designation', 'Days reported', 'Leave days', 'Tasks', 'Done', 'In progress', 'Blocked', 'Hours', 'Avg hours per day'], $rows];
        case 'reports':
            $rows = array_map(fn($r) => [$r['report_date'], $r['name'], $r['designation'], $r['type'], $r['title'], $r['category'], $r['hours'], $r['status'], $r['remarks'], $r['note']],
                $q("SELECT r.report_date, r.type, r.note, u.name, u.designation, t.title, t.category, t.hours, t.status, t.remarks FROM reports r JOIN users u ON u.id = r.user_id LEFT JOIN tasks t ON t.report_id = r.id WHERE r.report_date BETWEEN ? AND ? ORDER BY r.report_date, u.name, t.id", [$from, $to]));
            return [$name, ['Date', 'Employee', 'Designation', 'Type', 'Task', 'Category', 'Hours', 'Status', 'Remarks', 'Day note'], $rows];
    }
    return [$name, [], []];
}


/** Banners asking an employee to hand in the daily report: yesterday's if it is missing, today's once the reminder time has passed. */
function report_reminders(array $u): array
{
    if (($u['role'] ?? '') !== 'employee') { return []; }
    $pdo = db();
    $c = att_cfg();
    $today = today();
    $first = max($c['since'], substr((string)$u['created_at'], 0, 10));
    $st = $pdo->prepare('SELECT report_date FROM reports WHERE user_id = ? AND report_date >= ?');
    $st->execute([(int)$u['id'], add_days($today, -7)]);
    $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    $prev = null;
    for ($d = add_days($today, -1); $d >= add_days($today, -6); $d = add_days($d, -1)) { if (is_working_day($d)) { $prev = $d; break; } }
    if ($prev && $prev >= $first && !isset($have[$prev])) {
        $out[] = ['You have not submitted your daily report for ' . fmt_date($prev) . '. Please fill it in.', url('report', ['date' => $prev])];
    }
    if (is_working_day($today) && $today >= $first && !isset($have[$today]) && date('H:i') >= $c['remind']) {
        $out[] = ['Reminder: today\'s daily report is not submitted yet.', url('report', ['date' => $today])];
    }
    return $out;
}
