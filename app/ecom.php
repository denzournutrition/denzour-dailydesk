<?php
defined('DD') or exit;

/* E-commerce specialist: daily sales and ad spend for Blinkit, Myntra, HUGU Life and Tata 1mg. */

const ECOM_DESIG = 'E-commerce Specialist';

/** key => [name, brand colour, text colour] */
const ECOM_PLATFORMS = [
    'blinkit'  => ['Blinkit',   '#F8CB46', '#1d1d1d'],
    'myntra'   => ['Myntra',    '#FF3F6C', '#ffffff'],
    'hugulife' => ['HUGU Life', '#6D4AC8', '#ffffff'],
    'tata1mg'  => ['Tata 1mg',  '#FF6F61', '#ffffff'],
];

function ecom_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ecom_uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        filename TEXT NOT NULL DEFAULT '',
        rows_in INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ecom_daily (
        platform TEXT NOT NULL,
        day TEXT NOT NULL,
        sales REAL NOT NULL DEFAULT 0,
        orders INTEGER NOT NULL DEFAULT 0,
        units INTEGER NOT NULL DEFAULT 0,
        spend REAL NOT NULL DEFAULT 0,
        user_id INTEGER NOT NULL DEFAULT 0,
        src TEXT NOT NULL DEFAULT 'sheet',
        updated_at TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (platform, day)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ecom_day ON ecom_daily(day)');
}

function is_ecom(?array $u = null): bool
{
    $u = $u ?? user();
    return $u && ($u['role'] ?? '') === 'employee' && (strcasecmp((string)$u['designation'], ECOM_DESIG) === 0 || !empty($u['ecom_access']));
}

function can_ecom_desk(?array $u = null): bool
{
    $u = $u ?? user();
    return $u && (is_ecom($u) || ($u['role'] ?? '') === 'admin');
}

/** Platform logo: the uploaded image if there is one, otherwise a coloured badge with the name. */
function ecom_logo_path(string $key): ?string
{
    foreach (['png', 'jpg', 'webp'] as $x) {
        $f = dirname(__DIR__) . '/data/logos/' . $key . '.' . $x;
        if (is_file($f)) { return $f; }
    }
    return null;
}

function ecom_logo(string $key, int $size = 40): string
{
    $p = ECOM_PLATFORMS[$key] ?? null;
    if (!$p) { return ''; }
    if (ecom_logo_path($key)) {
        return '<span class="plogo" style="--s:' . $size . 'px"><img src="' . h(url('ecomlogo', ['k' => $key, 'v' => (string)@filemtime((string)ecom_logo_path($key))])) . '" alt="' . h($p[0]) . '"></span>';
    }
    $ini = $key === 'tata1mg' ? '1mg' : ($key === 'hugulife' ? 'HG' : mb_substr($p[0], 0, 1));
    return '<span class="plogo plogo--b" style="--s:' . $size . 'px;background:' . $p[1] . ';color:' . $p[2] . '" role="img" aria-label="' . h($p[0]) . '">' . h($ini) . '</span>';
}

function ecom_platform_key(string $text): string
{
    $t = strtolower($text);
    if (preg_match('/blinkit|grofers|blink it/', $t)) { return 'blinkit'; }
    if (preg_match('/myntra/', $t)) { return 'myntra'; }
    if (preg_match('/hugu/', $t)) { return 'hugulife'; }
    if (preg_match('/1\s*mg|tata/', $t)) { return 'tata1mg'; }
    return '';
}

/* ---------- reading a sheet ---------- */
function ecom_norm(string $s): string { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }

const ECOM_COLS = [
    'date'     => ['date', 'day', 'orderdate', 'reportdate', 'salesdate', 'reportingdate', 'datee'],
    'platform' => ['platform', 'channel', 'marketplace', 'portal', 'store', 'partner'],
    'sales'    => ['sales', 'gmv', 'revenue', 'netsales', 'grosssales', 'totalsales', 'salesamount', 'netrevenue', 'totalgmv', 'salesvalue', 'totalrevenue', 'salesinr', 'gmvinr', 'netsalesinr'],
    'orders'   => ['orders', 'totalorders', 'ordercount', 'noorders', 'orderscount', 'ordersplaced'],
    'units'    => ['units', 'unitssold', 'qty', 'quantity', 'itemssold', 'unitsold'],
    'spend'    => ['spend', 'adspend', 'cost', 'amountspent', 'adcost', 'totalspend', 'adspendinr', 'spendinr', 'amountspentinr', 'adsspend', 'campaignspend', 'totalcost'],
];

function ecom_find_cols(array $header): array
{
    $norm = array_map(fn($c) => ecom_norm((string)$c), $header);
    $map = [];
    foreach (ECOM_COLS as $field => $names) {
        foreach ($names as $n) { $i = array_search($n, $norm, true); if ($i !== false) { $map[$field] = (int)$i; break; } }
    }
    $has = fn(string $re, ?string $not = null) => (function () use ($norm, $re, $not) {
        foreach ($norm as $i => $c) { if ($c !== '' && preg_match($re, $c) && !($not && preg_match($not, $c))) { return $i; } }
        return null;
    })();
    if (!isset($map['spend']) && ($i = $has('/spend|adcost|cost/')) !== null) { $map['spend'] = $i; }
    if (!isset($map['sales']) && ($i = $has('/gmv|sales|revenue/', '/spend|cost|roas|units|qty/')) !== null) { $map['sales'] = $i; }
    if (!isset($map['orders']) && ($i = $has('/orders/', '/value|amount|rate/')) !== null) { $map['orders'] = $i; }
    if (!isset($map['date']) && ($i = $has('/date/')) !== null) { $map['date'] = $i; }
    return $map;
}

/** One tab -> ['name','ok','cols','platform','rows'=>[ [p,day,sales,orders,units,spend], ... ]] */
function ecom_tab(array $tab, string $filename): array
{
    $res = ['name' => (string)$tab['name'], 'ok' => false, 'cols' => [], 'platform' => ecom_platform_key((string)$tab['name']) ?: ecom_platform_key($filename), 'rows' => [], 'note' => ''];
    $rows = $tab['rows'];
    $hi = null; $map = [];
    foreach (array_slice($rows, 0, 25, true) as $i => $r) {
        $m = ecom_find_cols($r);
        if (isset($m['date']) && (isset($m['sales']) || isset($m['spend']))) { $hi = $i; $map = $m; break; }
    }
    if ($hi === null) { $res['note'] = 'No date with a sales or spend column found.'; return $res; }
    $res['ok'] = true;
    $res['cols'] = array_values(array_filter(['sales', 'orders', 'units', 'spend'], fn($f) => isset($map[$f])));
    $agg = [];
    foreach (array_slice($rows, $hi + 1) as $r) {
        $day = s_date($r[$map['date']] ?? '');
        if (!$day) { continue; }
        $pk = isset($map['platform']) ? ecom_platform_key((string)($r[$map['platform']] ?? '')) : '';
        $k = $pk . '|' . $day;
        $a = &$agg[$k];
        $a = $a ?? ['p' => $pk, 'day' => $day, 'sales' => 0.0, 'orders' => 0, 'units' => 0, 'spend' => 0.0];
        foreach ($res['cols'] as $f) {
            $v = s_amount($r[$map[$f]] ?? 0);
            if ($f === 'orders' || $f === 'units') { $a[$f] += (int)round($v); } else { $a[$f] += $v; }
        }
        unset($a);
    }
    $res['rows'] = array_values($agg);
    if (!$res['rows']) { $res['ok'] = false; $res['note'] = 'No rows with a valid date.'; }
    return $res;
}

function ecom_parse_file(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $head = (string)@file_get_contents($path, false, null, 0, 4);
    if ($ext === 'xlsx' || $head === "PK\x03\x04") { $tabs = xlsx_read($path); }
    elseif ($ext === 'csv' || $ext === 'txt') { $tabs = csv_read($path, pathinfo($filename, PATHINFO_FILENAME)); }
    else { throw new RuntimeException('Please upload an Excel (.xlsx) or CSV (.csv) file.'); }
    return array_map(fn($t) => ecom_tab($t, $filename), $tabs);
}

/* ---------- saving ---------- */
/** Saves one platform-day. Only the fields in $vals are changed, so a spend sheet and a sales sheet can be uploaded separately. */
function ecom_save_day(string $platform, string $day, array $vals, int $uid, string $src): void
{
    $fields = array_values(array_intersect(array_keys($vals), ['sales', 'orders', 'units', 'spend']));
    if (!isset(ECOM_PLATFORMS[$platform]) || !valid_date($day) || !$fields) { return; }
    $cols = implode(',', $fields);
    $ph = implode(',', array_fill(0, count($fields), '?'));
    $set = implode(',', array_map(fn($f) => "$f = excluded.$f", $fields));
    $sql = "INSERT INTO ecom_daily (platform, day, $cols, user_id, src, updated_at) VALUES (?, ?, $ph, ?, ?, ?)
            ON CONFLICT(platform, day) DO UPDATE SET $set, user_id = excluded.user_id, src = excluded.src, updated_at = excluded.updated_at";
    $args = [$platform, $day];
    foreach ($fields as $f) { $args[] = $vals[$f]; }
    array_push($args, $uid, $src, date('Y-m-d H:i:s'));
    db()->prepare($sql)->execute($args);
}

function ecom_commit(int $uid, string $filename, array $tabs, array $chosen, array $platformFor): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO ecom_uploads (user_id, filename, rows_in, created_at) VALUES (?,?,0,?)')->execute([$uid, mb_substr($filename, 0, 200), date('Y-m-d H:i:s')]);
    $n = 0;
    foreach ($chosen as $i) {
        $t = $tabs[(int)$i] ?? null;
        if (!$t || empty($t['ok'])) { continue; }
        $fallback = $platformFor[(int)$i] ?? ($t['platform'] ?? '');
        foreach ($t['rows'] as $r) {
            $p = $r['p'] !== '' ? $r['p'] : $fallback;
            if (!isset(ECOM_PLATFORMS[$p])) { continue; }
            $vals = [];
            foreach ($t['cols'] as $f) { $vals[$f] = $r[$f]; }
            ecom_save_day($p, $r['day'], $vals, $uid, 'sheet');
            $n++;
        }
    }
    $pdo->exec('UPDATE ecom_uploads SET rows_in = ' . $n . ' WHERE id = ' . (int)$pdo->lastInsertId());
    $pdo->commit();
    return $n;
}

/* ---------- reading numbers ---------- */
function ecom_rows(string $from, string $to): array
{
    $st = db()->prepare('SELECT d.*, u.name AS who FROM ecom_daily d LEFT JOIN users u ON u.id = d.user_id WHERE d.day BETWEEN ? AND ? ORDER BY d.day');
    $st->execute([$from, $to]);
    $by = [];
    foreach ($st->fetchAll() as $r) { $by[$r['day']][$r['platform']] = $r; }
    return $by;
}

function ecom_month(string $month): array
{
    [$s, $e] = s_month_range($month);
    $by = ecom_rows($s, $e);
    $wd = array_filter(working_days($s, min($e, add_days(today(), -1))), fn($d) => true);
    $plat = [];
    foreach (ECOM_PLATFORMS as $k => $_) {
        $plat[$k] = ['sales' => 0.0, 'spend' => 0.0, 'orders' => 0, 'units' => 0, 'days' => 0, 'missing' => 0, 'last' => null];
    }
    $tot = ['sales' => 0.0, 'spend' => 0.0, 'orders' => 0, 'units' => 0];
    foreach ($by as $d => $ps) {
        foreach ($ps as $k => $r) {
            $plat[$k]['sales'] += $r['sales']; $plat[$k]['spend'] += $r['spend']; $plat[$k]['orders'] += $r['orders']; $plat[$k]['units'] += $r['units'];
            $plat[$k]['days']++; if ($plat[$k]['last'] === null || $d > $plat[$k]['last']) { $plat[$k]['last'] = $d; }
            $tot['sales'] += $r['sales']; $tot['spend'] += $r['spend']; $tot['orders'] += $r['orders']; $tot['units'] += $r['units'];
        }
    }
    foreach ($wd as $d) { foreach ($plat as $k => &$p) { if (!isset($by[$d][$k])) { $p['missing']++; } } unset($p); }
    return ['by' => $by, 'plat' => $plat, 'tot' => $tot, 'start' => $s, 'end' => $e];
}

function ecom_roas(float $sales, float $spend): string
{
    return $spend > 0 ? number_format($sales / $spend, 2) . 'x' : '–';
}

/** Daily sales (bars) and ad spend (line) for the month. */
function ecom_chart(string $month, array $by): string
{
    [$s, $e] = s_month_range($month);
    $days = []; for ($d = $s; $d <= $e; $d = add_days($d, 1)) { $days[] = $d; }
    $n = count($days);
    $sales = []; $spend = [];
    foreach ($days as $d) {
        $sales[$d] = array_sum(array_column($by[$d] ?? [], 'sales'));
        $spend[$d] = array_sum(array_column($by[$d] ?? [], 'spend'));
    }
    $max = max(max($sales), max($spend), 1);
    $W = 720; $H = 240; $pl = 52; $pr = 10; $pb = 28; $pt = 12; $iw = $W - $pl - $pr; $ih = $H - $pb - $pt;
    $bw = max(2, $iw / $n * 0.62);
    $x = fn($i) => $pl + ($i + .5) * $iw / $n;
    $y = fn($v) => $pt + $ih * (1 - $v / $max);
    $o = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Daily sales and ad spend" preserveAspectRatio="xMidYMid meet">';
    foreach ([0, .25, .5, .75, 1] as $g) {
        $yy = $pt + $ih * (1 - $g);
        $o .= '<line x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/><text x="' . ($pl - 8) . '" y="' . ($yy + 4) . '" text-anchor="end" class="axis">' . h(inr_short($max * $g)) . '</text>';
    }
    foreach ($days as $i => $d) {
        if ($sales[$d] > 0) { $o .= '<rect x="' . round($x($i) - $bw / 2, 1) . '" y="' . round($y($sales[$d]), 1) . '" width="' . round($bw, 1) . '" height="' . round($pt + $ih - $y($sales[$d]), 1) . '" rx="2" class="bar"><title>' . h(fmt_short($d) . ' · sales ' . inr($sales[$d])) . '</title></rect>'; }
    }
    $pts = [];
    foreach ($days as $i => $d) { if ($spend[$d] > 0) { $pts[] = round($x($i), 1) . ',' . round($y($spend[$d]), 1); } }
    if (count($pts) > 1) { $o .= '<path class="ln ln--spend" d="M' . implode(' L', $pts) . '"/>'; }
    foreach ($days as $i => $d) { if ($spend[$d] > 0) { $o .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($spend[$d]), 1) . '" r="2.6" class="dot dot--spend"><title>' . h(fmt_short($d) . ' · spend ' . inr($spend[$d])) . '</title></circle>'; } }
    $step = max(1, (int)ceil($n / 8));
    foreach ($days as $i => $d) {
        if ($i % $step === 0) { $o .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle" class="axis">' . h(fmt_short($d)) . '</text>'; }
    }
    return $o . '</svg>';
}
