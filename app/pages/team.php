<?php
defined('DD') or exit;
$pdo = db(); $me = user();
$q = trim((string)($_GET['q'] ?? ''));
$rows = $pdo->query("SELECT u.*, p.phone, p.city, p.photo, p.updated_at AS pu, p.joined_on FROM users u LEFT JOIN profiles p ON p.user_id=u.id WHERE u.active=1 ORDER BY CASE u.role WHEN 'ceo' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, u.name")->fetchAll();
if ($q !== '') { $rows = array_values(array_filter($rows, fn($r) => stripos($r['name'] . ' ' . $r['designation'] . ' ' . $r['email'] . ' ' . $r['city'], $q) !== false)); }
layout_head('Team');
?>
<div class="pagehead"><div><h1>Team</h1><p class="muted"><?= count($rows) ?> people. Open a card to see the full profile.</p></div>
<form class="pagehead__r" method="get"><input type="hidden" name="p" value="team"><input class="srch" type="search" name="q" value="<?= h($q) ?>" placeholder="Search name or role" style="min-height:42px;border:1px solid var(--line);border-radius:12px;padding:0 13px;font:inherit"><button class="btn btn--ghost btn--sm" type="submit">Search</button></form></div>
<div class="team">
<?php foreach ($rows as $r): ?>
  <a class="card team__c" href="<?= h(url('profile', ['id' => (int)$r['id']])) ?>">
    <?= avatar_html($r, 64, ($r['photo'] ?? '') !== '' ? $r['photo'] . '|' . $r['pu'] : '') ?>
    <strong><?= h($r['name']) ?></strong>
    <span class="muted"><?= h($r['designation']) ?></span>
    <span class="tiny muted"><?= h($r['phone'] ?: $r['email']) ?><?= !empty($r['city']) ? ' · ' . h($r['city']) : '' ?></span>
  </a>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="muted">No one matches that search.</p><?php endif; ?>
</div>
<?php layout_foot();
