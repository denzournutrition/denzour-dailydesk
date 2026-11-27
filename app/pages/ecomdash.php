<?php
defined('DD') or exit;

$me = user();
$isAdmin = $me['role'] === 'admin';
$today = today();
$month = s_month($_GET['month'] ?? null);
$self = url('ecomdash', ['month' => $month]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    csrf_verify();
    $k = (string)($_POST['platform'] ?? '');
    if (($_POST['action'] ?? '') === 'logo' && isset(ECOM_PLATFORMS[$k])) {
        $f = $_FILES['logo'] ?? null;
        $info = $f && $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
        $ext = $info ? [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null : null;
        if (!$ext || $f['size'] > 1024 * 1024) {
            flash('error', 'Please choose a PNG, JPG or WebP logo under 1 MB.');
        } else {
            $dir = dirname(__DIR__, 2) . '/data/logos';
            if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
            foreach (['png', 'jpg', 'webp'] as $x) { @unlink($dir . '/' . $k . '.' . $x); }
            move_uploaded_file($f['tmp_name'], $dir . '/' . $k . '.' . $ext);
            flash('success', ECOM_PLATFORMS[$k][0] . ' logo saved.');
        }
    } elseif (($_POST['action'] ?? '') === 'logo_remove' && isset(ECOM_PLATFORMS[$k])) {
        foreach (['png', 'jpg', 'webp'] as $x) { @unlink(dirname(__DIR__, 2) . '/data/logos/' . $k . '.' . $x); }
        flash('success', 'Logo removed.');
    }
    redirect($self);
}

$m = ecom_month($month);
$by = $m['by']; $plat = $m['plat']; $tot = $m['tot'];
[$mS, $mE] = s_month_range($month);
$lastDay = $by ? max(array_keys($by)) : null;
$day = valid_date($_GET['d'] ?? '') ? (string)$_GET['d'] : ($lastDay ?? $today);
$dayRows = ecom_rows($day, $day)[$day] ?? [];
$prev = ecom_rows(add_days($day, -1), add_days($day, -1))[add_days($day, -1)] ?? [];
$share = fn(float $v) => $tot['spend'] > 0 ? round($v / $tot['spend'] * 100) : 0;
$csrf = csrf_field();
$daysList = []; for ($d = min($mE, $today); $d >= $mS; $d = add_days($d, -1)) { $daysList[] = $d; }

layout_head('E-commerce');
?>
<div class="pagehead">
  <div><h1>E-commerce</h1><p class="muted">Blinkit, Myntra, Hyugalife and Tata 1mg · sales and ad spend every day · <?= h(date('F Y', strtotime($month . '-01'))) ?></p></div>
  <div class="pagehead__r"><a class="btn btn--ghost" href="<?= h(url('exports')) ?>"><?= icon('download') ?>Download data</a></div>
</div>
<?php s_month_filter('ecomdash', $month); ?>

<div class="kpis kpis--4">
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Sales</span><span class="kpi__ic kpi__ic--ok"><?= icon('trend') ?></span></div><strong class="kpi__v"><?= h(inr_short($tot['sales'])) ?></strong><span class="kpi__s"><?= h(inr($tot['sales'])) ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Ad spend</span><span class="kpi__ic kpi__ic--warn"><?= icon('target') ?></span></div><strong class="kpi__v"><?= h(inr_short($tot['spend'])) ?></strong><span class="kpi__s"><?= h(inr($tot['spend'])) ?></span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">ROAS</span><span class="kpi__ic"><?= icon('check') ?></span></div><strong class="kpi__v"><?= h(ecom_roas($tot['sales'], $tot['spend'])) ?></strong><span class="kpi__s">Sales for every ₹1 of ad spend</span></div>
  <div class="kpi"><div class="kpi__top"><span class="kpi__l">Orders</span><span class="kpi__ic"><?= icon('list') ?></span></div><strong class="kpi__v"><?= number_format($tot['orders']) ?></strong><span class="kpi__s"><?= $tot['orders'] ? h(inr($tot['sales'] / $tot['orders'])) . ' per order' : 'No orders entered' ?></span></div>
</div>

<div class="platgrid">
<?php foreach (ecom_sheet_platforms() as $k => $p): $x = $plat[$k]; ?>
  <section class="card platcard">
    <div class="platcard__h"><?= ecom_logo($k, 44) ?><div><h2><?= h($p[0]) ?></h2>
      <span class="tiny <?= $x['missing'] ? 'bad' : 'muted' ?>"><?= $x['last'] ? 'Last update ' . h(fmt_short($x['last'])) : 'No data yet' ?><?= $x['missing'] ? ' · ' . $x['missing'] . ' working day' . ($x['missing'] === 1 ? '' : 's') . ' missing' : '' ?></span></div></div>
    <div class="platcard__n"><div><b><?= h(inr_short($x['sales'])) ?></b><span>Sales</span></div><div><b><?= h(inr_short($x['spend'])) ?></b><span>Ad spend</span></div><div><b><?= h(ecom_roas($x['sales'], $x['spend'])) ?></b><span>ROAS</span></div><div><b><?= number_format($x['orders']) ?></b><span>Orders</span></div></div>
    <div class="meter" aria-label="Share of ad spend"><span style="width:<?= $share($x['spend']) ?>%;background:<?= h($p[1]) ?>"></span></div>
    <span class="muted tiny"><?= $share($x['spend']) ?>% of total ad spend</span>
  </section>
<?php endforeach; ?>
</div>

<section class="card" aria-label="Daily sales and spend chart">
  <div class="card__h"><h2>Every day</h2><span class="legend"><i class="lgd lgd--bar"></i>Sales <i class="lgd lgd--spend"></i>Ad spend</span></div>
  <?= ecom_chart($month, $by) ?>
</section>

<section class="card" aria-label="Day detail">
  <?php ecom_day_bar('ecomdash', $day); ?>
  <div class="tblwrap"><table class="tbl">
    <thead><tr><th>Platform</th><th class="r">Sales</th><th class="r">Orders</th><th class="r">Ad spend</th><th class="r">ROAS</th><th class="r hm">vs day before</th><th class="hm">Updated by</th></tr></thead>
    <tbody>
    <?php foreach (ecom_sheet_platforms() as $k => $p): $r = $dayRows[$k] ?? null; $pr = $prev[$k] ?? null; ?>
      <tr><td><span class="pcell"><?= ecom_logo($k, 30) ?><strong><?= h($p[0]) ?></strong></span></td>
        <?php if (!$r): ?><td colspan="4" class="notup <?= is_working_day($day) && $day < $today ? 'is-low' : '' ?>"><span>Not in the sheet yet</span><small><?= !empty($plat[$k]['last']) ? 'last entry ' . h(fmt_short($plat[$k]['last'])) : 'no entry this month' ?></small></td><td class="hm"></td><td class="hm"></td>
        <?php else: $dl = $pr && $pr['sales'] > 0 ? round(($r['sales'] - $pr['sales']) / $pr['sales'] * 100) : null; ?>
          <td class="r"><?= h(inr($r['sales'])) ?></td><td class="r"><?= (int)$r['orders'] ?: '–' ?></td><td class="r"><?= h(inr($r['spend'])) ?></td><td class="r"><?= h(ecom_roas((float)$r['sales'], (float)$r['spend'])) ?></td>
          <td class="r hm <?= $dl !== null && $dl < 0 ? 'is-low' : '' ?>"><?= $dl === null ? '–' : ($dl > 0 ? '+' : '') . $dl . '%' ?></td>
          <td class="hm tiny"><?= h((string)$r['who']) ?> <span class="muted"><?= $r['src'] === 'manual' ? 'typed' : 'sheet' ?></span></td>
        <?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="card" aria-label="All days">
  <div class="card__h"><h2>Day by day</h2><span class="muted tiny">Sales / ad spend</span></div>
  <div class="tblwrap"><table class="tbl tbl--days">
    <thead><tr><th>Date</th><?php foreach (ecom_sheet_platforms() as $k => $p): ?><th class="r"><?= h($p[0]) ?></th><?php endforeach; ?><th class="r">Total sales</th><th class="r">Total spend</th><th class="r hm">ROAS</th></tr></thead>
    <tbody>
    <?php foreach ($daysList as $d): $wd = is_working_day($d); $ds = array_sum(array_column($by[$d] ?? [], 'sales')); $dp = array_sum(array_column($by[$d] ?? [], 'spend')); ?>
      <tr class="<?= $wd || isset($by[$d]) ? '' : 'is-dim' ?>"><td><a href="<?= h(url('ecomdash', ['month' => $month, 'd' => $d])) ?>"><?= h(fmt_date($d)) ?></a></td>
        <?php foreach (ecom_sheet_platforms() as $k => $p): $r = $by[$d][$k] ?? null; ?>
          <td class="r <?= !$r && $wd && $d < $today ? 'is-low' : '' ?>"><?= $r ? h(inr_short((float)$r['sales'])) . ' <span class="muted tiny">/ ' . h(inr_short((float)$r['spend'])) . '</span>' : '–' ?></td>
        <?php endforeach; ?>
        <td class="r"><strong><?= $ds ? h(inr_short($ds)) : '–' ?></strong></td><td class="r"><?= $dp ? h(inr_short($dp)) : '–' ?></td><td class="r hm"><?= h(ecom_roas($ds, $dp)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php if ($isAdmin): ?>
<section class="card card--wide" aria-label="Logos">
  <div class="card__h"><h2>Platform logos</h2></div>
  <p class="muted tiny">Upload each platform's own logo (PNG, JPG or WebP, under 1 MB). Until then a coloured badge is shown.</p>
  <div class="logogrid">
  <?php foreach (ecom_sheet_platforms() as $k => $p): ?>
    <form method="post" enctype="multipart/form-data" action="<?= h($self) ?>" class="logoform">
      <?= $csrf ?><input type="hidden" name="platform" value="<?= h($k) ?>">
      <?= ecom_logo($k, 48) ?><strong><?= h($p[0]) ?></strong>
      <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" aria-label="<?= h($p[0]) ?> logo file" required>
      <div class="actions"><button class="btn btn--sm" name="action" value="logo">Save logo</button>
        <?php if (ecom_logo_path($k)): ?><button class="btn btn--ghost btn--sm" name="action" value="logo_remove" formnovalidate>Remove</button><?php endif; ?></div>
    </form>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php layout_foot();
