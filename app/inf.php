<?php
defined('DD') or exit;

/* Influencers: Sourabh's workbook (influencer-code orders by month, cost and realisation, affiliate list, payouts, barter).
   Bank, UPI, PAN, phone and address columns in that workbook are never read. */

const INF_SHEET_ID = '18KpmD704i8LmFQ0Y9-g4hgu2U-wkFeijWJ_oSx81jdA';

function inf_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS inf_order (month TEXT NOT NULL, day TEXT NOT NULL, oid TEXT NOT NULL DEFAULT '', code TEXT NOT NULL DEFAULT '', amount REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT 'sale', value REAL NOT NULL DEFAULT 0, cogs REAL NOT NULL DEFAULT 0, ship REAL NOT NULL DEFAULT 0, products TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inf_line (month TEXT NOT NULL, oid TEXT NOT NULL DEFAULT '', product TEXT NOT NULL, cogs REAL NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inf_code (code TEXT PRIMARY KEY, disp TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '', insta TEXT NOT NULL DEFAULT '', stack TEXT NOT NULL DEFAULT '', comm TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inf_perf (month TEXT PRIMARY KEY, sales REAL NOT NULL DEFAULT 0, spend REAL NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inf_pay (block TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '', code TEXT NOT NULL DEFAULT '', total REAL NOT NULL DEFAULT 0, net REAL NOT NULL DEFAULT 0, comm REAL NOT NULL DEFAULT 0, paid INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inf_barter (grp TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '', code TEXT NOT NULL DEFAULT '', insta TEXT NOT NULL DEFAULT '', product TEXT NOT NULL DEFAULT '', reel TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inf_meta (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '')");
    try {
        if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'seed_inf_sheet'")->fetchColumn()) {
            $has = $pdo->prepare('SELECT 1 FROM gsync_links WHERE sheet_id = ?'); $has->execute([INF_SHEET_ID]);
            if (!$has->fetchColumn()) {
                $pdo->prepare("INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at, month) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(['Influencers (Sourabh)', 'inf', 0, INF_SHEET_ID, 'https://docs.google.com/spreadsheets/d/' . INF_SHEET_ID . '/edit', 5, date('Y-m-d H:i:s'), '']);
            }
            $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('seed_inf_sheet', '1')");
        }
    } catch (Throwable $e) { /* never block start-up */ }
}

function inf_has_data(): bool
{
    try { return (bool)db()->query('SELECT 1 FROM inf_order LIMIT 1')->fetchColumn(); } catch (Throwable $e) { return false; }
}

/** A day number in the tab's month from "01/11/2026", "1st April", "3rd", "2/1/2026" or a date serial. Returns Y-m-d or ''. */
function inf_day($v, string $ym): string
{
    $M = (int)substr($ym, 5, 2); $d = 0;
    if (is_float($v) || is_int($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', trim($v)))) {
        $ts = ((int)floor((float)$v) - 25569) * 86400; if ($ts <= 0) { return ''; }
        $dd = (int)gmdate('j', $ts); $mm = (int)gmdate('n', $ts);
        $d = $mm === $M ? $dd : ($dd === $M ? $mm : $dd);
    } else {
        $t = trim((string)$v);
        if (preg_match('~^(\d{1,2})\s*[/.\-]\s*(\d{1,2})(?:\s*[/.\-]\s*\d{2,4})?~', $t, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2]; $d = $b === $M ? $a : ($a === $M ? $b : $a);
        } elseif (preg_match('/^(\d{1,2})/', $t, $m)) { $d = (int)$m[1]; }
    }
    return ($d >= 1 && checkdate($M, $d, (int)substr($ym, 0, 4))) ? sprintf('%s-%02d', $ym, $d) : '';
}

function inf_status(string $s, float $amount): array
{
    $t = strtolower(trim($s));
    if ($t === '') { return ['prepaid', 'sale']; }
    if (preg_match('/^(prepaid|paid)/', $t)) { return ['prepaid', 'sale']; }
    if (preg_match('/^del/', $t)) { return ['delivered', 'sale']; }
    if ($t === 'cod') { return ['cod', 'sale']; }
    if (preg_match('/transit/', $t)) { return ['transit', 'sale']; }
    if (preg_match('/partial/', $t)) { return ['partially paid', 'pending']; }
    if ($t === 'rto') { return ['rto', 'lost']; }
    if (preg_match('/cancel/', $t)) { return ['cancelled', 'lost']; }
    if (preg_match('/return/', $t)) { return ['return', 'lost']; }
    if (preg_match('/void/', $t)) { return ['voided', 'lost']; }
    if (preg_match('/dnp|not av|not reach|undeliver/', $t)) { return ['not reached', 'lost']; }
    return ['other', $amount > 0 ? 'sale' : 'skip'];
}

function inf_import(string $path): string
{
    $tabs = xlsx_read($path);
    $num = fn($v) => is_numeric($v) ? (float)$v : (float)preg_replace('/[^0-9.]/', '', (string)$v);
    $cell = fn(array $r, int $i) => trim((string)($r[$i] ?? ''));
    $mn = fn(string $n) => b2b_month_no(preg_replace('/^\s*(\d+\s*)?/', '', $n));
    $curY = (int)substr(today(), 0, 4);

    // 1. work out which tab is which month
    $cand = []; // ym => [tabIndex, hasCogs, orders]
    foreach ($tabs as $ti => $t) {
        $name = strtolower(trim((string)$t['name']));
        if (!preg_match('/datewise|date wise|^sep cogs/', $name)) { continue; }
        $mon = $mn($name); if ($mon < 1) { continue; }
        // header row
        $hi = -1; $h = [];
        foreach (array_slice($t['rows'], 0, 6, true) as $i => $r) {
            $hh = array_map(fn($c) => strtolower(trim((string)$c)), $r); $hasOrd = false; $hasCode = false;
            foreach ($hh as $c) { if (preg_match('/^order\s*i/', $c)) { $hasOrd = true; } if (str_contains($c, 'code')) { $hasCode = true; } }
            if ($hasOrd && $hasCode) { $hi = $i; $h = $hh; break; }
        }
        if ($hi < 0) { continue; }
        $col = ['date' => -1, 'oid' => -1, 'code' => -1, 'amt' => -1, 'st' => -1, 'transit' => -1, 'prod' => -1, 'cogs' => -1, 'ship' => -1, 'total' => -1];
        foreach ($h as $i => $c) {
            if (preg_match('/^date/', $c)) { $col['date'] = $i; }
            elseif (str_contains($c, 'transit')) { $col['transit'] = $i; }
            elseif (preg_match('/^order\s*i/', $c)) { $col['oid'] = $i; }
            elseif (str_contains($c, 'code')) { $col['code'] = $i; }
            elseif (preg_match('/^amount/', $c)) { $col['amt'] = $i; }
            elseif (preg_match('/^status/', $c)) { $col['st'] = $i; }
            elseif (preg_match('/^product/', $c)) { $col['prod'] = $i; }
            elseif ($c === 'cogs') { $col['cogs'] = $i; }
            elseif (preg_match('/^shipping/', $c)) { $col['ship'] = $i; }
            elseif ($c === 'total') { $col['total'] = $i; }
        }
        if ($col['date'] < 0) { $col['date'] = 0; }   // a tab whose date header is blank or odd (the date sits in the first column)
        if ($col['oid'] < 0 || $col['code'] < 0 || $col['amt'] < 0) { continue; }
        $year = 0;
        foreach (array_slice($t['rows'], $hi + 1, 40) as $r) { if ($col['date'] >= 0 && preg_match('~(?:[/.\-])(20\d\d)\b~', (string)($r[$col['date']] ?? ''), $m)) { $year = (int)$m[1]; break; } }
        if ($year === 0) { $year = preg_match('/^(oct|nov|dec)\s+datewise/', $name) ? 2025 : $curY; }
        $ym = sprintf('%04d-%02d', $year, $mon);
        $orders = 0; foreach (array_slice($t['rows'], $hi + 1) as $r) { if ($cell($r, $col['oid']) !== '' && $num($r[$col['amt']] ?? 0) > 0) { $orders++; } }
        $score = ($col['cogs'] >= 0 ? 100000 : 0) + $orders;
        if ($orders > 0 && (!isset($cand[$ym]) || $score > $cand[$ym]['score'])) { $cand[$ym] = ['ti' => $ti, 'hi' => $hi, 'col' => $col, 'score' => $score]; }
    }
    if (!$cand) { return 'No order tabs found (expected tabs like "November datewise orders").'; }

    // 2. orders and product lines
    $orders = []; $lines = []; $codeSeen = [];
    foreach ($cand as $ym => $c) {
        $col = $c['col']; $rows = $tabs[$c['ti']]['rows']; $cur = null; $day = '';
        $flush = function () use (&$cur, &$orders) { if ($cur !== null) { $orders[] = $cur; $cur = null; } };
        foreach ($rows as $i => $r) {
            if ($i <= $c['hi']) { continue; }
            if ($col['date'] >= 0 && trim((string)($r[$col['date']] ?? '')) !== '') { $d = inf_day($r[$col['date']], $ym); if ($d !== '') { $day = $d; } }
            $oid = $cell($r, $col['oid']); $code = strtolower(preg_replace('/\s+/', '', (string)($r[$col['code']] ?? '')));
            $prod = $col['prod'] >= 0 ? trim((string)($r[$col['prod']] ?? '')) : '';
            $cg = $col['cogs'] >= 0 ? $num($r[$col['cogs']] ?? 0) : 0.0;
            if ($oid !== '' || $code !== '') {
                $flush();
                if ($day === '') { continue; }
                $amount = $num($r[$col['amt']] ?? 0); [$st, $kind] = inf_status($col['st'] >= 0 ? (string)($r[$col['st']] ?? '') : '', $amount);
                $tv = $col['transit'] >= 0 ? $num($r[$col['transit']] ?? 0) : 0.0;
                $cur = ['month' => $ym, 'day' => $day, 'oid' => $oid, 'code' => $code, 'amount' => $amount, 'status' => $st, 'kind' => $kind, 'value' => $kind === 'sale' ? $amount : max($tv, $amount),
                        'cogs' => 0.0, 'ship' => $col['ship'] >= 0 ? $num($r[$col['ship']] ?? 0) : 0.0, 'products' => []];
                if ($code !== '' && !isset($codeSeen[$code])) { $codeSeen[$code] = trim((string)($r[$col['code']] ?? '')); }
            }
            if ($cur === null) { continue; }
            if ($prod !== '' || $cg > 0) {
                $cur['cogs'] += $cg;
                foreach (preg_split('/\R/', $prod) as $p) {
                    $p = trim(preg_replace('/^\d+\s*[.)]\s*/', '', trim($p))); if ($p === '') { continue; }
                    $cur['products'][] = $p; $lines[] = [$ym, $oid, mb_strtolower(preg_replace('/\s+/', ' ', $p)), $p === trim($prod) ? $cg : 0.0];
                }
                if ($prod === '' && $cg > 0) { $lines[] = [$ym, $oid, 'unnamed item', $cg]; }
            }
        }
        $flush();
    }

    // 3. influencer list (Affiliate 12M Analysis): code, Instagram, stack and commission flags
    $codes = []; $aff = null;
    foreach ($tabs as $t) { if (str_contains(strtolower((string)$t['name']), 'affiliate')) { $aff = $t['rows']; break; } }
    foreach ($aff ?? [] as $i => $r) {
        if ($i === 0) { continue; }
        $code = strtolower(preg_replace('/\s+/', '', $cell($r, 1))); if ($code === '') { continue; }
        $link = $cell($r, 0); $codes[$code] = ['disp' => $cell($r, 1), 'name' => '', 'insta' => preg_match('~^(https?://)?(www\.)?instagram\.com~i', $link) ? mb_substr($link, 0, 200) : '', 'stack' => strtolower($cell($r, 5)), 'comm' => strtolower($cell($r, 6))];
    }
    // names from the code-orders and payments tabs (names only)
    foreach ($tabs as $t) {
        $nm = strtolower(trim((string)$t['name']));
        if ($nm === 'code orders') { foreach ($t['rows'] as $i => $r) { $code = strtolower(preg_replace('/\s+/', '', $cell($r, 2))); if ($i > 0 && $code !== '' && $cell($r, 1) !== '') { $codes[$code]['name'] = $cell($r, 1); $codes[$code]['disp'] ??= $cell($r, 2); } } }
    }
    $pays = []; $blk = 'Payments';
    foreach ($tabs as $t) {
        if (strtolower(trim((string)$t['name'])) !== 'payments') { continue; }
        foreach ($t['rows'] as $r) {
            $c0 = $cell($r, 0);
            if (strtolower($c0) === 'name') { continue; }
            if ($c0 !== '' && $cell($r, 1) === '' && count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 1) { $blk = ucfirst($c0); continue; }
            $code = strtolower(preg_replace('/\s+/', '', $cell($r, 1)));
            if ($c0 === '' || $code === '' || $num($r[2] ?? 0) <= 0) { continue; }
            $paid = 0; foreach (array_slice($r, 5) as $x) { if (preg_match('/^done$/i', trim((string)$x))) { $paid = 1; } }
            $pays[] = [$blk, $c0, $code, $num($r[2] ?? 0), $num($r[3] ?? 0), $num($r[4] ?? 0), $paid];
            $codes[$code]['name'] = $c0; $codes[$code]['disp'] ??= $cell($r, 1);
        }
    }
    foreach ($codeSeen as $k => $orig) { $codes[$k]['disp'] ??= $orig; $codes[$k] += ['name' => '', 'insta' => '', 'stack' => '', 'comm' => '']; }

    // 4. performance (sales and spend by month, Sep 2025 onwards)
    $perf = [];
    foreach ($tabs as $t) {
        if (strtolower(trim((string)$t['name'])) !== 'performance') { continue; }
        $y = 2025; $m0 = 0;
        foreach ($t['rows'] as $r) {
            $mon = $mn($cell($r, 1)); if ($mon < 1) { continue; }
            if ($m0 && $mon < $m0) { $y++; } $m0 = $mon;
            $s = $num($r[2] ?? 0); $sp = $num($r[3] ?? 0);
            if ($s > 0 || $sp > 0) { $perf[sprintf('%04d-%02d', $y, $mon)] = [$s, $sp]; }
        }
    }
    // 5. barter (names, codes, products, reel links only)
    $barter = []; $grp = '';
    foreach ($tabs as $t) {
        if (strtolower(trim((string)$t['name'])) !== 'barter') { continue; }
        foreach ($t['rows'] as $i => $r) {
            if ($i === 0) { continue; }
            $c0 = $cell($r, 0);
            if ($c0 !== '' && $cell($r, 1) === '' && $cell($r, 2) === '') { $grp = ucfirst($c0); continue; }
            if ($c0 === '' || $cell($r, 1) === '') { continue; }
            $reel = $cell($r, 6); if ($reel !== '' && !preg_match('~^(https?://|www\.)~i', $reel)) { $reel = ''; }
            $barter[] = [$grp, mb_substr($c0, 0, 80), mb_substr($cell($r, 1), 0, 40), mb_substr($cell($r, 2), 0, 200), mb_substr(preg_replace('/\s+/', ' ', $cell($r, 5)), 0, 160), mb_substr($reel, 0, 200)];
        }
    }

    $pdo = db(); $pdo->beginTransaction();
    try {
        foreach (['inf_order', 'inf_line', 'inf_code', 'inf_perf', 'inf_pay', 'inf_barter', 'inf_meta'] as $tb) { $pdo->exec("DELETE FROM $tb"); }
        $io = $pdo->prepare('INSERT INTO inf_order (month, day, oid, code, amount, status, kind, value, cogs, ship, products) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($orders as $o) { if ($o['kind'] === 'skip') { continue; } $io->execute([$o['month'], $o['day'], $o['oid'], $o['code'], $o['amount'], $o['status'], $o['kind'], $o['value'], $o['cogs'], $o['ship'], mb_substr(implode('; ', $o['products']), 0, 300)]); }
        $il = $pdo->prepare('INSERT INTO inf_line (month, oid, product, cogs) VALUES (?,?,?,?)'); foreach ($lines as $l) { $il->execute([$l[0], $l[1], mb_substr($l[2], 0, 100), $l[3]]); }
        $ic = $pdo->prepare('INSERT OR REPLACE INTO inf_code (code, disp, name, insta, stack, comm) VALUES (?,?,?,?,?,?)'); foreach ($codes as $k => $c) { $ic->execute([$k, $c['disp'] ?? $k, $c['name'] ?? '', $c['insta'] ?? '', $c['stack'] ?? '', $c['comm'] ?? '']); }
        $ip = $pdo->prepare('INSERT INTO inf_perf (month, sales, spend) VALUES (?,?,?)'); foreach ($perf as $ym => $p) { $ip->execute([$ym, $p[0], $p[1]]); }
        $iy = $pdo->prepare('INSERT INTO inf_pay (block, name, code, total, net, comm, paid) VALUES (?,?,?,?,?,?,?)'); foreach ($pays as $p) { $iy->execute($p); }
        $ib = $pdo->prepare('INSERT INTO inf_barter (grp, name, code, insta, product, reel) VALUES (?,?,?,?,?,?)'); foreach ($barter as $b) { $ib->execute($b); }
        $pdo->prepare('INSERT OR REPLACE INTO inf_meta (k, v) VALUES (?,?)')->execute(['synced_at', date('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return 'Read ' . count($orders) . ' influencer orders across ' . count($cand) . ' months, ' . count($codes) . ' codes, ' . count($pays) . ' payouts, ' . count($barter) . ' barter rows.';
}
