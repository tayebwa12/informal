<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/middleware/role_examiner.php';
require_once __DIR__ . '/../../app/config/db.php';

$me = require_examiner();
$userId = (int)($me['id'] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((float)$n, 0); }

$seriesId = (int)($_GET['series_id'] ?? 0);

/**
 * Load series list (for filter dropdown)
 * Your schema: exam_series(id, name, status)
 */
$series = [];
try {
  $series = $pdo->query("SELECT id, name, status FROM exam_series ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $series = []; }

/**
 * If series_id is provided, we also fetch its name to support both schemas:
 * - timetable_sessions.exam_series may store series id (int)
 * - OR it may store series name (varchar)
 */
$seriesNameForFilter = null;
if ($seriesId > 0) {
  try {
    $st = $pdo->prepare("SELECT name FROM exam_series WHERE id=? LIMIT 1");
    $st->execute([$seriesId]);
    $seriesNameForFilter = $st->fetchColumn();
    if ($seriesNameForFilter !== false) $seriesNameForFilter = (string)$seriesNameForFilter;
  } catch (Throwable $e) {
    $seriesNameForFilter = null;
  }
}

/* ---------------- FETCH ONLY COMPLETED DEPLOYMENTS ---------------- */
$params = [$userId];
$whereSeries = "";

if ($seriesId > 0) {
  // supports BOTH: ts.exam_series = seriesId OR ts.exam_series = seriesName
  $whereSeries = " AND (ts.exam_series = ? " . ($seriesNameForFilter ? " OR ts.exam_series = ? " : "") . " ) ";
  $params[] = $seriesId;
  if ($seriesNameForFilter) $params[] = $seriesNameForFilter;
}

$st = $pdo->prepare("
  SELECT
    d.id AS deployment_id,
    ts.session_date,
    ts.start_time,
    ts.end_time,
    ts.candidate_count,

    COALESCE(es.name, CAST(ts.exam_series AS CHAR)) AS series_name,

    c.center_number,
    c.center_name,

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

/* ---------------- TOTAL ----------------
   Current rule: claim_rate per session.
   If you want per candidate, change $amount line below.
*/
$total = 0.0;
foreach ($rows as $r) {
  $amount = (float)$r['claim_rate']; // ✅ per session
  // $amount = (float)$r['claim_rate'] * (int)$r['candidate_count']; // ✅ per candidate (optional)
  $total += $amount;
}

$claimEnabled = count($rows) > 0;

/* Facilitation (template includes these; keep as 0 unless you add inputs/DB fields) */
$safariDays = 0; $safariRate = 0; $safariAmount = 0;
$transportDays = 0; $transportRate = 0; $transportAmount = 0;

$facSub = $safariAmount + $transportAmount;
$grandTotal = $total + $facSub;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Practical Assessment Claim Form</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    body{font-family:system-ui;background:#f6f7fb;margin:0;padding:18px}

    a.btn,button.btn{
      display:inline-block;padding:10px 12px;border-radius:10px;background:#1b5cff;color:#fff;
      text-decoration:none;font-weight:800;border:0;cursor:pointer
    }
    a.btn2{background:#fff;color:#1b5cff;border:2px solid #1b5cff}
    .locked{background:#9aa3b2 !important;cursor:not-allowed}

    select{padding:10px;border:1px solid #ddd;border-radius:10px}
    .muted{color:#666;font-size:13px}
    .tiny{font-size:11px;color:#444}

    /* "Paper" like the Word form */
    .paper{
      background:#fff;
      max-width:1100px;
      margin:0 auto;
      border-radius:10px;
      padding:18px;
      box-shadow:0 8px 24px rgba(0,0,0,.06)
    }
    .hdr .big{font-weight:900;font-size:18px;text-align:center;letter-spacing:.2px}

    .meta{width:100%;border-collapse:collapse;margin-top:10px}
    .meta td{padding:6px 8px;border:1px solid #ddd;font-size:13px}

    .block{margin-top:14px}
    .row{margin:8px 0}
    .field{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
    .lbl{font-size:13px;color:#222}
    .line{
      display:inline-block;
      min-width:220px;
      border-bottom:1px dotted #333;
      padding:2px 6px;
      font-size:13px;
    }
    .line.sm{min-width:140px}
    .line.lg{min-width:340px}

    .section-title{font-weight:900;margin:6px 0 8px}

    table.claim{width:100%;border-collapse:collapse}
    table.claim th, table.claim td{
      border:1px solid #000;
      padding:7px 8px;
      font-size:13px;
      vertical-align:top;
    }
    table.claim th{background:#f2f2f2}
    .right{text-align:right}

    .footer{
      margin-top:16px;
      padding-top:10px;
      border-top:1px solid #ddd;
      font-size:12px;
      color:#222;
      display:flex;
      flex-direction:column;
      gap:4px;
    }

    .topbar{
      max-width:1100px;
      margin:0 auto 12px auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      flex-wrap:wrap;
    }

    /* Print to look like the form */
    @media print{
      body{background:#fff;padding:0}
      .noprint{display:none !important}
      .paper{box-shadow:none;border-radius:0;max-width:none}
      a.btn, a.btn2, button.btn{display:none !important}
    }
  </style>
</head>

<body>

<div class="topbar noprint">
  <a class="btn btn2" href="dashboard.php">← Back</a>

  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0">
      <div>
        <label class="muted"><b>Filter by Series</b></label><br>
        <select name="series_id" onchange="this.form.submit()">
          <option value="0">-- All series --</option>
          <?php foreach ($series as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo $seriesId===(int)$s['id']?'selected':''; ?>>
              <?php echo h($s['name']); ?> (<?php echo h($s['status']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <?php if ($claimEnabled): ?>
      <a class="btn" href="generate_claim_pdf.php<?php echo $seriesId>0 ? '?series_id='.$seriesId : ''; ?>" target="_blank">
        Generate PDF
      </a>
    <?php else: ?>
      <a class="btn locked" href="#" onclick="alert('Claim is locked. Ask the Officer to mark your deployment as COMPLETED.');return false;">
        Claim Locked
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="paper">
  <!-- HEADER -->
  <div class="hdr">
    <div class="big">UGANDA VOCATIONAL AND TECHNICAL ASSESSMENT BOARD (UVTAB)</div>

    <table class="meta">
      <tr>
        <td><b>Document title:</b> Practical Assessment Claim Form</td>
        <td><b>Document No:</b> 001</td>
      </tr>
      <tr>
        <td><b>ISO 9001: 2015, 7.1</b></td>
        <td><b>Effective Date:</b> 15th/Mar/2025</td>
      </tr>
      <tr>
        <td><b>REF:</b> UVTAB-QMS-DES-FRM-001-2026</td>
        <td><b>ISSUE NO:</b> 01</td>
      </tr>
    </table>
  </div>

  <!-- TOP DETAILS -->
  <div class="block">
    <div class="row">
      <div class="field">
        <span class="lbl">Period of Assessment; From</span>
        <span class="line sm"> </span>
        <span class="lbl">To</span>
        <span class="line sm"> </span>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <span class="lbl">Name of assessor</span>
        <span class="line lg"><?php echo h($me['full_name'] ?? ''); ?></span>
        <span class="lbl">Contact</span>
        <span class="line sm"><?php echo h($me['phone'] ?? ''); ?></span>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <span class="lbl">Account Number</span>
        <span class="line lg"> </span>
        <span class="lbl">Bank</span>
        <span class="line sm"> </span>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <span class="lbl">Account Name</span>
        <span class="line lg"> </span>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <span class="lbl">Name of your Centre</span>
        <span class="line lg"> </span>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <span class="lbl">Name of Centre Deployed to</span>
        <span class="line lg"> </span>
      </div>
    </div>

    <p class="muted noprint" style="margin:10px 0 0;">
      Claim is enabled ONLY after an Officer marks your deployment as <b>COMPLETED</b>.
    </p>
  </div>

  <!-- MAIN TABLE -->
  <div class="block">
    <table class="claim">
      <thead>
        <tr>
          <th style="width:10%">Date</th>
          <th style="width:22%">Occupation<br><span class="tiny">(Level I,II &amp; Modular)</span></th>
          <th style="width:8%">Level</th>
          <th style="width:10%">Code</th>
          <th style="width:10%">No. of days</th>
          <th style="width:12%">Marking Fee</th>
          <th style="width:12%">Amount</th>
          <th style="width:16%">Signature</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="8" class="muted">No completed deployments found for claim.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $days = 1; // default (template wants days). Replace if you later store real days.
              $fee = (float)$r['claim_rate'];
              $amount = $fee * $days; // per session * days
              // If you want per candidate instead, use:
              // $amount = $fee * (int)$r['candidate_count'];
            ?>
            <tr>
              <td><?php echo h($r['session_date']); ?></td>
              <td><?php echo h($r['occupation_name'] ?? ''); ?></td>
              <td><?php echo 'I/II'; ?></td>
              <td><?php echo h($r['occupation_code'] ?? ''); ?></td>
              <td><?php echo (int)$days; ?></td>
              <td><?php echo money($fee); ?></td>
              <td><?php echo money($amount); ?></td>
              <td>..............................</td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" class="right"><b>Sub-total</b></td>
          <td><b><?php echo money($total); ?></b></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- FACILITATION -->
  <div class="block">
    <div class="section-title">Facilitation</div>
    <table class="claim">
      <thead>
        <tr>
          <th style="width:6%"></th>
          <th>Items</th>
          <th style="width:10%">Days</th>
          <th style="width:14%">Rate</th>
          <th style="width:14%">Amount</th>
          <th style="width:18%">Signature</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>1.</td>
          <td>Safari Day Allowance/Per-diem</td>
          <td><?php echo (int)$safariDays; ?></td>
          <td><?php echo money($safariRate); ?></td>
          <td><?php echo money($safariAmount); ?></td>
          <td>..............................</td>
        </tr>
        <tr>
          <td>2.</td>
          <td>Transport</td>
          <td><?php echo (int)$transportDays; ?></td>
          <td><?php echo money($transportRate); ?></td>
          <td><?php echo money($transportAmount); ?></td>
          <td>..............................</td>
        </tr>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" class="right"><b>Sub-total</b></td>
          <td><b><?php echo money($facSub); ?></b></td>
          <td></td>
        </tr>
        <tr>
          <td colspan="4" class="right"><b>Grand total</b></td>
          <td><b><?php echo money($grandTotal); ?></b></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- ENDORSEMENTS -->
  <div class="block">
    <div class="section-title">Centre Endorsement (Head of Centre)</div>
    <div class="row">
      <div class="field">
        <span class="lbl">Name:</span>
        <span class="line lg"> </span>
        <span class="lbl">Contact:</span>
        <span class="line sm"> </span>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <span class="lbl">Sign:</span>
        <span class="line sm"> </span>
        <span class="lbl">Stamp:</span>
        <span class="line sm"> </span>
        <span class="lbl">&amp; Date</span>
        <span class="line sm"> </span>
      </div>
    </div>

    <div class="section-title" style="margin-top:14px">UVTAB Endorsement (Official Use Only)</div>
    <div class="row">
      <div class="field">
        <span class="lbl">Names of Coordinator:</span>
        <span class="line lg"> </span>
        <span class="lbl">Sign</span>
        <span class="line sm"> </span>
      </div>
    </div>
  </div>

  <!-- FOOTER -->
  <div class="footer">
    <div>Doc. Owner: <b>DEPUTY EXECUTIVE SECRETARY – TVET ASSESSMENT</b></div>
    <div>Doc.No. <b>UVTAB-QMS-DES-FRM-001-26</b> • Version No. <b>v1.0</b> • Doc. Security: <b>Internal Use Only</b></div>
    <div>Effective Date: <b>15th/Mar/2025</b></div>
  </div>
</div>

</body>
</html>