<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_examiner.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_examiner();
$userId = (int)($me['id'] ?? 0);

function money($n): string { return number_format((float)$n, 0); }

$seriesId = (int)($_GET['series_id'] ?? 0);

/* ---------------- Load TCPDF (Composer OR Manual) ---------------- */
$tcpdfLoaded = false;

// Composer
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
  if (class_exists('TCPDF')) $tcpdfLoaded = true;
}

// Manual fallback
if (!$tcpdfLoaded) {
  $manual = __DIR__ . '/../../app/libs/tcpdf/tcpdf.php';
  if (file_exists($manual)) {
    require_once $manual;
    if (class_exists('TCPDF')) $tcpdfLoaded = true;
  }
}

if (!$tcpdfLoaded) {
  http_response_code(500);
  exit("TCPDF not found. Install via Composer or place it in app/libs/tcpdf/tcpdf.php");
}

/* ---------------- series name for filter (supports id or name storage) ---------------- */
$seriesNameForFilter = null;
if ($seriesId > 0) {
  try {
    $st = $pdo->prepare("SELECT name FROM exam_series WHERE id=? LIMIT 1");
    $st->execute([$seriesId]);
    $v = $st->fetchColumn();
    if ($v !== false) $seriesNameForFilter = (string)$v;
  } catch (Throwable $e) { $seriesNameForFilter = null; }
}

/* ---------------- Fetch completed deployments ONLY ---------------- */
$params = [$userId];
$whereSeries = "";

if ($seriesId > 0) {
  $whereSeries = " AND (ts.exam_series = ? " . ($seriesNameForFilter ? " OR ts.exam_series = ? " : "") . " ) ";
  $params[] = $seriesId;
  if ($seriesNameForFilter) $params[] = $seriesNameForFilter;
}

$st = $pdo->prepare("
  SELECT
    ts.session_date,
    ts.start_time,
    ts.end_time,
    ts.candidate_count,

    COALESCE(es.name, CAST(ts.exam_series AS CHAR)) AS series_name,

    c.center_number,
    c.center_name,
    c.location_name,

    o.code AS occupation_code,
    o.name AS occupation_name,
    COALESCE(o.claim_rate,0) AS claim_rate
  FROM deployments d
  JOIN timetable_sessions ts ON ts.id = d.timetable_session_id
  LEFT JOIN exam_series es ON es.id = ts.exam_series
  LEFT JOIN centers c ON c.id = ts.center_id
  LEFT JOIN occupations o ON o.id = ts.occupation_id
  WHERE d.examiner_user_id = ?
    AND COALESCE(d.status,'active') = 'completed'
    $whereSeries
  ORDER BY ts.session_date ASC, ts.start_time ASC
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
  http_response_code(403);
  exit("Claim is locked. No completed deployments found. Ask the Officer to mark your deployment as COMPLETED.");
}

$seriesName = (string)($rows[0]['series_name'] ?? 'N/A');

/* ---------------- Total ---------------- */
$total = 0.0;
foreach ($rows as $r) {
  $amount = (float)$r['claim_rate']; // per session
  // $amount = (float)$r['claim_rate'] * (int)$r['candidate_count']; // per candidate (optional)
  $total += $amount;
}

/* ---------------- Build PDF ---------------- */
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Practical Deployment System');
$pdf->SetAuthor('UBTEB');
$pdf->SetTitle('Claim Form');
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);

// Optional logo
$logoPath = __DIR__ . '/../assets/logo.png';
if (file_exists($logoPath)) {
  $pdf->Image($logoPath, 12, 10, 18, 18);
}

$pdf->SetXY(32, 12);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 6, 'UGANDA BUSINESS AND TECHNICAL EXAMINATIONS BOARD', 0, 1);

$pdf->SetX(32);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'PRACTICAL DEPLOYMENT - EXAMINER CLAIM FORM', 0, 1);

$pdf->Ln(6);

$pdf->SetFont('helvetica', '', 11);
$info =
  "Series: {$seriesName}\n" .
  "Examiner: " . ($me['full_name'] ?? '') . "\n" .
  "Phone: " . ($me['phone'] ?? '—') . "\n" .
  "Generated: " . date('Y-m-d H:i');
$pdf->MultiCell(0, 6, $info, 0, 'L', false, 1);

$pdf->Ln(2);

/* ---------------- Table HTML ---------------- */
$html = '
<table border="1" cellpadding="5">
  <tr style="background-color:#f2f5ff;">
    <th width="16%"><b>Date</b></th>
    <th width="22%"><b>Time</b></th>
    <th width="30%"><b>Centre</b></th>
    <th width="22%"><b>Paper / Occupation</b></th>
    <th width="10%" align="right"><b>Amt</b></th>
  </tr>
';

foreach ($rows as $r) {
  $time = trim(($r['start_time'] ?? '') . ' - ' . ($r['end_time'] ?? ''));
  $centre = trim(($r['center_number'] ?? '') . ' - ' . ($r['center_name'] ?? ''));
  $paper = trim(($r['occupation_code'] ?? '') . ' - ' . ($r['occupation_name'] ?? ''));
  $amount = (float)$r['claim_rate']; // per session
  // $amount = (float)$r['claim_rate'] * (int)$r['candidate_count']; // per candidate (optional)

  $html .= '
  <tr>
    <td width="16%">' . htmlspecialchars((string)$r['session_date']) . '</td>
    <td width="22%">' . htmlspecialchars($time) . '</td>
    <td width="30%">' . htmlspecialchars($centre) . '</td>
    <td width="22%">' . htmlspecialchars($paper) . '</td>
    <td width="10%" align="right">' . money($amount) . '</td>
  </tr>';
}

$html .= '</table>';

$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Ln(4);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'TOTAL: ' . money($total) . ' UGX', 0, 1, 'R');

$pdf->Ln(6);
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 6, "Declaration: I confirm that I attended the above deployment sessions and performed my duties.", 0, 'L');

$pdf->Ln(6);
$pdf->Cell(0, 6, 'Examiner Signature: ___________________________   Date: _____________', 0, 1);
$pdf->Ln(3);
$pdf->Cell(0, 6, 'Officer / Supervisor Signature: __________________   Date: _____________', 0, 1);
$pdf->Ln(3);
$pdf->Cell(0, 6, 'Executive Secretary Signature: ____________________  Date: _____________', 0, 1);

$filename = 'claim_form_' . $userId . '_' . date('Ymd_His') . '.pdf';
$pdf->Output($filename, 'I');
exit;
