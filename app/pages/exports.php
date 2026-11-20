<?php
defined('DD') or exit;

$today = today();
$from = valid_date($_GET['from'] ?? '') ? (string)$_GET['from'] : substr($today, 0, 7) . '-01';
$to = valid_date($_GET['to'] ?? '') ? (string)$_GET['to'] : $today;
if ($to < $from) { [$from, $to] = [$to, $from]; }
$sets = export_datasets();
$dl = (string)($_GET['dl'] ?? '');

if ($dl !== '') {
    if ($dl === 'all' && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'dd');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        foreach (array_keys($sets) as $k) {
            [$n, $h, $r] = export_data($k, $from, $to);
            $z->addFromString($n, csv_string($h, $r));
        }
        $z->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="denzour_dailydesk_' . $from . '_to_' . $to . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    if (isset($sets[$dl])) {
        [$n, $h, $r] = export_data($dl, $from, $to);
        $csv = csv_string($h, $r);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $n . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }
}

$counts = [];
$pdo = db();
$q = fn(string $sql) => (int)($pdo->prepare($sql)->execute([$from, $to]) ? 0 : 0);
$cnt = function (string $sql) use ($pdo, $from, $to) { $st = $pdo->prepare($sql); $st->execute([$from, $to]); return (int)$st->fetchColumn(); };
$counts['attendance'] = $cnt("SELECT COUNT(DISTINCT user_id || day) FROM att_events WHERE day BETWEEN ? AND ?");
$counts['attendance_events'] = $cnt('SELECT COUNT(*) FROM att_events WHERE day BETWEEN ? AND ?');
$counts['reasons'] = $cnt('SELECT COUNT(*) FROM att_reasons WHERE day BETWEEN ? AND ?');
$counts['sales_orders'] = $cnt('SELECT COUNT(*) FROM sales_orders WHERE order_date BETWEEN ? AND ?');
$counts['sales_calls'] = $cnt('SELECT COUNT(*) FROM sales_calls WHERE call_date BETWEEN ? AND ?');
$counts['sales_minutes'] = $cnt('SELECT COUNT(*) FROM sales_daily WHERE day BETWEEN ? AND ?');
$counts['ecom_daily'] = $cnt('SELECT COUNT(*) FROM ecom_daily WHERE day BETWEEN ? AND ?');
$counts['sales_all'] = $counts['ecom_daily'] + $cnt('SELECT COUNT(*) FROM channel_daily WHERE day BETWEEN ? AND ?') + $cnt('SELECT COUNT(DISTINCT order_date) FROM sales_orders WHERE order_date BETWEEN ? AND ?');
$counts['tasks_monthly'] = $cnt('SELECT COUNT(DISTINCT user_id || substr(report_date,1,7)) FROM reports WHERE report_date BETWEEN ? AND ?');
$counts['reports'] = $cnt('SELECT COUNT(*) FROM reports WHERE report_date BETWEEN ? AND ?');
$qs = fn(array $x) => h(url('exports', ['from' => $from, 'to' => $to] + $x));
$first = substr($today, 0, 7) . '-01';
$last = date('Y-m-01', strtotime($first . ' -1 month'));

layout_head('Downloads');
?>
<div class="pagehead">
  <div><h1>Downloads</h1><p class="muted">Everything DailyDesk has saved, as files you can open in Excel. Download any time.</p></div>
</div>

<form method="get" class="filters" id="rangeform">
  <input type="hidden" name="p" value="exports">
  <div class="filters__row">
    <label class="field"><span>From</span><input type="date" name="from" value="<?= h($from) ?>" max="<?= h($today) ?>"></label>
    <label class="field"><span>To</span><input type="date" name="to" value="<?= h($to) ?>"></label>
    <button class="btn btn--sm" type="submit">Apply dates</button>
  </div>
  <div class="seg" role="group" aria-label="Quick dates">
    <a class="pill" href="<?= h(url('exports', ['from' => $first, 'to' => $today])) ?>">This month</a>
    <a class="pill" href="<?= h(url('exports', ['from' => $last, 'to' => date('Y-m-t', strtotime($last))])) ?>">Last month</a>
    <a class="pill" href="<?= h(url('exports', ['from' => '2020-01-01', 'to' => '2099-12-31'])) ?>">All time</a>
  </div>
</form>

<section class="card" aria-label="Files">
  <?php foreach ($sets as $k => [$title, $desc]): ?>
    <div class="dlrow">
      <div><strong><?= h($title) ?></strong><br><span class="muted tiny"><?= h($desc) ?> · <?= (int)$counts[$k] ?> row<?= $counts[$k] === 1 ? '' : 's' ?> in these dates</span></div>
      <a class="btn btn--ghost btn--sm" href="<?= $qs(['dl' => $k]) ?>"><?= icon('download') ?>CSV</a>
    </div>
  <?php endforeach; ?>
  <?php if (class_exists('ZipArchive')): ?>
    <div class="dlrow dlrow--all"><div><strong>Everything in one zip</strong><br><span class="muted tiny">All of the files above for these dates.</span></div>
      <a class="btn btn--sm" href="<?= $qs(['dl' => 'all']) ?>"><?= icon('download') ?>Download all</a></div>
  <?php endif; ?>
</section>
<p class="muted tiny">CSV files open in Excel and Google Sheets. For a full safety copy of the whole system, also download <code>data/dailydesk.sqlite</code> from cPanel File Manager now and then.</p>
<?php layout_foot();
