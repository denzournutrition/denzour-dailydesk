<?php
defined('DD') or exit;

/* =====================================================================
   Inside Sales: sheet reader, import, targets and metrics
   ===================================================================== */

const SALES_DESIG = 'Inside Sales';

function sales_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        filename TEXT NOT NULL,
        uploaded_at TEXT NOT NULL,
        from_date TEXT NOT NULL DEFAULT '',
        orders_new INTEGER NOT NULL DEFAULT 0,
        orders_dup INTEGER NOT NULL DEFAULT 0,
        calls_new INTEGER NOT NULL DEFAULT 0,
        calls_dup INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        upload_id INTEGER NOT NULL REFERENCES sales_uploads(id) ON DELETE CASCADE,
        order_key TEXT NOT NULL UNIQUE,
        order_id TEXT NOT NULL DEFAULT '',
        order_date TEXT NOT NULL,
        phone TEXT NOT NULL DEFAULT '',
        customer TEXT NOT NULL DEFAULT '',
        source TEXT NOT NULL DEFAULT '',
        products TEXT NOT NULL DEFAULT '',
        amount REAL NOT NULL DEFAULT 0,
        code TEXT NOT NULL DEFAULT '',
        payment TEXT NOT NULL DEFAULT '',
        dispatch TEXT NOT NULL DEFAULT '',
        rto TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_calls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        upload_id INTEGER NOT NULL REFERENCES sales_uploads(id) ON DELETE CASCADE,
        call_key TEXT NOT NULL,
        call_date TEXT NOT NULL,
        phone TEXT NOT NULL DEFAULT '',
        customer TEXT NOT NULL DEFAULT '',
        remark TEXT NOT NULL DEFAULT '',
        connected INTEGER NOT NULL DEFAULT 1,
        tab TEXT NOT NULL DEFAULT '',
        ref_code TEXT NOT NULL DEFAULT '',
        UNIQUE (user_id, call_key)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_cb_done (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        phone TEXT NOT NULL,
        done_day TEXT NOT NULL,
        PRIMARY KEY (user_id, phone)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_daily (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        day TEXT NOT NULL,
        call_minutes REAL NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, day)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_targets (
        month TEXT NOT NULL,
        user_id INTEGER NOT NULL DEFAULT 0,
        sales REAL NOT NULL DEFAULT 0,
        orders INTEGER NOT NULL DEFAULT 0,
        calls_day INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (month, user_id)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_so_date ON sales_orders(order_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_so_phone ON sales_orders(phone)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sc_date ON sales_calls(call_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sc_phone ON sales_calls(phone)');
}

/* ---------- access ---------- */
function is_sales(?array $u = null): bool
{
    $u = $u ?? user();
    return $u && ($u['role'] ?? '') === 'employee' && (strcasecmp((string)$u['designation'], SALES_DESIG) === 0 || !empty($u['sales_access']));
}

function can_sales_desk(?array $u = null): bool
{
    $u = $u ?? user();
    return $u && in_array($u['role'], ['employee', 'admin'], true) && (is_sales($u) || $u['role'] === 'admin');
}

/* ---------- formatting ---------- */
function inr(float $n): string
{
    $neg = $n < 0;
    $s = (string)(int)round(abs($n));
    if (strlen($s) > 3) {
        $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3);
    }
    return ($neg ? '-' : '') . '₹' . $s;
}

function inr_short(float $n): string
{
    $a = abs($n);
    if ($a >= 10000000) { return '₹' . rtrim(rtrim(number_format($n / 10000000, 2), '0'), '.') . ' Cr'; }
    if ($a >= 100000)   { return '₹' . rtrim(rtrim(number_format($n / 100000, 2), '0'), '.') . ' L'; }
    return inr($n);
}

function pct(float $a, float $b): int
{
    return $b > 0 ? (int)round($a / $b * 100) : 0;
}

/* ---------- cell parsing ---------- */
function s_hdr(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

function s_clean($v): string
{
    if (is_float($v) || is_int($v)) {
        $v = (is_float($v) && floor($v) == $v && abs($v) < 1e15) ? sprintf('%.0f', $v) : (string)$v;
    }
    $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
    return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $v));
}

function s_date($v): ?string
{
    if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', trim($v)))) {
        $n = (float)$v;
        if ($n > 30000 && $n < 80000) {
            return gmdate('Y-m-d', (int)(($n - 25569) * 86400));
        }
        return null;
    }
    $v = trim((string)$v);
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})(?:\D.*)?$/', $v, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y < 100) { $y += 2000; }
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\D.*)?$/', $v, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return null;
    }
    if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

function s_phone($v): string
{
    $d = preg_replace('/\D/', '', s_clean($v));
    if (strlen($d) > 10) { $d = substr($d, -10); }
    return strlen($d) >= 6 ? $d : '';
}

function s_amount($v): float
{
    if (is_int($v) || is_float($v)) { return (float)$v; }
    $x = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($x) ? (float)$x : 0.0;
}

function s_connected(string $remark): int
{
    if (trim($remark) === '') { return 0; }
    return preg_match('/not connected|not respond|not reachable|switched off|ringing|not pick|no answer|busy|disconnect|invalid|wrong number|out of coverage|not answering|unreachable|not receiv|did not pick|didn.?t pick/i', $remark) ? 0 : 1;
}

/* ---------- file readers ---------- */
/** Returns [ ['name' => string, 'rows' => list<list<mixed>>], ... ] */
function xlsx_read(string $path): array
{
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        throw new RuntimeException('This does not look like a valid Excel (.xlsx) file.');
    }
    $wb = $z->getFromName('xl/workbook.xml');
    $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        throw new RuntimeException('This Excel file could not be read. Save it again as .xlsx or .csv and retry.');
    }
    $map = [];
    foreach (simplexml_load_string($rels)->Relationship as $r) {
        $t = ltrim((string)$r['Target'], '/');
        $map[(string)$r['Id']] = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
    }
    $ss = [];
    $sx = $z->getFromName('xl/sharedStrings.xml');
    if ($sx !== false) {
        foreach (simplexml_load_string($sx)->si as $si) {
            if (isset($si->t)) { $ss[] = (string)$si->t; continue; }
            $s = '';
            foreach ($si->r as $run) { $s .= (string)$run->t; }
            $ss[] = $s;
        }
    }
    $out = [];
    $wx = simplexml_load_string($wb);
    foreach ($wx->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        $file = $map[$rid] ?? '';
        $xml = $file !== '' ? $z->getFromName($file) : false;
        if ($xml === false || strlen($xml) > 40000000) {
            continue;
        }
        $sheet = simplexml_load_string($xml);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = 0;
                foreach (str_split(preg_replace('/\d+/', '', (string)$c['r'])) as $ch) { $col = $col * 26 + (ord($ch) - 64); }
                $col--;
                if ($col < 0 || $col > 800) { continue; }
                $t = (string)$c['t'];
                $val = '';
                if ($t === 's') { $val = $ss[(int)$c->v] ?? ''; }
                elseif ($t === 'inlineStr') { $val = (string)$c->is->t; }
                elseif ($t === 'str') { $val = (string)$c->v; }
                elseif ($t === 'b' || $t === 'e') { $val = ''; }
                else {
                    $raw = (string)$c->v;
                    $val = ($raw !== '' && is_numeric($raw)) ? (float)$raw : $raw;
                }
                $cells[$col] = $val;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $dense = [];
                for ($i = 0; $i <= $max; $i++) { $dense[] = $cells[$i] ?? ''; }
                $rows[] = $dense;
            }
            if (count($rows) > 60000) { break; }
        }
        $out[] = ['name' => (string)$sh['name'], 'rows' => $rows];
    }
    $z->close();
    return $out;
}

function csv_read(string $path, string $name): array
{
    $fh = fopen($path, 'r');
    if (!$fh) { throw new RuntimeException('The file could not be opened.'); }
    $rows = [];
    $first = true;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($first && isset($r[0])) { $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]); }
        $first = false;
        if (array_filter($r, fn($c) => trim((string)$c) !== '')) { $rows[] = $r; }
        if (count($rows) > 60000) { break; }
    }
    fclose($fh);
    return [['name' => $name, 'rows' => $rows]];
}

/* ---------- classify and normalise a tab ---------- */
function sales_tab(array $tab): array
{
    $rows = $tab['rows'];
    $res = ['name' => $tab['name'], 'type' => '', 'orders' => [], 'calls' => [], 'skipped' => 0, 'notes' => []];
    $hi = null; $map = [];
    foreach (array_slice($rows, 0, 8, true) as $i => $r) {
        $m = [];
        foreach ($r as $ci => $cell) {
            $k = s_hdr((string)$cell);
            if ($k !== '' && !isset($m[$k])) { $m[$k] = $ci; }
        }
        if (isset($m['shopifyorderid'], $m['finalamount'])) { $hi = $i; $map = $m; $res['type'] = 'orders'; break; }
        if (isset($m['talkremarks'], $m['contactno'])) { $hi = $i; $map = $m; $res['type'] = 'calls'; break; }
    }
    if ($hi === null) {
        return $res;
    }
    $col = function (array $names) use ($map) {
        foreach ($names as $n) { if (isset($map[$n])) { return $map[$n]; } }
        foreach ($map as $k => $ci) { foreach ($names as $n) { if (strpos($k, $n) === 0) { return $ci; } } }
        return null;
    };
    $c = [
        'date' => $col(['datetime', 'date']), 'phone' => $col(['contactno', 'phone', 'mobile']),
        'name' => $col(['nameofcustomer', 'customer', 'name']), 'source' => $col(['source']),
        'oid' => $col(['shopifyorderid']), 'prod' => $col(['productname', 'product']),
        'amt' => $col(['finalamount', 'amount']), 'code' => $col(['specialdiscount', 'refrenceforpurchase', 'referenceforpurchase', 'code']),
        'disp' => $col(['orderdispatch', 'dispatch']), 'pay' => $col(['paymentmode', 'payment']),
        'rto' => $col(['rtoreason', 'rto']), 'rem' => $col(['talkremarks']),
    ];
    $get = fn(array $r, ?int $i) => $i === null ? '' : ($r[$i] ?? '');
    $noDate = 0; $badRows = 0;
    foreach (array_slice($rows, $hi + 1) as $r) {
        $dateRaw = $get($r, $c['date']);
        $has = false;
        foreach ($r as $ci => $cell) { if ($ci !== 0 && trim((string)$cell) !== '') { $has = true; break; } }
        if (!$has) { continue; }
        $date = s_date($dateRaw);
        $phone = s_phone($get($r, $c['phone']));
        if ($res['type'] === 'orders') {
            $oid = s_clean($get($r, $c['oid']));
            $amt = s_amount($get($r, $c['amt']));
            if ($oid === '' && $amt == 0.0) { $badRows++; continue; }
            if ($date === null) { $noDate++; continue; }
            $key = preg_match('/^\d+$/', $oid) ? 'S' . $oid : 'O' . $date . '|' . $phone . '|' . round($amt) . '|' . substr(sha1(s_clean($get($r, $c['prod']))), 0, 8);
            $src = s_clean($get($r, $c['source']));
            if (preg_match('/^affilate$/i', $src)) { $src = 'Affiliate'; }
            $pay = strtoupper(s_clean($get($r, $c['pay'])));
            $pay = $pay === 'COD' ? 'COD' : ($pay === '' ? '' : ucfirst(strtolower($pay)));
            $prod = implode(' / ', array_filter(array_map('trim', explode("\n", s_clean($get($r, $c['prod']))))));
            $res['orders'][] = [
                'key' => $key, 'oid' => $oid, 'date' => $date, 'phone' => $phone,
                'customer' => s_clean($get($r, $c['name'])), 'source' => $src, 'products' => $prod, 'amount' => $amt,
                'code' => strtoupper(s_clean($get($r, $c['code']))), 'payment' => $pay,
                'dispatch' => s_clean($get($r, $c['disp'])), 'rto' => s_clean($get($r, $c['rto'])),
            ];
        } else {
            if ($phone === '') { $badRows++; continue; }
            if ($date === null) { $noDate++; continue; }
            $rem = s_clean($get($r, $c['rem']));
            $res['calls'][] = [
                'key' => sha1($date . '|' . $phone . '|' . strtolower($rem)), 'date' => $date, 'phone' => $phone,
                'customer' => s_clean($get($r, $c['name'])), 'remark' => $rem, 'connected' => s_connected($rem),
                'ref' => ($c['code'] !== null && $res['type'] === 'calls') ? strtoupper(s_clean($get($r, $c['code']))) : '',
            ];
        }
    }
    $res['skipped'] = $noDate + $badRows;
    if ($noDate) { $res['notes'][] = $noDate . ' row(s) had a missing or invalid date and were skipped.'; }
    if ($badRows) { $res['notes'][] = $badRows . ' row(s) had no ' . ($res['type'] === 'orders' ? 'order id or amount' : 'phone number') . ' and were skipped.'; }
    return $res;
}

function sales_parse_file(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $head = (string)@file_get_contents($path, false, null, 0, 4);
    if ($ext === 'xlsx' || $head === "PK\x03\x04") {
        $tabs = xlsx_read($path);
    } elseif ($ext === 'csv' || $ext === 'txt') {
        $tabs = csv_read($path, pathinfo($filename, PATHINFO_FILENAME));
    } else {
        throw new RuntimeException('Please upload an Excel (.xlsx) or CSV (.csv) file.');
    }
    return array_map('sales_tab', $tabs);
}

/* ---------- staging between preview and confirm ---------- */
function sales_stage_dir(): string
{
    $d = dirname(__DIR__) . '/data/uploads';
    if (!is_dir($d)) { @mkdir($d, 0750, true); }
    foreach (glob($d . '/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - 7200) { @unlink($f); }
    }
    return $d;
}

/** Mark rows as new or already-imported for this user and apply the "from" date. */
function sales_annotate(array $tabs, int $uid, string $from): array
{
    $pdo = db();
    $haveOrders = [];
    foreach ($pdo->query('SELECT order_key FROM sales_orders') as $r) { $haveOrders[$r['order_key']] = true; }
    $st = $pdo->prepare('SELECT call_key FROM sales_calls WHERE user_id = ? AND call_date >= ?');
    $st->execute([$uid, $from]);
    $haveCalls = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($tabs as &$t) {
        $t['found'] = count($t['orders']) + count($t['calls']);
        $t['old'] = 0; $t['new'] = 0; $t['dup'] = 0; $t['amount'] = 0.0; $t['min'] = ''; $t['max'] = '';
        $seen = [];
        foreach (['orders', 'calls'] as $kind) {
            $keep = [];
            foreach ($t[$kind] as $row) {
                if ($row['date'] < $from) { $t['old']++; continue; }
                $k = $row['key'];
                if (isset($seen[$kind . $k])) { $t['dup']++; continue; }
                $seen[$kind . $k] = true;
                $isDup = $kind === 'orders' ? isset($haveOrders[$k]) : isset($haveCalls[$k]);
                $row['dup'] = $isDup ? 1 : 0;
                if ($isDup) { $t['dup']++; } else {
                    $t['new']++;
                    if ($kind === 'orders') { $t['amount'] += $row['amount']; }
                }
                $t['min'] = $t['min'] === '' ? $row['date'] : min($t['min'], $row['date']);
                $t['max'] = max($t['max'], $row['date']);
                $keep[] = $row;
            }
            $t[$kind] = $keep;
        }
    }
    unset($t);
    return $tabs;
}

function sales_commit(int $uid, string $filename, string $from, array $tabs, array $chosen): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $on = $od = $cn = $cd = 0;
    $pdo->prepare('INSERT INTO sales_uploads (user_id, filename, uploaded_at, from_date) VALUES (?,?,?,?)')
        ->execute([$uid, mb_substr($filename, 0, 120), date('Y-m-d H:i:s'), $from]);
    $up = (int)$pdo->lastInsertId();
    $io = $pdo->prepare('INSERT OR IGNORE INTO sales_orders (user_id, upload_id, order_key, order_id, order_date, phone, customer, source, products, amount, code, payment, dispatch, rto) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ic = $pdo->prepare('INSERT OR IGNORE INTO sales_calls (user_id, upload_id, call_key, call_date, phone, customer, remark, connected, tab, ref_code) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($tabs as $i => $t) {
        if (!in_array((string)$i, $chosen, true)) { continue; }
        foreach ($t['orders'] as $o) {
            $io->execute([$uid, $up, $o['key'], $o['oid'], $o['date'], $o['phone'], $o['customer'], $o['source'], $o['products'], $o['amount'], $o['code'], $o['payment'], $o['dispatch'], $o['rto']]);
            $io->rowCount() ? $on++ : $od++;
        }
        foreach ($t['calls'] as $c) {
            $ic->execute([$uid, $up, $c['key'], $c['date'], $c['phone'], $c['customer'], $c['remark'], $c['connected'], mb_substr($t['name'], 0, 60), $c['ref']]);
            $ic->rowCount() ? $cn++ : $cd++;
        }
    }
    $pdo->prepare('UPDATE sales_uploads SET orders_new=?, orders_dup=?, calls_new=?, calls_dup=? WHERE id=?')->execute([$on, $od, $cn, $cd, $up]);
    $pdo->commit();
    return ['orders' => $on, 'orders_dup' => $od, 'calls' => $cn, 'calls_dup' => $cd];
}

/* ---------- targets and metrics ---------- */
function s_month(?string $m): string
{
    return ($m !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) ? $m : substr(today(), 0, 7);
}

function s_month_range(string $m): array
{
    return [$m . '-01', date('Y-m-t', strtotime($m . '-01'))];
}

function sales_members(): array
{
    return db()->query("SELECT id, name, designation FROM users WHERE active = 1 AND role IN ('employee','admin') AND (designation = '" . SALES_DESIG . "' OR sales_access = 1) AND role = 'employee' ORDER BY name")->fetchAll();
}

function sales_targets(string $month): array
{
    $st = db()->prepare('SELECT * FROM sales_targets WHERE month = ?');
    $st->execute([$month]);
    $out = ['team' => ['sales' => 0.0, 'orders' => 0, 'calls_day' => 0], 'users' => []];
    foreach ($st->fetchAll() as $r) {
        if ((int)$r['user_id'] === 0) { $out['team'] = ['sales' => (float)$r['sales'], 'orders' => (int)$r['orders'], 'calls_day' => (int)$r['calls_day']]; }
        else { $out['users'][(int)$r['user_id']] = (float)$r['sales']; }
    }
    return $out;
}

/** Month totals for the team and for each person. */
function sales_stats(string $month): array
{
    $pdo = db();
    [$s, $e] = s_month_range($month);
    $q = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); };
    $per = [];
    $row = function (int $id) use (&$per) {
        return $per[$id] ??= ['id' => $id, 'name' => '', 'sales' => 0.0, 'orders' => 0, 'calls' => 0, 'connected' => 0, 'minutes' => 0.0, 'fromcalls' => 0, 'cod' => 0, 'rto' => 0, 'last' => null];
    };
    foreach ($q('SELECT user_id, COUNT(*) n, SUM(amount) a, SUM(CASE WHEN UPPER(payment)=\'COD\' THEN 1 ELSE 0 END) cod, SUM(CASE WHEN rto<>\'\' THEN 1 ELSE 0 END) rto FROM sales_orders WHERE order_date BETWEEN ? AND ? GROUP BY user_id', [$s, $e]) as $r) {
        $x = &$per[(int)$r['user_id']]; $x = $row((int)$r['user_id']);
        $x['orders'] = (int)$r['n']; $x['sales'] = (float)$r['a']; $x['cod'] = (int)$r['cod']; $x['rto'] = (int)$r['rto']; unset($x);
    }
    foreach ($q('SELECT user_id, COUNT(*) n, SUM(connected) c FROM sales_calls WHERE call_date BETWEEN ? AND ? GROUP BY user_id', [$s, $e]) as $r) {
        $x = &$per[(int)$r['user_id']]; $x = $row((int)$r['user_id']);
        $x['calls'] = (int)$r['n']; $x['connected'] = (int)$r['c']; unset($x);
    }
    foreach ($q('SELECT user_id, SUM(call_minutes) m FROM sales_daily WHERE day BETWEEN ? AND ? GROUP BY user_id', [$s, $e]) as $r) {
        $x = &$per[(int)$r['user_id']]; $x = $row((int)$r['user_id']);
        $x['minutes'] = (float)$r['m']; unset($x);
    }
    foreach ($q('SELECT o.user_id, COUNT(*) n FROM sales_orders o WHERE o.order_date BETWEEN ? AND ? AND o.phone<>\'\' AND EXISTS (SELECT 1 FROM sales_calls c WHERE c.phone = o.phone AND c.call_date <= o.order_date) GROUP BY o.user_id', [$s, $e]) as $r) {
        $x = &$per[(int)$r['user_id']]; $x = $row((int)$r['user_id']);
        $x['fromcalls'] = (int)$r['n']; unset($x);
    }
    foreach ($q('SELECT user_id, MAX(uploaded_at) t FROM sales_uploads GROUP BY user_id') as $r) {
        if (isset($per[(int)$r['user_id']])) { $per[(int)$r['user_id']]['last'] = $r['t']; }
    }
    foreach (sales_members() as $m) {
        $x = &$per[(int)$m['id']]; $x = $row((int)$m['id']); unset($x);
        $per[(int)$m['id']]['last'] = $per[(int)$m['id']]['last'] ?? ($q('SELECT MAX(uploaded_at) t FROM sales_uploads WHERE user_id = ?', [(int)$m['id']])[0]['t'] ?? null);
    }
    if ($per) {
        foreach ($q('SELECT id, name FROM users WHERE id IN (' . implode(',', array_map('intval', array_keys($per))) . ')') as $r) {
            $per[(int)$r['id']]['name'] = $r['name'];
        }
    }
    $team = ['sales' => 0.0, 'orders' => 0, 'calls' => 0, 'connected' => 0, 'minutes' => 0.0, 'fromcalls' => 0, 'cod' => 0, 'rto' => 0];
    foreach ($per as $p) { foreach ($team as $k => $_) { $team[$k] += $p[$k]; } }
    $daily = [];
    foreach ($q('SELECT order_date d, COUNT(*) n, SUM(amount) a FROM sales_orders WHERE order_date BETWEEN ? AND ? GROUP BY order_date', [$s, $e]) as $r) {
        $daily[$r['d']] = ['orders' => (int)$r['n'], 'sales' => (float)$r['a']];
    }
    $by = function (string $col) use ($q, $s, $e) {
        $out = [];
        foreach ($q("SELECT $col k, COUNT(*) n, SUM(amount) a FROM sales_orders WHERE order_date BETWEEN ? AND ? GROUP BY $col ORDER BY a DESC", [$s, $e]) as $r) {
            $out[$r['k'] === '' ? 'Not set' : $r['k']] = ['orders' => (int)$r['n'], 'sales' => (float)$r['a']];
        }
        return $out;
    };
    $src = $by('source');
    $code = $by('code');
    $pay = $by('payment');
    uasort($per, fn($a, $b) => $b['sales'] <=> $a['sales']);
    return ['team' => $team, 'per' => $per, 'daily' => $daily, 'source' => $src, 'code' => $code, 'pay' => $pay, 'start' => $s, 'end' => $e];
}

/** Pace figures against a monthly sales target. */
function sales_pace(string $month, float $target, float $achieved): array
{
    [$s, $e] = s_month_range($month);
    $today = today();
    $totalWd = max(1, count(working_days($s, $e)));
    if ($today < $s) { $elapsed = 0; $left = $totalWd; }
    elseif ($today > $e) { $elapsed = $totalWd; $left = 0; }
    else { $elapsed = count(working_days($s, $today)); $left = count(working_days($today, $e)); }
    $remaining = max(0.0, $target - $achieved);
    return [
        'total_days' => $totalWd, 'elapsed' => $elapsed, 'left' => $left,
        'expected_pct' => (int)round($elapsed / $totalWd * 100),
        'remaining' => $remaining,
        'per_day' => $left > 0 ? $remaining / $left : $remaining,
        'projected' => $elapsed > 0 ? $achieved / $elapsed * $totalWd : 0.0,
        'live' => $today >= $s && $today <= $e,
    ];
}

/** Cumulative sales line vs straight target line. */
function sales_chart(string $month, array $daily, float $target): string
{
    [$s, $e] = s_month_range($month);
    $last = min($e, max($s, today()));
    $days = []; for ($d = $s; $d <= $e; $d = add_days($d, 1)) { $days[] = $d; }
    $n = count($days);
    $cum = []; $run = 0.0;
    foreach ($days as $d) { $run += $daily[$d]['sales'] ?? 0; $cum[$d] = $run; }
    $max = max($target, $run, 1);
    $W = 720; $H = 240; $pl = 52; $pr = 10; $pb = 28; $pt = 12; $iw = $W - $pl - $pr; $ih = $H - $pb - $pt;
    $x = fn($i) => $pl + ($n > 1 ? $i * $iw / ($n - 1) : $iw / 2);
    $y = fn($v) => $pt + $ih * (1 - $v / $max);
    $o = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Cumulative sales against target" preserveAspectRatio="xMidYMid meet"><defs><linearGradient id="gs" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2563eb" stop-opacity=".22"/><stop offset="1" stop-color="#2563eb" stop-opacity="0"/></linearGradient></defs>';
    foreach ([0, .25, .5, .75, 1] as $g) {
        $yy = $pt + $ih * (1 - $g);
        $o .= '<line x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/><text x="' . ($pl - 8) . '" y="' . ($yy + 4) . '" text-anchor="end" class="axis">' . h(inr_short($max * $g)) . '</text>';
    }
    if ($target > 0) {
        $o .= '<line class="tline" x1="' . round($x(0), 1) . '" y1="' . round($y(0), 1) . '" x2="' . round($x($n - 1), 1) . '" y2="' . round($y($target), 1) . '"/>';
    }
    $pts = [];
    foreach ($days as $i => $d) { if ($d <= $last) { $pts[] = round($x($i), 1) . ',' . round($y($cum[$d]), 1); } }
    if ($pts) {
        $li = count($pts) - 1;
        $o .= '<path class="area" d="M' . implode(' L', $pts) . ' L' . explode(',', $pts[$li])[0] . ',' . ($pt + $ih) . ' L' . explode(',', $pts[0])[0] . ',' . ($pt + $ih) . ' Z"/><path class="ln" d="M' . implode(' L', $pts) . '"/>';
    }
    $step = max(1, (int)ceil($n / 8));
    foreach ($days as $i => $d) {
        if ($i % $step === 0) { $o .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle" class="axis">' . h(fmt_short($d)) . '</text>'; }
    }
    return $o . '</svg>';
}

function s_hbars(array $rows, string $field = 'sales'): string
{
    if (!$rows) { return '<p class="muted tiny">No data for this month.</p>'; }
    $rows = array_slice($rows, 0, 8, true);
    $max = max(array_column($rows, $field)) ?: 1;
    $o = '<div class="hbars">';
    foreach ($rows as $k => $v) {
        $o .= '<div class="hbar hbar--w"><span class="hbar__l">' . h($k) . '</span><span class="hbar__t"><i style="width:' . round($v[$field] / $max * 100) . '%"></i></span><span class="hbar__v">' . ($field === 'sales' ? h(inr_short($v['sales'])) : (int)$v[$field]) . '</span></div>';
    }
    return $o . '</div>';
}

/** Month picker (last 12 months). Echoes a GET form that submits on change. */
function s_month_filter(string $page, string $month, string $extra = ''): void
{
    $opts = [];
    $cur = substr(today(), 0, 7);
    for ($i = 0; $i < 12; $i++) { $opts[] = date('Y-m', strtotime($cur . '-01 -' . $i . ' month')); }
    if (!in_array($month, $opts, true)) { array_unshift($opts, $month); }
    echo '<form method="get" class="filters"><input type="hidden" name="p" value="' . h($page) . '">' . $extra
        . '<label class="field"><span>Month</span><select name="month" data-autosubmit>';
    foreach ($opts as $m) {
        echo '<option value="' . h($m) . '"' . ($m === $month ? ' selected' : '') . '>' . h(date('F Y', strtotime($m . '-01'))) . '</option>';
    }
    echo '</select></label></form>';
}


/** Customers who did not pick up on an earlier day and still need a call today (not connected since, no order, not marked done). */
function sales_callbacks(int $uid): array
{
    $today = today();
    $st = db()->prepare("SELECT c.phone, MAX(c.call_date) AS last_day, COUNT(*) AS attempts, MAX(c.customer) AS customer,
            (SELECT x.remark FROM sales_calls x WHERE x.user_id = c.user_id AND x.phone = c.phone AND x.connected = 0 ORDER BY x.call_date DESC, x.id DESC LIMIT 1) AS remark
        FROM sales_calls c
        WHERE c.user_id = ? AND c.connected = 0 AND c.phone <> '' AND c.call_date >= ? AND c.call_date < ?
          AND NOT EXISTS (SELECT 1 FROM sales_calls y WHERE y.user_id = c.user_id AND y.phone = c.phone AND y.connected = 1 AND y.call_date >= c.call_date)
          AND NOT EXISTS (SELECT 1 FROM sales_orders o WHERE o.phone = c.phone AND o.order_date >= c.call_date)
          AND NOT EXISTS (SELECT 1 FROM sales_cb_done d WHERE d.user_id = c.user_id AND d.phone = c.phone AND d.done_day >= c.call_date)
        GROUP BY c.phone ORDER BY last_day DESC, attempts DESC LIMIT 60");
    $st->execute([$uid, add_days($today, -7), $today]);
    return $st->fetchAll();
}
