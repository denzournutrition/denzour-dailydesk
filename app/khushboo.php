<?php
defined('DD') or exit;
/* Khushboo's order desk: (1) the Shopify order-confirmation log (one tab per month) and (2) the Orders Details sheet
   (offline sale, online sale, B2B COD for Bhanu and Shivam, sample dispatch, RTO and re-dispatch). Both are re-read every few minutes. */

const KB_SHEETS = [
    ['Khushboo · Order confirmation', '1AqW-PB1lPVOQItK4iFv_yxuPmMKMIGvt8wg_QxbQMJQ', 'kbconf'],
    ['Khushboo · Order details', '1JSJ9mPkHWvRc3NIcfHUwcMEimw_aA_j9b9_Z7luDog4', 'kbdet'],
];

function kb_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_conf (ym TEXT NOT NULL, day INTEGER NOT NULL DEFAULT 0, oid TEXT NOT NULL DEFAULT '', conf TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', sc TEXT NOT NULL DEFAULT 'oth', courier TEXT NOT NULL DEFAULT '', pay TEXT NOT NULL DEFAULT '', amt REAL NOT NULL DEFAULT 0, reason TEXT NOT NULL DEFAULT '')");
    $pdo->exec('CREATE INDEX IF NOT EXISTS kb_conf_ym ON kb_conf (ym, day)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_off (day TEXT NOT NULL DEFAULT '', party TEXT NOT NULL DEFAULT '', oid TEXT NOT NULL DEFAULT '', base TEXT NOT NULL DEFAULT '', track TEXT NOT NULL DEFAULT '', courier TEXT NOT NULL DEFAULT '', pay TEXT NOT NULL DEFAULT '', adv REAL NOT NULL DEFAULT 0, cod REAL NOT NULL DEFAULT 0, pstat TEXT NOT NULL DEFAULT '', pend REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_on (day TEXT NOT NULL DEFAULT '', oid TEXT NOT NULL DEFAULT '', track TEXT NOT NULL DEFAULT '', courier TEXT NOT NULL DEFAULT '', dstatus TEXT NOT NULL DEFAULT '', pay TEXT NOT NULL DEFAULT '', total REAL NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_b2b (who TEXT NOT NULL DEFAULT '', day TEXT NOT NULL DEFAULT '', party TEXT NOT NULL DEFAULT '', addr TEXT NOT NULL DEFAULT '', contact TEXT NOT NULL DEFAULT '', oid TEXT NOT NULL DEFAULT '', track TEXT NOT NULL DEFAULT '', courier TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', pay TEXT NOT NULL DEFAULT '', adv REAL NOT NULL DEFAULT 0, pend REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, remark TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_samp (party TEXT NOT NULL DEFAULT '', items TEXT NOT NULL DEFAULT '', addr TEXT NOT NULL DEFAULT '', contact TEXT NOT NULL DEFAULT '', oid TEXT NOT NULL DEFAULT '', track TEXT NOT NULL DEFAULT '', courier TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', pay TEXT NOT NULL DEFAULT '', total REAL NOT NULL DEFAULT 0, sender TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_rto (day TEXT NOT NULL DEFAULT '', oid TEXT NOT NULL DEFAULT '', t1 TEXT NOT NULL DEFAULT '', t2 TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_cod (cust TEXT NOT NULL DEFAULT '', sales TEXT NOT NULL DEFAULT '', oid TEXT NOT NULL DEFAULT '', sent TEXT NOT NULL DEFAULT '', track TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', delivered TEXT NOT NULL DEFAULT '', adv REAL NOT NULL DEFAULT 0, cod REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0)");
    try {
        if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'seed_kb_v1'")->fetchColumn()) {
            foreach (KB_SHEETS as [$label, $id, $kind]) {
                $has = $pdo->prepare('SELECT 1 FROM gsync_links WHERE sheet_id = ? AND kind = ?'); $has->execute([$id, $kind]);
                if (!$has->fetchColumn()) {
                    $pdo->prepare('INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at, month) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$label, $kind, 0, $id, 'https://docs.google.com/spreadsheets/d/' . $id . '/edit', 10, date('Y-m-d H:i:s'), '']);
                }
            }
            $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('seed_kb_v1', '1')");
        }
    } catch (Throwable $e) { /* gsync table not ready yet */ }
}

function kb_num($v): float
{
    if (is_int($v) || is_float($v)) { return (float)$v; }
    $s = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return is_numeric($s) ? (float)$s : 0.0;
}
function kb_s($v): string { return trim(preg_replace('/\s+/u', ' ', is_float($v) && floor($v) == $v ? (string)(int)$v : (string)$v)); }

/** The sheets carry year typos (16-11-2027, 18-11-27), so only the day and month are trusted; the year is the latest one that is not in the future. */
function kb_date($v): string
{
    $d = 0; $m = 0; $y = 0;
    if (is_float($v) || is_int($v)) {
        if (!($v > 30000 && $v < 80000)) { return ''; }
        $ts = (int)(($v - 25569) * 86400); $a = gmdate('Y-m-d', $ts);
        // The sheets were typed as D-M-YY but Google read many of them as M-D-YY, so 1-11-26 is stored as 11 Jan. When both readings are real dates, the one closest to today (not in the future) is the right one.
        $M = (int)gmdate('n', $ts); $D = (int)gmdate('j', $ts); $Y = (int)gmdate('Y', $ts);
        if ($D <= 12 && $M !== $D) {
            $b = sprintf('%04d-%02d-%02d', $Y, $D, $M); $lim = date('Y-m-d', strtotime(today() . ' +2 days'));
            $okA = $a <= $lim; $okB = $b <= $lim;
            if ($okB && (!$okA || abs(strtotime(today()) - strtotime($b)) < abs(strtotime(today()) - strtotime($a)))) { return $b; }
        }
        return $a;
    }
    $s = trim((string)$v);
    if ($s === '') { return ''; }
    $mon = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    if (preg_match('/(\d{1,2})\s*(?:st|nd|rd|th)?\s*([A-Za-z]{3})[a-z]*\.?,?\s*(\d{2,4})?/', $s, $x) && isset($mon[strtolower($x[2])])) {
        $d = (int)$x[1]; $m = $mon[strtolower($x[2])]; $y = (int)($x[3] ?? 0);
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $x)) {
        $y = (int)$x[1]; $m = (int)$x[2]; $d = (int)$x[3];
    } elseif (preg_match('/^(\d{1,2})([\/\-.])(\d{1,2})(?:[\/\-.](\d{2,4}))?/', $s, $x)) {
        $a = (int)$x[1]; $b = (int)$x[3]; $y = (int)($x[4] ?? 0);
        // Google's xlsx export writes date cells as M/D/YY; typed text uses D-M-YY
        if ($b > 12 && $a <= 12) { $m = $a; $d = $b; } elseif ($a > 12) { $d = $a; $m = $b; } elseif ($x[2] === '/') { $m = $a; $d = $b; } else { $d = $a; $m = $b; }
    } else { return ''; }
    if ($m < 1 || $m > 12 || $d < 1 || $d > 31) { return ''; }
    if ($y > 0 && $y < 100) { $y += 2000; }
    $today = today(); $cy = (int)substr($today, 0, 4);
    if ($y < 2024 || $y > $cy) { $y = $cy; }
    $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
    if ($iso > date('Y-m-d', strtotime($today . ' +2 days'))) { $iso = sprintf('%04d-%02d-%02d', $y - 1, $m, $d); }
    return checkdate($m, $d, (int)substr($iso, 0, 4)) ? $iso : '';
}

function kb_sc(string $st): string
{
    $s = strtolower($st);
    if ($s === 'delivered' || strpos($s, 'by hand') !== false) { return 'del'; }
    if (strpos($s, 'out for') !== false) { return 'ofd'; }
    if (strpos($s, 'transit') !== false || $s === 'shipped' || $s === 'dispatched') { return 'tra'; }
    if (strpos($s, 'rto') !== false || strpos($s, 'return') !== false) { return 'rto'; }
    if (strpos($s, 'cancel') !== false) { return 'can'; }
    return 'oth';
}

function kb_cols(array $head, array $want): array
{
    $h = array_map(fn($c) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$c))), $head);
    $out = [];
    foreach ($want as $k => $re) { $out[$k] = -1; foreach ($h as $i => $c) { if ($c !== '' && preg_match($re, $c)) { $out[$k] = $i; break; } } }
    return $out;
}
function kb_head_row(array $rows, string $needRe): int
{
    foreach (array_slice($rows, 0, 6, true) as $i => $r) { if (preg_match($needRe, strtolower(implode('|', array_map('strval', $r))))) { return (int)$i; } }
    return -1;
}

function kb_import_conf(string $path): string
{
    $tabs = xlsx_read($path);
    $pdo = db(); $mon = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    $minYm = date('Y-m', strtotime(today() . '-01 -14 months'));
    $pdo->beginTransaction();
    $n = 0; $months = 0; $probe = '';
    $ins = $pdo->prepare('INSERT INTO kb_conf (ym, day, oid, conf, status, sc, courier, pay, amt, reason) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($tabs as $t) {
        if (!preg_match('/^\s*([A-Za-z]{3})[a-z]*[\s\-\']*(\d{2})\b/', (string)$t['name'], $x) || !isset($mon[strtolower($x[1])])) { continue; }
        $ym = sprintf('20%02d-%02d', (int)$x[2], $mon[strtolower($x[1])]);
        if ($ym < $minYm || $ym > date('Y-m', strtotime(today() . ' +1 month'))) { continue; }
        $hr = kb_head_row($t['rows'], '/order.*status|status.*order/');
        if ($hr < 0) { continue; }
        $c = kb_cols($t['rows'][$hr], ['date' => '/^date/', 'oid' => '/^order( id| no)?$/', 'conf' => '/order confirmation/', 'st' => '/^status$/', 'cour' => '/courier/', 'pay' => '/payment method/', 'amt' => '/^(amount|gross figure)$/', 'rc' => '/reason of cancel/', 'rr' => '/reason of rto/']);
        if ($c['oid'] < 0 || $c['st'] < 0) { continue; }
        if ($c['amt'] < 0 && $c['pay'] >= 0) { $c['amt'] = $c['pay'] + 1; } // some months have an unnamed amount column
        $pdo->prepare('DELETE FROM kb_conf WHERE ym = ?')->execute([$ym]);
        $months++;
        $dim = (int)date('t', strtotime($ym . '-01'));
        foreach (array_slice($t['rows'], $hr + 1) as $r) {
            $oid = kb_s($r[$c['oid']] ?? '');
            if (!preg_match('/\d{3,}/', $oid)) { continue; }
            $dv = $r[$c['date']] ?? ''; $day = 0;
            if ($probe === '' && $ym === date('Y-m')) { $probe = gettype($dv) . ':' . (is_scalar($dv) ? (string)$dv : '?'); }
            if (is_float($dv) || is_int($dv)) {
                if ($dv > 30000) {
                    $ts = (int)(($dv - 25569) * 86400); $M = (int)gmdate('n', $ts); $D = (int)gmdate('j', $ts); $mo = (int)substr($ym, 5, 2);
                    $day = ($D === $mo && $M !== $mo) ? $M : $D; // typed D-M-YY read as M-D-YY: 1-11-26 is stored as 11 Jan
                } else { $day = (int)$dv; }
            }
            elseif (preg_match('/^(\d{1,2})([\/\-.])(\d{1,2})/', (string)$dv, $dm)) {
                $a = (int)$dm[1]; $b = (int)$dm[3]; $mo = (int)substr($ym, 5, 2);
                if ($a === $mo && $b !== $mo) { $day = $b; } elseif ($b === $mo) { $day = $a; } else { $day = $dm[2] === '/' ? $b : $a; }
            } elseif (preg_match('/\d{1,2}/', (string)$dv, $dm)) { $day = (int)$dm[0]; }
            if ($day < 1 || $day > $dim) { $day = 0; }
            $st = kb_s($r[$c['st']] ?? '');
            $reason = kb_s($r[$c['rc']] ?? '') ?: kb_s($r[$c['rr']] ?? '');
            $ins->execute([$ym, $day, ltrim($oid, '#'), kb_s($r[$c['conf']] ?? ''), $st, kb_sc($st), kb_s($r[$c['cour']] ?? ''), kb_s($r[$c['pay']] ?? ''), kb_num($r[$c['amt']] ?? 0), $reason]);
            $n++;
        }
    }
    $pdo->commit();
    return 'Read ' . $n . ' confirmed orders across ' . $months . ' months.' . ($probe !== '' ? ' First date cell: ' . $probe . '.' : '');
}

function kb_import_det(string $path): string
{
    $tabs = xlsx_read($path); $pdo = db(); $cnt = [];
    $pdo->beginTransaction();
    foreach ($tabs as $t) {
        $nm = strtolower((string)$t['name']); $rows = $t['rows'];
        $hr = kb_head_row($rows, '/order|party|customer|date/');
        if ($hr < 0) { continue; }
        $body = array_slice($rows, $hr + 1);
        if (strpos($nm, 'offline_sale') !== false) {
            $c = kb_cols($rows[$hr], ['party' => '/^party/', 'date' => '/order date/', 'oid' => '/order id/', 'cour' => '/courier/', 'trk' => '/tracking/', 'pay' => '/payment method/', 'adv' => '/advance/', 'cod' => '/cod amount/', 'ps' => '/payment status/', 'pend' => '/pending/', 'tot' => '/total/']);
            $pdo->exec('DELETE FROM kb_off'); $i = $pdo->prepare('INSERT INTO kb_off VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'); $n = 0; $lastParty = ''; $lastDay = '';
            foreach ($body as $r) {
                $oid = kb_s($r[$c['oid']] ?? ''); if ($oid === '') { continue; }
                $party = kb_s($r[$c['party']] ?? '') ?: $lastParty; $lastParty = $party;
                $day = kb_date($r[$c['date']] ?? '') ?: $lastDay; $lastDay = $day;
                $i->execute([$day, $party, $oid, preg_replace('/-\d+$/', '', $oid), kb_s($r[$c['trk']] ?? ''), kb_s($r[$c['cour']] ?? ''), kb_s($r[$c['pay']] ?? ''), kb_num($r[$c['adv']] ?? 0), kb_num($r[$c['cod']] ?? 0), kb_s($r[$c['ps']] ?? ''), kb_num($r[$c['pend']] ?? 0), kb_num($r[$c['tot']] ?? 0)]); $n++;
            }
            $cnt[] = $n . ' offline parcels';
        } elseif (strpos($nm, 'online_sale') !== false) {
            $c = kb_cols($rows[$hr], ['date' => '/^date/', 'oid' => '/order id/', 'trk' => '/tracking/', 'cour' => '/courier/', 'ds' => '/delivery status/', 'pay' => '/payment method/', 'tot' => '/total/']);
            $pdo->exec('DELETE FROM kb_on'); $i = $pdo->prepare('INSERT INTO kb_on VALUES (?,?,?,?,?,?,?)'); $n = 0;
            foreach ($body as $r) { $oid = kb_s($r[$c['oid']] ?? ''); if ($oid === '') { continue; }
                $i->execute([kb_date($r[$c['date']] ?? ''), $oid, kb_s($r[$c['trk']] ?? ''), kb_s($r[$c['cour']] ?? ''), kb_s($r[$c['ds']] ?? ''), kb_s($r[$c['pay']] ?? ''), kb_num($r[$c['tot']] ?? 0)]); $n++; }
            $cnt[] = $n . ' online';
        } elseif (strpos($nm, 'b2b') !== false) {
            $who = trim(preg_replace('/\s*b2b.*$/i', '', (string)$t['name']));
            $c = kb_cols($rows[$hr], ['party' => '/^party/', 'date' => '/order date/', 'addr' => '/address/', 'con' => '/contact/', 'oid' => '/order id/', 'trk' => '/tracking/', 'cour' => '/courier/', 'st' => '/^status$/', 'pay' => '/payment method/', 'adv' => '/advance/', 'pend' => '/pending/', 'tot' => '/^total/', 'rem' => '/remark/']);
            $pdo->prepare('DELETE FROM kb_b2b WHERE who = ?')->execute([$who]); $i = $pdo->prepare('INSERT INTO kb_b2b VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'); $n = 0;
            foreach ($body as $r) { $party = kb_s($r[$c['party']] ?? ''); if ($party === '') { continue; }
                $adv = kb_num($r[$c['adv']] ?? 0); $pend = kb_num($r[$c['pend']] ?? 0); $tot = kb_num($r[$c['tot']] ?? 0); if ($tot <= 0) { $tot = $adv + $pend; }
                $i->execute([$who, kb_date($r[$c['date']] ?? ''), $party, kb_s($r[$c['addr']] ?? ''), kb_s($r[$c['con']] ?? ''), kb_s($r[$c['oid']] ?? ''), kb_s($r[$c['trk']] ?? ''), kb_s($r[$c['cour']] ?? ''), kb_s($r[$c['st']] ?? ''), kb_s($r[$c['pay']] ?? ''), $adv, $pend, $tot, kb_s($r[$c['rem']] ?? '')]); $n++; }
            if ($n) { $cnt[] = $n . ' ' . $who . ' B2B'; }
        } elseif (strpos($nm, 'sample') !== false) {
            $c = kb_cols($rows[$hr], ['party' => '/^party/', 'items' => '/sample/', 'addr' => '/address/', 'con' => '/contact/', 'oid' => '/order id/', 'trk' => '/tracking/', 'cour' => '/courier/', 'st' => '/^status$/', 'pay' => '/payment method/', 'tot' => '/^total/', 'rem' => '/remark/']);
            $pdo->exec('DELETE FROM kb_samp'); $i = $pdo->prepare('INSERT INTO kb_samp VALUES (?,?,?,?,?,?,?,?,?,?,?)'); $n = 0;
            foreach ($body as $r) { $party = kb_s($r[$c['party']] ?? ''); if ($party === '') { continue; }
                $i->execute([$party, kb_s($r[$c['items']] ?? ''), kb_s($r[$c['addr']] ?? ''), kb_s($r[$c['con']] ?? ''), kb_s($r[$c['oid']] ?? ''), kb_s($r[$c['trk']] ?? ''), kb_s($r[$c['cour']] ?? ''), kb_s($r[$c['st']] ?? ''), kb_s($r[$c['pay']] ?? ''), kb_num($r[$c['tot']] ?? 0), kb_s($r[$c['rem']] ?? '')]); $n++; }
            $cnt[] = $n . ' samples';
        } elseif (strpos($nm, 'rto') !== false) {
            $pdo->exec('DELETE FROM kb_rto'); $i = $pdo->prepare('INSERT INTO kb_rto VALUES (?,?,?,?)'); $n = 0;
            foreach (array_slice($rows, 1) as $r) { $oid = kb_s($r[1] ?? ''); if (!preg_match('/\d{3,}/', $oid)) { continue; } $i->execute([kb_s($r[0] ?? ''), $oid, kb_s($r[2] ?? ''), kb_s($r[3] ?? '')]); $n++; }
            $cnt[] = $n . ' RTO';
        } elseif (strpos($nm, 'cod-all') !== false || strpos($nm, 'cod all') !== false) {
            $c = kb_cols($rows[$hr], ['cust' => '/customer/', 'sp' => '/sales person/', 'oid' => '/order id/', 'sent' => '/sending/', 'trk' => '/tracking/', 'st' => '/order status/', 'dd' => '/delivered date/', 'adv' => '/advance/', 'cod' => '/cod/', 'tot' => '/total/']);
            $pdo->exec('DELETE FROM kb_cod'); $i = $pdo->prepare('INSERT INTO kb_cod VALUES (?,?,?,?,?,?,?,?,?,?)'); $n = 0;
            foreach ($body as $r) { $cu = kb_s($r[$c['cust']] ?? ''); if ($cu === '') { continue; }
                $i->execute([$cu, kb_s($r[$c['sp']] ?? ''), kb_s($r[$c['oid']] ?? ''), kb_s($r[$c['sent']] ?? ''), kb_s($r[$c['trk']] ?? ''), kb_s($r[$c['st']] ?? ''), kb_s($r[$c['dd']] ?? ''), kb_num($r[$c['adv']] ?? 0), kb_num($r[$c['cod']] ?? 0), kb_num($r[$c['tot']] ?? 0)]); $n++; }
            if ($n) { $cnt[] = $n . ' COD'; }
        }
    }
    $pdo->commit();
    return 'Read ' . ($cnt ? implode(', ', $cnt) : 'nothing') . '.';
}
