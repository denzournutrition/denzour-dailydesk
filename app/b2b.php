<?php
defined('DD') or exit;

/* Bhanu credit offline: the distributors workbook (outstanding, orders, collections, follow-ups, legal cases, prospects). */

const B2B_SHEET_ID = '1Z_WIUL10jlw0yECQXwZhVrvRYzD6VjjwmaHYBfPj774';

function b2b_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS b2b_dist (k TEXT PRIMARY KEY, name TEXT NOT NULL, outstanding REAL NOT NULL DEFAULT 0, flags TEXT NOT NULL DEFAULT '', case_note TEXT NOT NULL DEFAULT '',
        contact TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', purpose TEXT NOT NULL DEFAULT '', next_date TEXT NOT NULL DEFAULT '', next_text TEXT NOT NULL DEFAULT '',
        last_contact TEXT NOT NULL DEFAULT '', first_contact TEXT NOT NULL DEFAULT '', remark TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS b2b_order (month TEXT NOT NULL, k TEXT NOT NULL, amount REAL NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS b2b_coll (day TEXT NOT NULL, k TEXT NOT NULL, amount REAL NOT NULL DEFAULT 0, raw TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS b2b_lead (src TEXT NOT NULL, name TEXT NOT NULL, area TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '',
        contact TEXT NOT NULL DEFAULT '', remark TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS b2b_meta (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '')");
    try {
        if (!$pdo->query("SELECT 1 FROM settings WHERE k = 'seed_b2b_sheet'")->fetchColumn()) {
            $has = $pdo->prepare('SELECT 1 FROM gsync_links WHERE sheet_id = ?'); $has->execute([B2B_SHEET_ID]);
            if (!$has->fetchColumn()) {
                $pdo->prepare("INSERT INTO gsync_links (label, kind, user_id, sheet_id, url, every_min, created_at, month) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(['Distributors (Bhanu credit offline)', 'b2b', 0, B2B_SHEET_ID, 'https://docs.google.com/spreadsheets/d/' . B2B_SHEET_ID . '/edit', 5, date('Y-m-d H:i:s'), '']);
            }
            $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('seed_b2b_sheet', '1')");
        }
    } catch (Throwable $e) { /* never block start-up */ }
}

function b2b_key(string $n): string { return strtolower(preg_replace('/[^a-z0-9]+/i', '', $n)); }

/** "KRSNA (OM P VERMA ), oddisa" => "KRSNA"; "Amutham Agencies, coimbaore" => "Amutham Agencies". */
function b2b_base(string $n): string
{
    $n = preg_replace('/\([^)]*\)/', ' ', $n);
    $n = explode(',', $n)[0];
    return trim(preg_replace('/\s+/', ' ', $n));
}

/** The master name that a written name belongs to (typos, extra words, city suffixes), or the cleaned name itself. */
function b2b_match(string $name, array $canon): string
{
    $kr = b2b_key(b2b_base($name));
    if ($kr === '') { return ''; }
    $best = null; $bd = 99;
    foreach ($canon as $kc => $_) {
        if ($kc === $kr) { return $kc; }
        $d = 99; $lc = strlen($kc); $lr = strlen($kr);
        if ($lc >= 5 && $lr >= 5 && (str_starts_with($kr, $kc) || str_starts_with($kc, $kr))) { $d = 1; }
        elseif ($lc >= 6 && $lr >= 6) { $lv = levenshtein($kc, $kr); if ($lv <= max(2, (int)floor(min($lc, $lr) * 0.2))) { $d = $lv; } }
        if ($d < $bd) { $bd = $d; $best = $kc; }
    }
    return $best ?? $kr;
}

function b2b_month_no(string $t): int
{
    static $m = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    return $m[strtolower(substr(trim($t), 0, 3))] ?? 0;
}

function b2b_ym(int $mon, ?int $year = null): string
{
    $t = today(); $y = (int)substr($t, 0, 4); $cm = (int)substr($t, 5, 2);
    if ($year === null || $year < 2000) { $year = $mon > $cm + 1 ? $y - 1 : $y; }
    return sprintf('%04d-%02d', $year, $mon);
}

/** A date written many ways in the sheet ("10/11", "26/10/2026", "9sep", "21 SEP", "23.10/2026") => Y-m-d or ''. */
function b2b_date(string $t): string
{
    $re = '/(\d{1,2})\s*[\/\.\-]\s*(\d{1,2})(?:\s*[\/\.\-]\s*(\d{2,4}))?|(\d{1,2})\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*/i';
    if (!preg_match($re, $t, $m)) { return ''; }
    if (($m[5] ?? '') !== '') { $d = (int)$m[4]; $mo = b2b_month_no($m[5]); $y = null; }
    else { $d = (int)$m[1]; $mo = (int)$m[2]; $y = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null; if ($y !== null && $y < 100) { $y += 2000; } if ($y !== null && $y > 2100) { $y = null; } }
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) { return ''; }
    $ym = b2b_ym($mo, $y);
    $day = sprintf('%s-%02d', $ym, $d);
    return checkdate($mo, $d, (int)substr($ym, 0, 4)) ? $day : '';
}

function b2b_import(string $path): string
{
    $tabs = [];
    foreach (xlsx_read($path) as $t) { $tabs[strtolower(trim((string)$t['name']))] = $t['rows']; }
    $find = function (string $needle) use ($tabs) { foreach ($tabs as $k => $r) { if (str_contains($k, $needle)) { return $r; } } return []; };
    $cell = fn(array $r, int $i) => trim((string)($r[$i] ?? ''));
    $amt = fn($v) => is_numeric($v) ? (float)$v : (float)preg_replace('/[^0-9.]/', '', (string)preg_replace('/\/-?|\s/', '', (string)$v));

    $dist = []; // key => row
    $touch = function (string $name) use (&$dist) {
        $base = b2b_base($name); $k = b2b_key($base);
        if ($k === '') { return ''; }
        $dist[$k] ??= ['name' => $base, 'outstanding' => 0.0, 'flags' => [], 'case_note' => '', 'contact' => '', 'phone' => '', 'status' => '', 'purpose' => '', 'next_date' => '', 'next_text' => '', 'last_contact' => '', 'first_contact' => '', 'remark' => ''];
        return $k;
    };
    // 1. outstanding (clean: Distributor, Outstanding)
    foreach ($find('outstanding') as $i => $r) {
        $n = $cell($r, 0); if ($i === 0 && stripos($n, 'distributor') !== false) { continue; }
        if ($n === '') { continue; }
        $k = $touch($n); if ($k !== '') { $dist[$k]['outstanding'] = max(0.0, $amt($r[1] ?? 0)); }
    }
    // 2. follow-up tracker
    $fu = $find('follow'); $h = []; $hi = -1;
    foreach ($fu as $i => $r) { $names = array_map(fn($c) => strtolower(preg_replace('/[^a-z]+/i', '', (string)$c)), $r); if (in_array('distributor', $names, true)) { $h = array_flip($names); $hi = $i; break; } }
    $followN = 0; $due = 0;
    if ($hi >= 0) {
        foreach ($fu as $i => $r) {
            if ($i <= $hi) { continue; }
            $n = $cell($r, $h['distributor']); if ($n === '') { continue; }
            $canon = $dist; $k = b2b_match($n, $canon); if (!isset($dist[$k])) { $k = $touch($n); }
            $g = fn($f) => isset($h[$f]) ? trim((string)($r[$h[$f]] ?? '')) : '';
            $st = strtolower($g('status'));
            $status = $st === '' ? '' : (preg_match('/in\s*ac/', $st) ? 'Inactive' : (str_contains($st, 'active') ? 'Active' : (preg_match('/int[a-z]*rest/', $st) ? 'Interested' : ucfirst($st))));
            $rem = $g('remarks'); preg_match_all('/\d{1,2}\/\d{1,2}\/\d{2,4}/', $rem, $mm); $last = '';
            foreach ($mm[0] as $dt) { $d = b2b_date($dt); if ($d !== '' && $d > $last) { $last = $d; } }
            $first = b2b_date((string)($r[0] ?? '')); if ($last === '') { $last = $first; }
            $nx = $g('nextfollowup');
            $dist[$k]['contact'] = $g('contactperson'); $dist[$k]['phone'] = preg_replace('/\s+/', ' ', $g('phoneno')); $dist[$k]['status'] = $status; $dist[$k]['purpose'] = $g('purpose');
            $dist[$k]['next_text'] = $nx; $dist[$k]['next_date'] = preg_match('/\d{1,2}\s*[\/\-\.]\s*\d{1,2}/', $nx) ? b2b_date($nx) : ''; $dist[$k]['last_contact'] = $last; $dist[$k]['first_contact'] = $first;
            $dist[$k]['remark'] = mb_substr(trim(preg_replace('/\s+/', ' ', $g('nextaction') . ' ' . $g('discussion'))), 0, 200);
            $followN++;
        }
    }
    // 3. legal cases and blocked parties
    $legal = 0;
    foreach ($find('case party') as $i => $r) {
        if ($i < 2) { continue; }
        foreach ([[0, 1, 'MSME'], [7, 8, 'Sec 138']] as [$ci, $ni, $flag]) {
            $n = $cell($r, $ci); if ($n === '') { continue; }
            $k = b2b_match($n, $dist); if (!isset($dist[$k])) { $k = $touch($n); }
            if (!in_array($flag, $dist[$k]['flags'], true)) { $dist[$k]['flags'][] = $flag; $legal++; }
            $note = $cell($r, $ni); if ($note !== '' && $dist[$k]['case_note'] === '') { $dist[$k]['case_note'] = mb_substr($note, 0, 80); }
        }
    }
    foreach ($find('blocked') as $r) {
        $n = $cell($r, 7); if ($n === '' || strtolower($n) === 'blocked') { continue; }
        $k = b2b_match($n, $dist); if (!isset($dist[$k])) { $k = $touch($n); }
        if (!in_array('Blocked', $dist[$k]['flags'], true)) { $dist[$k]['flags'][] = 'Blocked'; }
    }
    // 4. orders by month (Order Sheet): month heading rows, then "Distributor ... order amount" with the amount in column G
    $orders = []; $cur = '';
    foreach ($find('order sheet') as $r) {
        $a = $cell($r, 0); $b = $cell($r, 1); $g = $r[6] ?? '';
        // a month heading can be a real date cell (Excel stores "August 07" as a number)
        foreach ([$a, $b] as $t) { if (preg_match('/^\d{5}$/', $t) && $amt($g) <= 0) { $ts = ((int)$t - 25569) * 86400; if ($ts > 0) { $cur = b2b_ym((int)gmdate('n', $ts), (int)gmdate('Y', $ts)); continue 2; } } }
        $hdr = null; foreach ([$a, $b] as $t) { if (preg_match('/^(?:month\s*)?(january|february|march|april|may|june|july|august|september|october|november|december)\b/i', trim($t, " ,"), $m)) { $hdr = $m[1]; } }
        if ($hdr !== null && $amt($g) <= 0) { $cur = b2b_ym(b2b_month_no($hdr)); continue; }
        if ($a === '' || $cur === '' || $amt($g) <= 0) { continue; }
        $k = b2b_match($a, $dist); if (!isset($dist[$k])) { $k = $touch($a); }
        $orders[] = [$cur, $k, $amt($g)];
    }
    // 5. collections (Monthly B2B Performance): amount in column F, "party dd/mm" in column G; the first August cell is a list in one cell
    $colls = [];
    $addColl = function (float $amount, string $text, string $fallbackMonth) use (&$colls, &$dist, $touch) {
        $text = trim(preg_replace('/\s+/', ' ', str_replace('"', '', $text)));
        if ($amount <= 0 || !preg_match('/[a-z]{3}/i', preg_replace('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/i', '', $text))) { return; }
        $day = b2b_date($text); if ($day === '') { $day = $fallbackMonth !== '' ? $fallbackMonth . '-01' : ''; }
        if ($day === '') { return; }
        $name = trim(preg_replace('/(\d{1,2}\s*[\/\.\-]\s*\d{1,2}(\s*[\/\.\-]\s*\d{2,4})?|\d{1,2}\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*(\s*\d{2,4})?|\d{1,2}\s*aug|\d{1,2}ug)/i', ' ', $text), " -./,");
        $k = b2b_match($name, $dist); if (!isset($dist[$k])) { $k = $touch($name); }
        if ($k !== '') { $colls[] = [$day, $k, $amount, mb_substr($text, 0, 80)]; }
    };
    $sec = '';
    foreach ($find('monthly') as $i => $r) {
        if ($i === 0) { continue; }
        foreach ([0, 4] as $ci) { $t = trim($cell($r, $ci), " ,"); if (preg_match('/^(january|february|march|april|may|june|july|august|september|october|november|december)\b/i', $t, $m)) { $sec = b2b_ym(b2b_month_no($m[1])); } }
        $f = $r[5] ?? ''; $gtxt = $cell($r, 6);
        if (is_string($f) && str_contains($f, "\n")) {
            foreach (preg_split('/\R/', $f) as $line) {
                if (preg_match('/^\s*"*\s*([\d,\.]+)\s*(?:\/-)?\s*[-–—]+\s*(.+?)\s*"*$/u', $line, $m)) { $addColl($amt($m[1]), $m[2], $sec ?: b2b_ym(8)); }
            }
            continue;
        }
        if ($gtxt !== '') { $addColl($amt($f), $gtxt, $sec); }
    }
    // 6. pipeline: calling list (New Data), new prospects, Denzuu leads, samples, each row with its remark and call status
    $leads = [];
    $phoneOf = function (array $cells) { foreach ($cells as $c) { $d = preg_replace('/\D+/', '', (string)$c); if (strlen($d) >= 10 && strlen($d) <= 13) { return substr($d, -10); } } return ''; };
    $isText = fn($v) => $v !== '' && !preg_match('/^[\d\s\-+.,]+$/', $v) && $v !== '—';
    foreach ($find('new data') as $i => $r) {
        $n = $cell($r, 0); if ($i === 0 || $n === '') { continue; }
        $rem = $cell($r, 3); $leads[] = ['calling', $n, $cell($r, 1), $phoneOf([$cell($r, 2)]), '', '', $rem, b2b_lead_status($rem)];
    }
    $callMode = false;
    foreach ($find('new prospect') as $i => $r) {
        $n = $cell($r, 0); if ($i === 0 || $n === '') { continue; }
        if (preg_match('/^firm\s*name$/i', $n)) { $callMode = true; continue; }   // the calling list sits lower in the same tab, under its own header
        if ($callMode) { $rem = $cell($r, 3); $leads[] = ['calling', $n, $cell($r, 1), $phoneOf([$cell($r, 2)]), '', '', $rem, b2b_lead_status($rem)]; continue; }
        $ph = $phoneOf([$cell($r, 2), $cell($r, 1), $cell($r, 3)]); $area = $isText($cell($r, 1)) ? $cell($r, 1) : '';
        $d3 = $cell($r, 3); $addr = mb_strlen($d3) > 40 ? $d3 : ''; $rem = $cell($r, 5);
        if ($addr === '' && $isText($d3)) {   // a short text in column D is either a remark ("invalid contact number") or the city ("Delhi")
            if (b2b_lead_status($d3) !== 'Other') { if ($rem === '') { $rem = $d3; } } elseif ($area === '') { $area = $d3; }
        }
        $leads[] = ['prospect', $n, $area, $ph, $addr, $isText($cell($r, 4)) ? $cell($r, 4) : '', $rem, b2b_lead_status($rem)];
    }
    foreach ($find('denzu lead') as $i => $r) {
        if ($i < 2) { continue; }
        $ph = $phoneOf([$cell($r, 0)]); if ($ph === '') { continue; }
        $leads[] = ['denzuu', $cell($r, 1) !== '' ? $cell($r, 1) : 'Lead ' . $ph, '', $ph, $cell($r, 2), '', '', 'Not called'];
    }
    foreach ($find('samples') as $i => $r) {
        $n = $cell($r, 0); if ($i === 0 || $n === '') { continue; }
        $leads[] = ['sample', $n, '', '', $cell($r, 1), '', 'Sample to send', 'Sample'];
    }
    $meta = ['prospects' => 0, 'newdata' => 0, 'samples' => 0, 'leads' => 0, 'hot' => 0];
    foreach ($leads as $l) { $meta[['calling' => 'newdata', 'prospect' => 'prospects', 'denzuu' => 'leads', 'sample' => 'samples'][$l[0]]]++; if ($l[7] === 'Interested') { $meta['hot']++; } }

    $pdo = db(); $pdo->beginTransaction();
    try {
        foreach (['b2b_dist', 'b2b_order', 'b2b_coll', 'b2b_meta', 'b2b_lead'] as $t) { $pdo->exec("DELETE FROM $t"); }
        $ins = $pdo->prepare('INSERT INTO b2b_dist (k, name, outstanding, flags, case_note, contact, phone, status, purpose, next_date, next_text, last_contact, first_contact, remark) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($dist as $k => $d) { $ins->execute([$k, $d['name'], $d['outstanding'], implode(',', $d['flags']), $d['case_note'], $d['contact'], $d['phone'], $d['status'], $d['purpose'], $d['next_date'], $d['next_text'], $d['last_contact'], $d['first_contact'], $d['remark']]); }
        $io = $pdo->prepare('INSERT INTO b2b_order (month, k, amount) VALUES (?,?,?)'); foreach ($orders as $o) { $io->execute($o); }
        $ic = $pdo->prepare('INSERT INTO b2b_coll (day, k, amount, raw) VALUES (?,?,?,?)'); foreach ($colls as $c) { $ic->execute($c); }
        $il = $pdo->prepare('INSERT INTO b2b_lead (src, name, area, phone, address, contact, remark, status) VALUES (?,?,?,?,?,?,?,?)'); foreach ($leads as $l) { $il->execute([$l[0], mb_substr($l[1], 0, 120), mb_substr($l[2], 0, 60), $l[3], mb_substr($l[4], 0, 200), mb_substr($l[5], 0, 60), mb_substr($l[6], 0, 300), $l[7]]); }
        $im = $pdo->prepare('INSERT INTO b2b_meta (k, v) VALUES (?,?)'); foreach ($meta + ['synced_at' => date('Y-m-d H:i:s')] as $k => $v) { $im->execute([$k, (string)$v]); }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    b2b_sync_channel();
    return 'Read ' . count($dist) . ' distributors, ' . count($orders) . ' orders, ' . count($colls) . ' collections, ' . $followN . ' follow-ups.';
}

function b2b_has_data(): bool { return (int)db()->query('SELECT COUNT(*) FROM b2b_dist')->fetchColumn() > 0; }

/**
 * The offline Bhanu's order value (Order Sheet, by month) becomes the "Bhanu credit offline" channel on the Sales overview.
 * The sheet has no order dates, so a month's value is spread evenly over its days (up to today for the current month).
 * Days that already have uploaded invoices or a hand-typed entry are left alone.
 */
function b2b_sync_channel(): void
{
    $pdo = db(); $cid = offline_channel_id('bhanu'); $today = today();
    $pdo->prepare("DELETE FROM channel_daily WHERE channel_id = ? AND note = 'From Distributors sheet'")->execute([$cid]);
    $has = $pdo->prepare('SELECT 1 FROM channel_daily WHERE channel_id = ? AND day = ?');
    $ins = $pdo->prepare("INSERT INTO channel_daily (channel_id, day, sales, orders, user_id, note, updated_at) VALUES (?,?,?,?,0,'From Distributors sheet',?)");
    foreach ($pdo->query('SELECT month, SUM(amount) s, COUNT(*) n FROM b2b_order GROUP BY month')->fetchAll() as $m) {
        $first = $m['month'] . '-01'; $last = min(date('Y-m-t', strtotime($first)), $today);
        if ($last < $first) { continue; }
        $days = []; for ($d = $first; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $has->execute([$cid, $d]); if (!$has->fetchColumn()) { $days[] = $d; } }
        if (!$days) { continue; }
        $each = round((float)$m['s'] / count($days), 2); $rest = round((float)$m['s'] - $each * (count($days) - 1), 2);
        foreach ($days as $i => $d) { $ins->execute([$cid, $d, $i === count($days) - 1 ? $rest : $each, $i === 0 ? (int)$m['n'] : 0, date('Y-m-d H:i:s')]); }
    }
}

/** What a calling remark means: Interested, Follow up, Details shared, No answer, Not interested, Sample, Existing, Blocked, Language, Other, or Not called. */
function b2b_lead_status(string $remark): string
{
    $r = strtolower(trim($remark));
    if ($r === '') { return 'Not called'; }
    if (preg_match('/blocked/', $r)) { return 'Blocked'; }
    if (preg_match('/invalid|wrong (deails|details|number|no)/', $r)) { return 'Invalid number'; }
    if (preg_match('/already (have|par|cusomer|customer)|alread|part in his area/', $r)) { return 'Existing'; }
    if (preg_match('/no[t]?\s*(want|wan |int[a-z]*rest|intr|intres|inres)|not intr|dont want|don.t want|no intrested|not intrested|not intrresed|inresed/', $r)) { return 'Not interested'; }
    if (preg_match('/frenchie|franchi|compan[a-z]* outlet|company outlet/', $r)) { return 'Company outlet'; }
    if (preg_match('/good prospect|ready to dispatch|interested|intrested/', $r)) { return 'Interested'; }
    if (preg_match('/sample/', $r)) { return 'Sample'; }
    if (preg_match('/call not|call no|no received|no recieved|not received|not recei|no aended|switch|swich|call cut|call cu\b|not available|incoming|busy|bus on|not pick|no answer|not connect/', $r)) { return 'No answer'; }
    if (preg_match('/details? share|deails share|whatsapp|whasapp/', $r)) { return 'Details shared'; }
    if (preg_match('/process|follow|later|ime laer|callback|call back|tomorrow|next week|ma be|may be/', $r)) { return 'Follow up'; }
    if (preg_match('/language|tamil|telugu|kannada/', $r)) { return 'Language'; }
    if ($r === 'done') { return 'Done'; }
    return 'Other';
}
