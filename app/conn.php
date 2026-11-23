<?php
defined('DD') or exit;

/* =====================================================================
   Connections: Meta Ads, Google Ads and Instagram, read straight from the platforms.
   Keys are typed by the admin into Admin > Connections and kept encrypted.
   ===================================================================== */

const CONN_KINDS = [
    'meta_ads'   => ['Meta Ads', 'Spend, sales, ROAS and creatives from Facebook and Instagram ads'],
    'google_ads' => ['Google Ads', 'Spend, conversions and ROAS by campaign'],
    'instagram'  => ['Instagram', 'Followers, reach and how each post performed'],
];

/** field => [label, secret?, hint, default] */
const CONN_FIELDS = [
    'meta_ads' => [
        'account_id'  => ['Ad account ID', false, 'Looks like act_1234567890 (Ads Manager, top left). The act_ part is optional.', ''],
        'api_version' => ['API version', false, 'Change this if Meta retires the version.', 'v23.0'],
        'token'       => ['Access token', true, 'A token with the ads_read permission.', ''],
    ],
    'google_ads' => [
        'customer_id'       => ['Customer ID', false, '10 digits, with or without dashes.', ''],
        'login_customer_id' => ['Manager (MCC) ID', false, 'Only if you reach this account through a manager account. Otherwise leave empty.', ''],
        'api_version'       => ['API version', false, 'Change this if Google retires the version.', 'v22'],
        'client_id'         => ['OAuth client ID', true, 'From Google Cloud Console > Credentials.', ''],
        'client_secret'     => ['OAuth client secret', true, '', ''],
        'refresh_token'     => ['Refresh token', true, 'Created once when you approve access to Google Ads.', ''],
        'developer_token'   => ['Developer token', true, 'Only if your Google Ads API access still asks for one.', ''],
    ],
    'instagram' => [
        'ig_user_id'  => ['Instagram account ID', false, 'The numeric ID of the business or creator account.', ''],
        'api_version' => ['API version', false, 'Change this if Meta retires the version.', 'v23.0'],
        'token'       => ['Access token', true, 'Needs instagram_basic and instagram_manage_insights.', ''],
    ],
];

function conn_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS conn (
        kind TEXT PRIMARY KEY, cfg TEXT NOT NULL DEFAULT '{}', secrets TEXT NOT NULL DEFAULT '',
        enabled INTEGER NOT NULL DEFAULT 1, every_min INTEGER NOT NULL DEFAULT 180,
        last_run TEXT NOT NULL DEFAULT '', last_ok INTEGER NOT NULL DEFAULT 0, last_msg TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ads_daily (
        platform TEXT NOT NULL, day TEXT NOT NULL, campaign TEXT NOT NULL DEFAULT '', adset TEXT NOT NULL DEFAULT '', ad TEXT NOT NULL DEFAULT '',
        spend REAL NOT NULL DEFAULT 0, impressions INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0,
        purchases REAL NOT NULL DEFAULT 0, sales REAL NOT NULL DEFAULT 0,
        PRIMARY KEY (platform, day, campaign, adset, ad))");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ads_day ON ads_daily(day)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS ig_posts (
        media_id TEXT PRIMARY KEY, posted_at TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT '', permalink TEXT NOT NULL DEFAULT '', caption TEXT NOT NULL DEFAULT '',
        likes INTEGER NOT NULL DEFAULT 0, comments INTEGER NOT NULL DEFAULT 0, reach INTEGER NOT NULL DEFAULT 0, views INTEGER NOT NULL DEFAULT 0,
        shares INTEGER NOT NULL DEFAULT 0, saves INTEGER NOT NULL DEFAULT 0, interactions INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ig_daily (day TEXT PRIMARY KEY, followers INTEGER NOT NULL DEFAULT 0, reach INTEGER NOT NULL DEFAULT 0)");
}

/* ---------- keeping keys safe ---------- */
function conn_key(): string
{
    $f = dirname((string)cfg('db_path')) . '/.htconn.key';
    if (!is_file($f)) {
        file_put_contents($f, random_bytes(32), LOCK_EX);
        @chmod($f, 0600);
    }
    $k = (string)file_get_contents($f);
    if (strlen($k) !== 32) { throw new RuntimeException('The key file for saved connections is damaged.'); }
    return $k;
}

function conn_encrypt(array $data): string
{
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt(json_encode($data), 'aes-256-gcm', conn_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}

function conn_decrypt(string $blob): array
{
    if ($blob === '') { return []; }
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) { return []; }
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', conn_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    $a = $p === false ? null : json_decode($p, true);
    return is_array($a) ? $a : [];
}

/** @return array{cfg:array,secrets:array,row:?array} */
function conn_get(string $kind): array
{
    $st = db()->prepare('SELECT * FROM conn WHERE kind = ?'); $st->execute([$kind]);
    $row = $st->fetch() ?: null;
    return ['cfg' => $row ? (json_decode((string)$row['cfg'], true) ?: []) : [], 'secrets' => $row ? conn_decrypt((string)$row['secrets']) : [], 'row' => $row];
}

/** Saves the typed values. An empty secret box keeps the saved secret. */
function conn_save(string $kind, array $post): void
{
    $cur = conn_get($kind);
    $cfg = $cur['cfg']; $sec = $cur['secrets'];
    foreach (CONN_FIELDS[$kind] as $f => [$label, $isSecret, $hint, $def]) {
        $v = trim((string)($post[$f] ?? ''));
        if ($isSecret) { if ($v !== '') { $sec[$f] = $v; } }
        else { $cfg[$f] = $v !== '' ? $v : $def; }
    }
    db()->prepare("INSERT INTO conn (kind, cfg, secrets) VALUES (?,?,?) ON CONFLICT(kind) DO UPDATE SET cfg = excluded.cfg, secrets = excluded.secrets")
        ->execute([$kind, json_encode($cfg), conn_encrypt($sec)]);
}

function conn_ready(string $kind): bool
{
    $c = conn_get($kind);
    foreach (CONN_FIELDS[$kind] as $f => [$label, $isSecret, $hint, $def]) {
        if ($f === 'api_version' || $f === 'login_customer_id' || $f === 'developer_token') { continue; }
        if (($isSecret ? ($c['secrets'][$f] ?? '') : ($c['cfg'][$f] ?? '')) === '') { return false; }
    }
    return true;
}

/* ---------- talking to the platforms ---------- */
/** @return array{0:int,1:?array,2:string} status, decoded JSON, raw body */
function conn_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!empty($GLOBALS['dd_http_mock']) && is_callable($GLOBALS['dd_http_mock'])) { return ($GLOBALS['dd_http_mock'])($method, $url, $headers, $body); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('Could not reach the platform: ' . $e); }
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    return [$code, is_array($j) ? $j : null, (string)$raw];
}

function conn_err(int $code, ?array $j, string $raw): string
{
    $m = $j['error']['message'] ?? ($j[0]['error']['message'] ?? ($j['error_description'] ?? ($j['error'] ?? null)));
    if (is_array($m)) { $m = json_encode($m); }
    return 'The platform said: ' . mb_substr($m !== null ? (string)$m : 'HTTP ' . $code . ' ' . trim(strip_tags($raw)), 0, 280);
}

/* ---------- Meta Ads ---------- */
function conn_run_meta_ads(): string
{
    $c = conn_get('meta_ads'); $v = $c['cfg']['api_version'] ?? 'v23.0';
    $acct = 'act_' . preg_replace('/^act_/i', '', trim((string)$c['cfg']['account_id']));
    $since = date('Y-m-d', strtotime('-45 days')); $until = today();
    $url = 'https://graph.facebook.com/' . rawurlencode($v) . '/' . rawurlencode($acct) . '/insights?' . http_build_query([
        'level' => 'ad', 'time_increment' => 1, 'limit' => 500, 'access_token' => $c['secrets']['token'],
        'fields' => 'date_start,campaign_name,adset_name,ad_name,spend,impressions,clicks,actions,action_values',
        'time_range' => json_encode(['since' => $since, 'until' => $until]),
    ]);
    $rows = []; $pages = 0;
    while ($url && $pages++ < 40) {
        [$code, $j, $raw] = conn_http('GET', $url);
        if ($code >= 400 || !is_array($j) || !isset($j['data'])) { throw new RuntimeException(conn_err($code, $j, $raw)); }
        foreach ($j['data'] as $r) { $rows[] = $r; }
        $url = $j['paging']['next'] ?? '';
    }
    $pick = function (array $list): float {
        $by = []; foreach ($list as $a) { $by[$a['action_type'] ?? ''] = (float)($a['value'] ?? 0); }
        return $by['omni_purchase'] ?? ($by['purchase'] ?? ($by['offsite_conversion.fb_pixel_purchase'] ?? 0.0));
    };
    $pdo = db(); $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM ads_daily WHERE platform = 'Meta' AND day BETWEEN ? AND ?")->execute([$since, $until]);
        $ins = $pdo->prepare("INSERT INTO ads_daily (platform, day, campaign, adset, ad, spend, impressions, clicks, purchases, sales) VALUES ('Meta',?,?,?,?,?,?,?,?,?)
            ON CONFLICT(platform, day, campaign, adset, ad) DO UPDATE SET spend = spend + excluded.spend, impressions = impressions + excluded.impressions, clicks = clicks + excluded.clicks, purchases = purchases + excluded.purchases, sales = sales + excluded.sales");
        foreach ($rows as $r) {
            $ins->execute([(string)$r['date_start'], (string)($r['campaign_name'] ?? ''), (string)($r['adset_name'] ?? ''), (string)($r['ad_name'] ?? ''),
                (float)($r['spend'] ?? 0), (int)($r['impressions'] ?? 0), (int)($r['clicks'] ?? 0), $pick($r['actions'] ?? []), $pick($r['action_values'] ?? [])]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return 'Saved ' . count($rows) . ' ad-days from ' . fmt_date($since) . ' to ' . fmt_date($until) . '.';
}

/* ---------- Google Ads ---------- */
function conn_run_google_ads(): string
{
    $c = conn_get('google_ads'); $cf = $c['cfg']; $s = $c['secrets']; $v = $cf['api_version'] ?? 'v22';
    [$code, $j, $raw] = conn_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => $s['client_id'], 'client_secret' => $s['client_secret'], 'refresh_token' => $s['refresh_token'], 'grant_type' => 'refresh_token']));
    if ($code >= 400 || empty($j['access_token'])) { throw new RuntimeException('Google would not sign in. ' . conn_err($code, $j, $raw)); }
    $cid = preg_replace('/\D/', '', (string)$cf['customer_id']);
    $h = ['Authorization: Bearer ' . $j['access_token'], 'Content-Type: application/json'];
    if (!empty($s['developer_token'])) { $h[] = 'developer-token: ' . $s['developer_token']; }
    if (!empty($cf['login_customer_id'])) { $h[] = 'login-customer-id: ' . preg_replace('/\D/', '', (string)$cf['login_customer_id']); }
    $since = date('Y-m-d', strtotime('-45 days')); $until = today();
    $gaql = "SELECT segments.date, campaign.name, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '$since' AND '$until'";
    [$code, $j, $raw] = conn_http('POST', 'https://googleads.googleapis.com/' . rawurlencode($v) . '/customers/' . $cid . '/googleAds:searchStream', $h, json_encode(['query' => $gaql]));
    if ($code >= 400 || !is_array($j)) { throw new RuntimeException(conn_err($code, $j, $raw)); }
    $batches = isset($j['results']) ? [$j] : $j;
    $pdo = db(); $pdo->beginTransaction(); $n = 0;
    try {
        $pdo->prepare("DELETE FROM ads_daily WHERE platform = 'Google' AND day BETWEEN ? AND ?")->execute([$since, $until]);
        $ins = $pdo->prepare("INSERT INTO ads_daily (platform, day, campaign, adset, ad, spend, impressions, clicks, purchases, sales) VALUES ('Google',?,?,'','',?,?,?,?,?)
            ON CONFLICT(platform, day, campaign, adset, ad) DO UPDATE SET spend = spend + excluded.spend, impressions = impressions + excluded.impressions, clicks = clicks + excluded.clicks, purchases = purchases + excluded.purchases, sales = sales + excluded.sales");
        foreach ($batches as $b) {
            foreach ($b['results'] ?? [] as $r) {
                $m = $r['metrics'] ?? [];
                $ins->execute([(string)($r['segments']['date'] ?? ''), (string)($r['campaign']['name'] ?? ''), round(((float)($m['costMicros'] ?? 0)) / 1e6, 2),
                    (int)($m['impressions'] ?? 0), (int)($m['clicks'] ?? 0), (float)($m['conversions'] ?? 0), (float)($m['conversionsValue'] ?? 0)]);
                $n++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return 'Saved ' . $n . ' campaign-days from ' . fmt_date($since) . ' to ' . fmt_date($until) . '.';
}

/* ---------- Instagram ---------- */
function conn_run_instagram(): string
{
    $c = conn_get('instagram'); $v = $c['cfg']['api_version'] ?? 'v23.0'; $id = preg_replace('/\D/', '', (string)$c['cfg']['ig_user_id']); $tok = $c['secrets']['token'];
    $base = 'https://graph.facebook.com/' . rawurlencode($v) . '/';
    $get = function (string $path, array $q) use ($base, $tok): array {
        [$code, $j, $raw] = conn_http('GET', $base . $path . '?' . http_build_query($q + ['access_token' => $tok]));
        if ($code >= 400 || !is_array($j)) { throw new RuntimeException(conn_err($code, $j, $raw)); }
        return $j;
    };
    $pdo = db();
    $acct = $get($id, ['fields' => 'followers_count,media_count']);
    $pdo->prepare('INSERT INTO ig_daily (day, followers, reach) VALUES (?,?,0) ON CONFLICT(day) DO UPDATE SET followers = excluded.followers')->execute([today(), (int)($acct['followers_count'] ?? 0)]);
    try {
        $ri = $get($id . '/insights', ['metric' => 'reach', 'period' => 'day', 'since' => strtotime('-28 days'), 'until' => time()]);
        $up = $pdo->prepare('INSERT INTO ig_daily (day, followers, reach) VALUES (?,0,?) ON CONFLICT(day) DO UPDATE SET reach = excluded.reach');
        foreach ($ri['data'][0]['values'] ?? [] as $pt) { $up->execute([substr((string)$pt['end_time'], 0, 10), (int)$pt['value']]); }
    } catch (Throwable $e) { /* account reach is a bonus; posts matter more */ }
    $limit = date('Y-m-d', strtotime('-60 days')); $posts = []; $q = ['fields' => 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count', 'limit' => 50]; $pages = 0;
    do {
        $j = $get($id . '/media', $q);
        foreach ($j['data'] ?? [] as $m) { if (substr((string)$m['timestamp'], 0, 10) >= $limit) { $posts[] = $m; } }
        $after = $j['paging']['cursors']['after'] ?? null; $more = !empty($j['paging']['next']) && $after && count($posts) < 80;
        $q['after'] = $after;
    } while ($more && $pages++ < 3);
    $up = $pdo->prepare("INSERT INTO ig_posts (media_id, posted_at, type, permalink, caption, likes, comments, reach, views, shares, saves, interactions, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(media_id) DO UPDATE SET likes = excluded.likes, comments = excluded.comments, reach = excluded.reach, views = excluded.views, shares = excluded.shares, saves = excluded.saves, interactions = excluded.interactions, updated_at = excluded.updated_at, caption = excluded.caption, permalink = excluded.permalink");
    $done = 0;
    foreach (array_slice($posts, 0, 60) as $m) {
        $vals = [];
        foreach ([['reach', 'saved', 'shares', 'views', 'total_interactions'], ['reach', 'saved', 'shares'], ['reach']] as $metrics) {
            [$code, $ji] = conn_http('GET', $base . $m['id'] . '/insights?' . http_build_query(['metric' => implode(',', $metrics), 'access_token' => $tok]));
            if ($code < 400 && !empty($ji['data'])) { foreach ($ji['data'] as $d) { $vals[$d['name']] = (int)($d['values'][0]['value'] ?? ($d['total_value']['value'] ?? 0)); } break; }
        }
        $type = (string)($m['media_product_type'] ?? '') === 'REELS' ? 'Reel' : ucfirst(strtolower(str_replace('_', ' ', (string)($m['media_type'] ?? ''))));
        $up->execute([(string)$m['id'], substr((string)$m['timestamp'], 0, 19), $type, (string)($m['permalink'] ?? ''), mb_substr((string)($m['caption'] ?? ''), 0, 200),
            (int)($m['like_count'] ?? 0), (int)($m['comments_count'] ?? 0), $vals['reach'] ?? 0, $vals['views'] ?? 0, $vals['shares'] ?? 0, $vals['saved'] ?? 0,
            $vals['total_interactions'] ?? ((int)($m['like_count'] ?? 0) + (int)($m['comments_count'] ?? 0) + ($vals['shares'] ?? 0) + ($vals['saved'] ?? 0)), date('Y-m-d H:i:s')]);
        $done++;
    }
    return 'Followers ' . number_format((int)($acct['followers_count'] ?? 0)) . '. Updated ' . $done . ' posts from the last 60 days.';
}

/* ---------- running ---------- */
function conn_run(string $kind): string
{
    if (!isset(CONN_KINDS[$kind])) { return 'Unknown connection.'; }
    $pdo = db();
    $pdo->prepare('UPDATE conn SET last_run = ? WHERE kind = ?')->execute([date('Y-m-d H:i:s'), $kind]);
    try {
        @set_time_limit(180);
        if (!conn_ready($kind)) { throw new RuntimeException('Fill in every box and save first.'); }
        $msg = ['meta_ads' => 'conn_run_meta_ads', 'google_ads' => 'conn_run_google_ads', 'instagram' => 'conn_run_instagram'][$kind]();
        $pdo->prepare('UPDATE conn SET last_ok = 1, last_msg = ? WHERE kind = ?')->execute([$msg, $kind]);
        return $msg;
    } catch (Throwable $e) {
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong: ' . $e->getMessage();
        $pdo->prepare('UPDATE conn SET last_ok = 0, last_msg = ? WHERE kind = ?')->execute([mb_substr($msg, 0, 300), $kind]);
        return $msg;
    }
}

function conn_run_due(): int
{
    $n = 0; $now = time();
    foreach (db()->query('SELECT kind, every_min, last_run FROM conn WHERE enabled = 1')->fetchAll() as $r) {
        if (!conn_ready($r['kind'])) { continue; }
        if ($r['last_run'] === '' || $now - strtotime($r['last_run']) >= max(30, (int)$r['every_min']) * 60) { conn_run($r['kind']); $n++; if ($n >= 2) { break; } }
    }
    return $n;
}

/* ---------- reading what was saved ---------- */
function conn_can(?array $u, string $what): bool
{
    if (!$u) { return false; }
    if (can_view_dashboard($u)) { return true; }
    $d = (string)($u['designation'] ?? '');
    return $what === 'ads' ? $d === 'Performance Marketing' : in_array($d, ['Social Media', 'Design / Creative', 'Content'], true) && $what === 'social';
}

function ads_summary(string $from, string $to): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT platform, SUM(spend) spend, SUM(impressions) imp, SUM(clicks) clk, SUM(purchases) pur, SUM(sales) sales FROM ads_daily WHERE day BETWEEN ? AND ? GROUP BY platform ORDER BY platform'); $st->execute([$from, $to]);
    $by = $st->fetchAll();
    $st = $pdo->prepare("SELECT platform, campaign, SUM(spend) spend, SUM(clicks) clk, SUM(purchases) pur, SUM(sales) sales FROM ads_daily WHERE day BETWEEN ? AND ? GROUP BY platform, campaign HAVING SUM(spend) > 0 ORDER BY spend DESC LIMIT 40"); $st->execute([$from, $to]);
    $camp = $st->fetchAll();
    $st = $pdo->prepare("SELECT ad, campaign, SUM(spend) spend, SUM(impressions) imp, SUM(clicks) clk, SUM(purchases) pur, SUM(sales) sales FROM ads_daily WHERE platform = 'Meta' AND ad <> '' AND day BETWEEN ? AND ? GROUP BY ad, campaign HAVING SUM(spend) > 0 ORDER BY spend DESC LIMIT 40"); $st->execute([$from, $to]);
    $ads = $st->fetchAll();
    $st = $pdo->prepare('SELECT day, SUM(spend) spend, SUM(sales) sales FROM ads_daily WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day'); $st->execute([$from, $to]);
    $days = $st->fetchAll();
    return ['by' => $by, 'camp' => $camp, 'ads' => $ads, 'days' => $days];
}

/** Meta-attributed sales for a date range (used for the Shopify "From Meta ads" line when it was not typed in). */
function ads_meta_sales(string $from, string $to): float
{
    $st = db()->prepare("SELECT COALESCE(SUM(sales),0) FROM ads_daily WHERE platform = 'Meta' AND day BETWEEN ? AND ?"); $st->execute([$from, $to]);
    return (float)$st->fetchColumn();
}
