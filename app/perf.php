<?php
defined('DD') or exit;
/* Performance marketing sheet ("Denzour Nutrition - Performance Report"): one tab per month holding
   (1) daily Meta and Google spend and sales, (2) Shopify totals and (3) a traffic funnel.
   Re-read every 30 minutes; each import replaces the month it finds. */

const PERF_SHEETS = [['Performance report', '167LDIdWrxGOmO4GmzJ8tZYCsRDDvLiwrnQLTh3Kgmmg', 'perf']];

function perf_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS perf_daily (ym TEXT NOT NULL, day INTEGER NOT NULL,
        m_spend REAL NOT NULL DEFAULT 0, m_sales REAL NOT NULL DEFAULT 0, g_spend REAL NOT NULL DEFAULT 0, g_sales REAL NOT NULL DEFAULT 0,
        shop REAL NOT NULL DEFAULT 0, net REAL NOT NULL DEFAULT 0, organic REAL NOT NULL DEFAULT 0,
        sessions REAL NOT NULL DEFAULT 0, carts REAL NOT NULL DEFAULT 0, checkouts REAL NOT NULL DEFAULT 0, orders REAL NOT NULL DEFAULT 0, bounce REAL NOT NULL DEFAULT 0,
        m_impr REAL NOT NULL DEFAULT 0, m_orders REAL NOT NULL DEFAULT 0, m_visits REAL NOT NULL DEFAULT 0,
        g_impr REAL NOT NULL DEFAULT 0, g_orders REAL NOT NULL DEFAULT 0, g_visits REAL NOT NULL DEFAULT 0,
        PRIMARY KEY (ym, day))");
    try {
        if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'seed_perf_v1'")->fetchColumn()) {
            foreach (PERF_SHEETS as [$label, $id, $kind]) {
                $has = $pdo->prepare('SELECT 1 FROM gsync_links WHERE sheet_id = ? AND kind = ?'); $has->execute([$id, $kind]);
                if (!$has->fetchColumn()) {
                    $pdo->prepare('INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at, month) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$label, $kind, 0, $id, 'https://docs.google.com/spreadsheets/d/' . $id . '/edit', 30, date('Y-m-d H:i:s'), '']);
                }
            }
            $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('seed_perf_v1', '1')");
        }
    } catch (Throwable $e) { /* gsync table not ready yet */ }
}

/** Finds a column by header text in a row (first match from $from). */
function perf_col(array $row, string $re, int $from = 0): ?int
{
    foreach ($row as $i => $c) {
        if ($i >= $from && preg_match($re, ad_hdr((string)$c))) { return (int)$i; }
    }
    return null;
}

function perf_import(string $path, string $linkMonth = ''): string
{
    $tabs = xlsx_read($path); $done = [];
    foreach ($tabs as $t) {
        $rows = $t['rows']; $hi = null; $ti = null;
        foreach (array_slice($rows, 0, 80, true) as $i => $r) {
            $h = array_map(fn($c) => ad_hdr((string)$c), $r);
            if ($hi === null && in_array('metaads', $h, true) && in_array('googleads', $h, true) && in_array('day', $h, true)) { $hi = $i; }
            if ($ti === null && in_array('date', $h, true) && in_array('totalsessions', $h, true)) { $ti = $i; }
        }
        if ($hi === null) { continue; }
        $sub = $rows[$hi + 1] ?? [];
        $cMs = perf_col($sub, '/^metaspend$/'); $cMs2 = perf_col($sub, '/^metasales?$/');
        $cGs = perf_col($sub, '/^googlespend$/'); $cGs2 = perf_col($sub, '/^googlesales?$/');
        $cSh = perf_col($sub, '/^totalsales(shopify)?$/'); $cNet = perf_col($sub, '/^netsale$/'); $cOrg = perf_col($sub, '/^organic$/');
        if ($cMs === null || $cMs2 === null) { continue; }
        $days = [];
        for ($i = $hi + 2; $i < count($rows) && $i < $hi + 40; $i++) {
            $r = $rows[$i]; $d = $r[0] ?? '';
            if (is_string($d) && preg_match('/^total|^avg/i', trim($d))) { break; }
            $dn = (int)ad_num($d);
            if ($dn < 1 || $dn > 31) { continue; }
            $g = fn(?int $c) => $c === null ? 0.0 : ad_num($r[$c] ?? 0);
            $days[$dn] = ['m_spend' => $g($cMs), 'm_sales' => $g($cMs2), 'g_spend' => $g($cGs), 'g_sales' => $g($cGs2), 'shop' => $g($cSh), 'net' => $g($cNet), 'organic' => $g($cOrg)];
        }
        $ym = '';
        if ($ti !== null) {
            $th = $rows[$ti];
            $cSes = perf_col($th, '/^totalsessions$/'); $cCar = perf_col($th, '/^totalcarts$/'); $cChk = perf_col($th, '/^checkoutsinitiated$/'); $cOrd = perf_col($th, '/^totalordersplaced$/'); $cBou = perf_col($th, '/^bouncerate$/');
            $cDt = perf_col($th, '/^date$/');
            $fromB = $cBou ?? 0;
            $mI = perf_col($th, '/^impressions$/', $fromB); $mO = perf_col($th, '/^orders$/', $fromB); $mV = perf_col($th, '/^visits$/', $fromB);
            $gI = $mI !== null ? perf_col($th, '/^impressions$/', $mI + 1) : null; $gO = $mO !== null ? perf_col($th, '/^orders$/', $mO + 1) : null; $gV = $mV !== null ? perf_col($th, '/^visits$/', $mV + 1) : null;
            for ($i = $ti + 1; $i < count($rows) && $i < $ti + 40; $i++) {
                $r = $rows[$i]; $dt = ad_date($r[$cDt] ?? '');
                if ($dt === '') { if (is_string($r[$cDt] ?? null) && preg_match('/^total/i', trim($r[$cDt]))) { break; } continue; }
                if ($ym === '') { $ym = substr($dt, 0, 7); }
                if (substr($dt, 0, 7) !== $ym) { continue; }
                $dn = (int)substr($dt, 8, 2);
                $g = fn(?int $c) => $c === null ? 0.0 : ad_num($r[$c] ?? 0);
                $days[$dn] = ($days[$dn] ?? ['m_spend' => 0.0, 'm_sales' => 0.0, 'g_spend' => 0.0, 'g_sales' => 0.0, 'shop' => 0.0, 'net' => 0.0, 'organic' => 0.0])
                    + ['sessions' => $g($cSes), 'carts' => $g($cCar), 'checkouts' => $g($cChk), 'orders' => $g($cOrd), 'bounce' => $g($cBou), 'm_impr' => $g($mI), 'm_orders' => $g($mO), 'm_visits' => $g($mV), 'g_impr' => $g($gI), 'g_orders' => $g($gO), 'g_visits' => $g($gV)];
            }
        }
        if ($ym === '') { $ym = preg_match('/^\d{4}-\d{2}$/', $linkMonth) ? $linkMonth : substr(today(), 0, 7); }
        // keep only days that carry numbers
        $keep = [];
        foreach ($days as $dn => $v) { if (checkdate((int)substr($ym, 5, 2), $dn, (int)substr($ym, 0, 4)) && (($v['m_spend'] ?? 0) + ($v['g_spend'] ?? 0) + ($v['shop'] ?? 0) + ($v['sessions'] ?? 0)) > 0) { $keep[$dn] = $v; } }
        if (!$keep) { continue; }
        $cols = ['m_spend', 'm_sales', 'g_spend', 'g_sales', 'shop', 'net', 'organic', 'sessions', 'carts', 'checkouts', 'orders', 'bounce', 'm_impr', 'm_orders', 'm_visits', 'g_impr', 'g_orders', 'g_visits'];
        $pdo = db(); $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM perf_daily WHERE ym = ?')->execute([$ym]);
            $ins = $pdo->prepare('INSERT INTO perf_daily (ym, day, ' . implode(',', $cols) . ') VALUES (?,?,' . implode(',', array_fill(0, count($cols), '?')) . ')');
            foreach ($keep as $dn => $v) { $ins->execute(array_merge([$ym, $dn], array_map(fn($c) => (float)($v[$c] ?? 0), $cols))); }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        $done[] = date('M Y', strtotime($ym . '-01')) . ' (' . count($keep) . ' days, last day ' . max(array_keys($keep)) . ')';
    }
    if (!$done) { throw new RuntimeException('Could not find the Meta Ads and Google Ads daily table in this sheet.'); }
    return 'Read ' . implode(', ', $done) . '.';
}
