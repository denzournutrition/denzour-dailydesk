<?php
defined('DD') or exit;
/* Upload a Meta Ads Manager or Google Ads report (CSV or Excel, broken down by Day) into ads_daily.
   Columns are found by their header names, so small differences between exports do not matter. */

function ad_hdr(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

function ad_num($v): float
{
    if (is_int($v) || is_float($v)) { return (float)$v; }
    $s = trim((string)$v);
    if ($s === '' || $s === '--' || $s === '-') { return 0.0; }
    $s = preg_replace('/[^0-9.\-]/', '', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

function ad_date($v): string
{
    if (is_int($v) || is_float($v)) {
        return ($v > 30000 && $v < 70000) ? gmdate('Y-m-d', (int)round(($v - 25569) * 86400)) : '';
    }
    $s = trim((string)$v);
    if ($s === '') { return ''; }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) { return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : ''; }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $s, $m)) {
        $y = (int)$m[3]; if ($y < 100) { $y += 2000; }
        return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : '';
    }
    if (preg_match('/^[A-Za-z]{3,9}\.? \d{1,2},? \d{4}$|^\d{1,2} [A-Za-z]{3,9}\.?,? \d{4}$/', $s)) {
        $t = strtotime($s); return $t ? date('Y-m-d', $t) : '';
    }
    return '';
}

/** Which header is which. Returns field => column index. */
function ad_cols(array $row): array
{
    $h = array_map(fn($c) => ad_hdr((string)$c), $row);
    $pick = function (array $pats, array $skip = []) use ($h): ?int {
        foreach ($pats as $p) {
            foreach ($h as $i => $v) {
                if ($v !== '' && !in_array($i, $skip, true) && preg_match($p, $v)) { return $i; }
            }
        }
        return null;
    };
    $c = [];
    $c['day'] = $pick(['/^day$/', '/^date$/', '/^reportingstarts$/', '/^dayofmonth$/']);
    $c['campaign'] = $pick(['/^campaignname$/', '/^campaign$/']);
    $c['adset'] = $pick(['/^adsetname$/', '/^adgroupname$/', '/^adgroup$/']);
    $c['ad'] = $pick(['/^adname$/']);
    $c['spend'] = $pick(['/^amountspent/', '/^cost$/', '/^costinr$/', '/^spend/', '/^cost(?!per)/']);
    $c['impr'] = $pick(['/^impressions$/', '/^impr$/']);
    $c['clicks'] = $pick(['/^linkclicks$/', '/^clicksall$/', '/^clicks$/']);
    $c['pur'] = $pick(['/^purchases$/', '/^websitepurchases$/', '/^purchase$/', '/^results$/', '/^conversions$/', '/^allconv$/', '/^allconversions$/']);
    $c['sales'] = $pick(['/^purchasesconversionvalue$/', '/^websitepurchasesconversionvalue$/', '/^purchaseconversionvalue$/', '/^convvalue$/', '/^conversionvalue$/', '/^allconvvalue$/', '/conversionvalue$/', '/convvalue$/']);
    $c['roas'] = $pick(['/^purchaseroas/', '/^websitepurchaseroas/', '/^roas/', '/^convvaluecost$/']);
    return $c;
}

/**
 * @return array{platform:string,rows:int,from:string,to:string,spend:float,sales:float,skipped:int}
 */
function ads_import_file(string $path, string $name, string $platformPick = 'auto'): array
{
    $head = (string)@file_get_contents($path, false, null, 0, 4);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $isX = $ext === 'xlsx' || $head === "PK\x03\x04";
    $isTsv = false;
    if ($isX) {
        $tabs = xlsx_read($path);
    } else {
        $first = (string)@file_get_contents($path, false, null, 0, 4000);
        if (strpos($first, "\xFF\xFE") === 0 || strpos($first, "\xFE\xFF") === 0) {   // Google Ads sometimes saves UTF-16 tab separated
            $raw = mb_convert_encoding((string)file_get_contents($path), 'UTF-8', 'UTF-16'); $tmp = tempnam(sys_get_temp_dir(), 'ads'); file_put_contents($tmp, $raw); $path = $tmp; $isTsv = true;
        } elseif (substr_count($first, "\t") > substr_count($first, ',') * 2) { $isTsv = true; }
        if ($isTsv) {
            $rows = []; $fh = fopen($path, 'r');
            while (($r = fgetcsv($fh, 0, "\t", '"', '')) !== false) { if (array_filter($r, fn($x) => trim((string)$x) !== '')) { $rows[] = $r; } }
            fclose($fh); if (isset($rows[0][0])) { $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$rows[0][0]); }
            $tabs = [['name' => $name, 'rows' => $rows]];
        } else { $tabs = csv_read($path, $name); }
    }
    $best = null;
    foreach ($tabs as $t) {
        foreach (array_slice($t['rows'], 0, 15, true) as $i => $r) {
            $c = ad_cols($r);
            if ($c['spend'] !== null && ($c['campaign'] !== null || $c['day'] !== null)) { $best = ['rows' => $t['rows'], 'hi' => $i, 'c' => $c]; break 2; }
        }
    }
    if (!$best) { throw new RuntimeException('Could not find the columns. The file needs a header row with at least Campaign name and Amount spent (Meta) or Campaign and Cost (Google).'); }
    $c = $best['c'];
    if ($c['day'] === null) { throw new RuntimeException('This report has no date column. In Meta Ads Manager, set Breakdown to Time, Day before exporting. In Google Ads, add Segment, Time, Day.'); }
    $hdrText = strtolower(implode(' ', array_map('strval', $best['rows'][$best['hi']])));
    $platform = $platformPick === 'Meta' || $platformPick === 'Google' ? $platformPick
        : ((strpos($hdrText, 'amount spent') !== false || strpos($hdrText, 'ad set name') !== false || strpos($hdrText, 'link clicks') !== false) ? 'Meta' : 'Google');
    $today = today(); $agg = []; $skipped = 0;
    foreach (array_slice($best['rows'], $best['hi'] + 1) as $r) {
        $day = ad_date($r[$c['day']] ?? '');
        if ($day === '' || $day > add_days($today, 1)) { $skipped++; continue; }
        $camp = $c['campaign'] !== null ? mb_substr(trim((string)($r[$c['campaign']] ?? '')), 0, 200) : '';
        if ($camp === '' && $c['campaign'] !== null) { $skipped++; continue; }
        $adset = $c['adset'] !== null && $platform === 'Meta' ? mb_substr(trim((string)($r[$c['adset']] ?? '')), 0, 200) : '';
        $ad = $c['ad'] !== null && $platform === 'Meta' ? mb_substr(trim((string)($r[$c['ad']] ?? '')), 0, 200) : '';
        $spend = ad_num($r[$c['spend']] ?? 0);
        $sales = $c['sales'] !== null ? ad_num($r[$c['sales']] ?? 0) : ($c['roas'] !== null ? ad_num($r[$c['roas']] ?? 0) * $spend : 0.0);
        $k = $day . "\x1f" . $camp . "\x1f" . $adset . "\x1f" . $ad;
        $a = $agg[$k] ?? ['day' => $day, 'camp' => $camp, 'adset' => $adset, 'ad' => $ad, 'spend' => 0.0, 'imp' => 0.0, 'clk' => 0.0, 'pur' => 0.0, 'sales' => 0.0];
        $a['spend'] += $spend; $a['sales'] += $sales;
        $a['imp'] += $c['impr'] !== null ? ad_num($r[$c['impr']] ?? 0) : 0;
        $a['clk'] += $c['clicks'] !== null ? ad_num($r[$c['clicks']] ?? 0) : 0;
        $a['pur'] += $c['pur'] !== null ? ad_num($r[$c['pur']] ?? 0) : 0;
        $agg[$k] = $a;
    }
    if (!$agg) { throw new RuntimeException('No daily rows were found. Check that the Day column has dates.'); }
    $days = array_column($agg, 'day'); $from = min($days); $to = max($days);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ads_daily WHERE platform = ? AND day BETWEEN ? AND ?')->execute([$platform, $from, $to]);
        $ins = $pdo->prepare('INSERT OR REPLACE INTO ads_daily (platform, day, campaign, adset, ad, spend, impressions, clicks, purchases, sales) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($agg as $a) { $ins->execute([$platform, $a['day'], $a['camp'], $a['adset'], $a['ad'], round($a['spend'], 2), (int)round($a['imp']), (int)round($a['clk']), $a['pur'], round($a['sales'], 2)]); }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return ['platform' => $platform, 'rows' => count($agg), 'from' => $from, 'to' => $to, 'spend' => array_sum(array_column($agg, 'spend')), 'sales' => array_sum(array_column($agg, 'sales')), 'skipped' => $skipped];
}
