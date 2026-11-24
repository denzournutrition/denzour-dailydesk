<?php
defined('DD') or exit;

/* E-commerce specialist: daily sales and ad spend for Blinkit, Myntra, Hyugalife and Tata 1mg. */

const ECOM_DESIG = 'E-commerce Specialist';

/** key => [name, brand colour, text colour] */
const ECOM_PLATFORMS = [
    'blinkit'  => ['Blinkit',   '#F8CB46', '#1d1d1d'],
    'myntra'   => ['Myntra',    '#FF3F6C', '#ffffff'],
    'hugulife' => ['Hyugalife', '#6D4AC8', '#ffffff'],
    'tata1mg'  => ['Tata 1mg',  '#FF6F61', '#ffffff'],
    'amazon'   => ['Amazon',    '#FF9900', '#111111'],
    'flipkart' => ['Flipkart',  '#2874F0', '#ffffff'],
];

/** The platform-wise sheet (Ritik's) carries these four. Amazon and Flipkart sales come from the main sale sheet. */
const ECOM_SHEET = ['blinkit', 'myntra', 'hugulife', 'tata1mg'];
function ecom_sheet_platforms(): array { return array_intersect_key(ECOM_PLATFORMS, array_flip(ECOM_SHEET)); }

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
    $ini = $key === 'tata1mg' ? '1mg' : ($key === 'hugulife' ? 'HL' : mb_substr($p[0], 0, 1));
    return '<span class="plogo plogo--b" style="--s:' . $size . 'px;background:' . $p[1] . ';color:' . $p[2] . '" role="img" aria-label="' . h($p[0]) . '">' . h($ini) . '</span>';
}

function ecom_platform_key(string $text): string
{
    $t = strtolower($text);
    if (preg_match('/blinkit|grofers|blink it/', $t)) { return 'blinkit'; }
    if (preg_match('/myntra/', $t)) { return 'myntra'; }
    if (preg_match('/hugu|hyugu|hyuga|hugh|hyug|huyg/', $t)) { return 'hugulife'; }
    if (preg_match('/1\s*mg|tata/', $t)) { return 'tata1mg'; }
    if (preg_match('/amazon|amzn/', $t)) { return 'amazon'; }
    if (preg_match('/flipkart|flipcart|fk\b/', $t)) { return 'flipkart'; }
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


/**
 * "Management sheet" layout: platforms down column A, dates across the top, each date split into Sale / Spend.
 * Month and date-range columns are skipped (they are not single days); only real days are imported.
 * Returns null when the tab does not look like this layout.
 */
function ecom_tab_wide(array $tab, string $filename): ?array
{
    $rows = array_values($tab['rows']);
    if (count($rows) < 3) { return null; }
    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    // header = first row (within 6) that has 3+ date-like or "sale"/"spend" labels
    $hi = null;
    foreach (array_slice($rows, 0, 6, true) as $i => $r) {
        $c = 0;
        foreach ($r as $v) { if (is_string($v) ? preg_match('/sale|spend|\d/i', $v) : is_numeric($v)) { $c++; } }
        if ($c >= 4) { $hi = $i; break; }
    }
    if ($hi === null) { return null; }
    $h0 = $rows[$hi]; $h1 = $rows[$hi + 1] ?? [];
    $n = max(count($h0), count($h1));
    $cols = []; // j => [day, kind]
    $daily = false; $ctx = null; $labelDay = null; $skip = false; $last = null; $bad = 0; $baseOnly = false;
    // A heading is accepted if it follows the previous one (0-40 days later). If not, try the usual typos:
    // day and month swapped (01/06 typed as 06/01) and a wrong year.
    $accept = function (string $d) use (&$last, &$bad): ?string {
        $fits = fn(string $x) => $last === null || ((strtotime($x) - strtotime($last)) / 86400 >= 0 && (strtotime($x) - strtotime($last)) / 86400 <= 40);
        [$y, $mo, $dd] = array_map('intval', explode('-', $d));
        $try = [$d];
        if ($dd <= 12) { $try[] = sprintf('%04d-%02d-%02d', $y, $dd, $mo); }
        if ($last !== null) {
            $ly = (int)substr($last, 0, 4);
            $try[] = sprintf('%04d-%02d-%02d', $ly, $mo, $dd);
            if ($dd <= 12) { $try[] = sprintf('%04d-%02d-%02d', $ly, $dd, $mo); }
            $try[] = sprintf('%04d-%02d-%02d', $ly + 1, $mo, $dd);
        }
        $best = null; $bd = 1e9;
        foreach ($try as $x) {
            if (!valid_date($x) || !$fits($x)) { continue; }
            $df = $last === null ? 0 : (strtotime($x) - strtotime($last)) / 86400;
            if ($df < $bd) { $bd = $df; $best = $x; }        // the closest date after the previous heading wins
        }
        if ($best === null) { $bad++; return null; }
        $last = $best; return $best;
    };
    for ($j = 1; $j < $n; $j++) {
        $a = $h0[$j] ?? null; $b = trim((string)($h1[$j] ?? ''));
        $at = trim((string)$a);
        if ($at !== '') {
            $skip = false; $labelDay = null; $baseOnly = false;
            if (preg_match('/^(\d{1,2})\s*[-–]\s*(\d{1,2})\s*([a-z]{3})/i', $at, $m)) {                // range "1-15 Nov"
                $daily = true;
                $mo = $months[strtolower($m[3])] ?? null;
                if ($mo && $ctx) { $y = (int)substr($ctx, 0, 4); if ($mo < (int)substr($ctx, 5, 2) - 6) { $y++; } $ctx = sprintf('%04d-%02d-01', $y, $mo); }
                $ed = $mo && $ctx ? sprintf('%s-%02d', substr($ctx, 0, 7), (int)$m[2]) : '';
                if ($ed !== '' && valid_date($ed) && ($d2 = $accept($ed)) !== null) { $labelDay = $d2; $baseOnly = true; } else { $skip = true; }
            } elseif (($d = s_date($a)) !== null) {
                if (!$daily) { $skip = true; $ctx = $d; }                                              // month column
                elseif (($d2 = $accept($d)) !== null) { $labelDay = $d2; $ctx = substr($d2, 0, 7) . '-01'; } else { $skip = true; }
            } elseif ($daily && $ctx && preg_match('/^(\d{1,2})\s*([a-z]{3})?/i', $at, $m)) {         // "22 Sale" or "30 Jan"
                $mo = !empty($m[2]) ? ($months[strtolower($m[2])] ?? null) : null;
                $ym = $mo ? sprintf('%04d-%02d', (int)substr($ctx, 0, 4) + ($mo < (int)substr($ctx, 5, 2) - 6 ? 1 : 0), $mo) : substr($ctx, 0, 7);
                $d = sprintf('%s-%02d', $ym, (int)$m[1]);
                if (valid_date($d) && ($d2 = $accept($d)) !== null) { $labelDay = $d2; $ctx = substr($d2, 0, 7) . '-01'; } else { $skip = true; }
            } else { $skip = true; }
        }
        $kindTxt = strtolower($b !== '' ? $b : $at);
        $kind = str_contains($kindTxt, 'spend') ? 'spend' : (str_contains($kindTxt, 'sale') ? 'sales' : '');
        if ($kind === '' && $at !== '' && $b === '' && $labelDay) { $kind = 'sales'; }
        if (!$skip && $labelDay && $kind !== '') { $cols[$j] = [$labelDay, $kind, $baseOnly]; }
    }
    if (!$cols) { return null; }
    // 1) read every figure: $ser[platform][kind][day] = [value, baselineOnly]
    $ser = [];
    foreach (array_slice($rows, $hi + 2) as $r) {
        $pk = ecom_platform_key((string)($r[0] ?? ''));
        if ($pk === '') { continue; }
        foreach ($cols as $j => [$day, $kind, $base]) {
            $raw = $r[$j] ?? null;
            if ($raw === null || trim((string)$raw) === '' || trim((string)$raw) === '-') { continue; }
            $ser[$pk][$kind][$day] = [s_amount($raw), $base];
        }
    }
    // 2) management sheets usually keep month-to-date running totals. If a month's figures only ever go up,
    //    turn them into daily amounts (today's total minus the previous total of the same month).
    $agg = []; $cum = 0;
    foreach ($ser as $pk => $kinds) {
        foreach ($kinds as $kind => $days) {
            ksort($days);
            $byMonth = [];
            foreach ($days as $d => $v) { $byMonth[substr($d, 0, 7)][$d] = $v; }
            foreach ($byMonth as $mon => $pts) {
                $vals = array_column($pts, 0);
                $up = 0; $steps = max(0, count($vals) - 1);
                for ($i = 1; $i < count($vals); $i++) { if ($vals[$i] >= $vals[$i - 1]) { $up++; } }
                $isCum = $steps >= 3 && $up / $steps >= 0.8;
                $prev = 0.0;
                foreach ($pts as $d => [$v, $base]) {
                    $val = $v;
                    if ($isCum) { $val = $v >= $prev ? $v - $prev : $v; $prev = $v; }
                    if ($base) { continue; }
                    $k = $pk . '|' . $d;
                    $agg[$k] = $agg[$k] ?? ['p' => $pk, 'day' => $d, 'sales' => 0.0, 'orders' => 0, 'units' => 0, 'spend' => 0.0, '_f' => []];
                    $agg[$k][$kind] = round($val, 2);
                    $agg[$k]['_f'][$kind] = true;
                }
                if ($isCum) { $cum++; }
            }
        }
    }
    if (!$agg) { return null; }
    ksort($agg);
    $out = [];
    foreach ($agg as $a) { $a['_s'] = isset($a['_f']['sales']); $a['_sp'] = isset($a['_f']['spend']); unset($a['_f']); $out[] = $a; }
    return ['name' => (string)$tab['name'], 'ok' => true, 'cols' => ['sales', 'spend'], 'platform' => '', 'rows' => $out, 'note' => 'Management sheet layout: ' . count($out) . ' platform-days' . ($cum ? ', running month totals converted to daily amounts' : '') . ($bad ? ', ' . $bad . ' column heading(s) with a wrong-looking date skipped' : '') . ' (month and range columns skipped).'];
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
    if ($hi === null) {
        $w = ecom_tab_wide($tab, $filename);
        if ($w) { return $w; }
        $res['note'] = 'No date with a sales or spend column found.'; return $res;
    }
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
            if (!isset(ECOM_PLATFORMS[$p]) || !in_array($p, ECOM_SHEET, true)) { continue; } // Amazon and Flipkart come from the main sale sheet
            $vals = [];
            foreach ($t['cols'] as $f) {
                if (isset($r['_s']) && (($f === 'sales' && !$r['_s']) || ($f === 'spend' && !$r['_sp']))) { continue; }
                $vals[$f] = $r[$f];
            }
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
