<?php
defined('DD') or exit;
$u = user();
if (!conn_can($u, 'social')) { http_response_code(403); layout_head('Not allowed'); echo '<div class="card"><h1>Not allowed</h1></div>'; layout_foot(); exit; }
$pdo = db();
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : substr(today(), 0, 7);
[$from, $to] = s_month_range($month);
$months = []; for ($i = 0; $i < 12; $i++) { $months[] = date('Y-m', strtotime(substr(today(), 0, 7) . '-01 -' . $i . ' month')); }
$st = $pdo->prepare('SELECT * FROM ig_posts WHERE substr(posted_at,1,10) BETWEEN ? AND ? ORDER BY posted_at DESC'); $st->execute([$from, $to]); $posts = $st->fetchAll();
$fol = $pdo->query('SELECT day, followers FROM ig_daily WHERE followers > 0 ORDER BY day DESC LIMIT 60')->fetchAll();
$now = $fol[0]['followers'] ?? 0;
$then = 0; foreach ($fol as $f) { if ($f['day'] <= $from) { $then = $f['followers']; break; } }
$st = $pdo->prepare('SELECT COALESCE(SUM(reach),0) FROM ig_daily WHERE day BETWEEN ? AND ?'); $st->execute([$from, $to]); $acctReach = (int)$st->fetchColumn();
$reach = array_sum(array_column($posts, 'reach')); $eng = array_sum(array_column($posts, 'interactions'));
$connected = (bool)$pdo->query("SELECT 1 FROM conn WHERE kind = 'instagram'")->fetchColumn();
layout_head('Instagram');
?>
<div class="pagehead"><div><h1>Instagram</h1><p class="muted">Followers, reach and post performance · <?= h(date('F Y', strtotime($month . '-01'))) ?></p></div>
  <div class="pagehead__r"><form method="get" class="filters" style="margin:0"><input type="hidden" name="p" value="social"><label class="field"><span class="sr">Month</span><select name="month" data-autosubmit><?php foreach ($months as $m): ?><option value="<?= h($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= h(date('F Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?></select></label></form></div></div>
<?php if (!$connected && !$posts): ?>
<section class="card"><p class="muted" style="margin:0">Instagram is not connected yet. <?php if ($u['role'] === 'admin'): ?><a href="<?= h(url('conn')) ?>">Open Connections</a><?php else: ?>Ask your admin to connect it.<?php endif; ?></p></section>
<?php else: ?>
<div class="kpis kpis--4">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Followers</span></div><strong class="kpi__v"><?= number_format($now) ?></strong><span class="kpi__s"><?= $then > 0 ? ($now - $then >= 0 ? '+' : '') . number_format($now - $then) . ' this month' : 'Growth shows after a month of daily checks' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Posts</span></div><strong class="kpi__v"><?= count($posts) ?></strong></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Post reach</span></div><strong class="kpi__v"><?= h(inr_short($reach)) ?></strong><span class="kpi__s"><?= $acctReach > 0 ? 'Account reach ' . h(inr_short($acctReach)) : '' ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Engagement</span></div><strong class="kpi__v"><?= number_format($eng) ?></strong><span class="kpi__s"><?= $reach > 0 ? number_format($eng / $reach * 100, 1) . '% of reach' : '' ?></span></div>
</div>
<section class="card"><div class="card__h"><h2>Posts</h2><span class="muted tiny">Newest first</span></div>
<?php if (!$posts): ?><p class="muted">No posts in this month yet.</p><?php else: ?><div class="tblwrap"><table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>Post</th><th class="r">Reach</th><th class="r hm">Views</th><th class="r">Likes</th><th class="r hm">Comments</th><th class="r hm">Shares</th><th class="r hm">Saves</th><th class="r">Eng. rate</th></tr></thead><tbody>
<?php foreach ($posts as $p): ?><tr><td><?= h(fmt_date(substr($p['posted_at'], 0, 10))) ?></td><td><?= h($p['type']) ?></td><td><a href="<?= h($p['permalink']) ?>" target="_blank" rel="noopener"><?= h(mb_substr($p['caption'] !== '' ? $p['caption'] : 'Open post', 0, 60)) ?></a></td><td class="r"><?= number_format($p['reach']) ?></td><td class="r hm"><?= number_format($p['views']) ?></td><td class="r"><?= number_format($p['likes']) ?></td><td class="r hm"><?= number_format($p['comments']) ?></td><td class="r hm"><?= number_format($p['shares']) ?></td><td class="r hm"><?= number_format($p['saves']) ?></td><td class="r"><?= $p['reach'] > 0 ? number_format($p['interactions'] / $p['reach'] * 100, 1) . '%' : '–' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php endif; ?>
<?php layout_foot();
