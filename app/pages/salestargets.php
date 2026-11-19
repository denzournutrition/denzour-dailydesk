<?php
defined('DD') or exit;

$pdo = db();
$month = s_month($_GET['month'] ?? $_POST['month'] ?? null);
$members = sales_members();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $num = fn($v) => max(0.0, s_amount((string)$v));
    $up = $pdo->prepare('INSERT INTO sales_targets (month, user_id, sales, orders, calls_day) VALUES (?,?,?,?,?) ON CONFLICT(month, user_id) DO UPDATE SET sales = excluded.sales, orders = excluded.orders, calls_day = excluded.calls_day');
    $up->execute([$month, 0, $num($_POST['sales'] ?? 0), (int)$num($_POST['orders'] ?? 0), (int)$num($_POST['calls_day'] ?? 0)]);
    foreach ($members as $m) {
        $raw = trim((string)($_POST['u'][$m['id']] ?? ''));
        if ($raw === '') {
            $pdo->prepare('DELETE FROM sales_targets WHERE month = ? AND user_id = ?')->execute([$month, (int)$m['id']]);
        } else {
            $up->execute([$month, (int)$m['id'], $num($raw), 0, 0]);
        }
    }
    flash('success', 'Targets for ' . date('F Y', strtotime($month . '-01')) . ' saved.');
    redirect(url('salestargets', ['month' => $month]));
}

$tg = sales_targets($month);
$share = ($tg['team']['sales'] > 0 && $members) ? $tg['team']['sales'] / count($members) : 0;

layout_head('Sales targets');
?>
<div class="pagehead">
  <div><h1>Sales targets</h1><p class="muted">One total target for the month. Everyone's dashboard and the CEO dashboard measure against it.</p></div>
</div>
<?php s_month_filter('salestargets', $month); ?>

<form method="post" class="card card--wide" action="<?= h(url('salestargets', ['month' => $month])) ?>">
  <?= csrf_field() ?><input type="hidden" name="month" value="<?= h($month) ?>">
  <div class="card__h"><h2><?= h(date('F Y', strtotime($month . '-01'))) ?></h2></div>
  <div class="formgrid formgrid--3">
    <label class="field"><span>Team sales target (₹)</span>
      <input type="text" name="sales" inputmode="numeric" value="<?= $tg['team']['sales'] > 0 ? h((string)(int)$tg['team']['sales']) : '' ?>" placeholder="e.g. 1500000"></label>
    <label class="field"><span>Orders target</span>
      <input type="text" name="orders" inputmode="numeric" value="<?= $tg['team']['orders'] > 0 ? (int)$tg['team']['orders'] : '' ?>" placeholder="e.g. 350"></label>
    <label class="field"><span>Calls per person / day</span>
      <input type="text" name="calls_day" inputmode="numeric" value="<?= $tg['team']['calls_day'] > 0 ? (int)$tg['team']['calls_day'] : '' ?>" placeholder="e.g. 40"></label>
  </div>

  <h3 class="sechead">Each person's share</h3>
  <?php if (!$members): ?>
    <p class="muted tiny">No one has the designation <strong>Inside Sales</strong> yet. Open People, edit a person, and set their designation to Inside Sales. They then get the Sales desk.</p>
  <?php else: ?>
    <p class="muted tiny">Leave a box empty to split the team target equally<?= $share > 0 ? ' (' . h(inr($share)) . ' each)' : '' ?>. Fill it to give that person a different target.</p>
    <div class="formgrid">
      <?php foreach ($members as $m): ?>
        <label class="field"><span><?= h($m['name']) ?> (₹)</span>
          <input type="text" name="u[<?= (int)$m['id'] ?>]" inputmode="numeric" value="<?= isset($tg['users'][$m['id']]) ? h((string)(int)$tg['users'][$m['id']]) : '' ?>" placeholder="<?= $share > 0 ? h((string)(int)round($share)) : 'Equal share' ?>"></label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="actions"><button class="btn" type="submit">Save targets</button><a class="btn btn--ghost" href="<?= h(url('salesdash', ['month' => $month])) ?>">View dashboard</a></div>
</form>
<?php layout_foot();
