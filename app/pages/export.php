<?php
defined('DD') or exit;
$pdo = db();
$f = $_GET['from'] ?? ''; $t = $_GET['to'] ?? '';
if (!valid_date($f) || !valid_date($t) || $f > $t) { $f = add_days(today(), -29); $t = today(); }
if ((new DateTime($f))->diff(new DateTime($t))->days > 366) { $f = add_days($t, -366); }
$fd = (string)($_GET['designation'] ?? ''); $fu = (int)($_GET['user'] ?? 0);
$sql = "SELECT r.report_date, u.name, u.designation, r.type, t.category, t.title, t.hours, t.status, t.remarks, r.note
        FROM reports r JOIN users u ON u.id = r.user_id LEFT JOIN tasks t ON t.report_id = r.id
        WHERE u.role = 'employee' AND r.report_date BETWEEN ? AND ?";
$args = [$f, $t];
if ($fd !== '' && in_array($fd, cfg('designations'), true)) { $sql .= ' AND u.designation = ?'; $args[] = $fd; }
if ($fu > 0) { $sql .= ' AND u.id = ?'; $args[] = $fu; }
$sql .= ' ORDER BY r.report_date DESC, u.name, t.id';
$st = $pdo->prepare($sql); $st->execute($args);

function safe_cell($v): string
{
    $v = (string)$v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}
header_remove('Content-Security-Policy');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="daily-reports_' . $f . '_to_' . $t . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Date', 'Employee', 'Designation', 'Type', 'Category', 'Task', 'Hours', 'Status', 'Remarks', 'Day note']);
foreach ($st as $r) {
    fputcsv($out, [$r['report_date'], safe_cell($r['name']), safe_cell($r['designation']), $r['type'], safe_cell($r['category']), safe_cell($r['title']),
        $r['hours'], $r['status'] ? status_label($r['status']) : '', safe_cell($r['remarks']), safe_cell($r['note'])]);
}
fclose($out);
exit;
