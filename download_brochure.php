<?php
/**
 * download_brochure.php — Property brochure PDF for authenticated Buyers.
 * Requires: composer require dompdf/dompdf  (already installed).
 * GD extension is optional; images degrade gracefully without it.
 */
ini_set('session.cache_limiter', 'public');
session_cache_limiter(false);
session_start();
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/* ── Auth: Buyer-only ──────────────────────────────────────────────────── */
requireRole(['buyer']);

/* ── Input ─────────────────────────────────────────────────────────────── */
$pid    = filter_input(INPUT_GET, 'pid', FILTER_VALIDATE_INT) ?: 0;
$userId = currentUserId();

/* ── Property query ─────────────────────────────────────────────────────── */
$stmt = $con->prepare(
    "SELECT p.*, u.name AS agent_name, u.email AS agent_email, u.phone_num AS agent_phone
       FROM property_listings p
       JOIN user u ON p.seller_id = u.user_id
      WHERE p.property_id = ?
        AND p.review_status = 'approved'
        AND p.status != 'hold'
      LIMIT 1"
);
$stmt->bind_param('i', $pid);
$stmt->execute();
$property = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}

/* ── Fetch images ───────────────────────────────────────────────────────── */
$imgStmt = $con->prepare(
    "SELECT image_url FROM property_image WHERE property_id = ? ORDER BY image_id LIMIT 3"
);
$imgStmt->bind_param('i', $pid);
$imgStmt->execute();
$images = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$imgStmt->close();

/* ── Helper: embed image as base64 ─────────────────────────────────────── */
function imageToBase64(string $filename): ?string
{
    $path = __DIR__ . '/admin/property/' . basename($filename);
    if (!is_file($path)) {
        return null;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
    if (!isset($allowed[$ext])) {
        return null;
    }
    // Verify via GD if available; otherwise trust extension
    if (extension_loaded('gd')) {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
    }
    $data = file_get_contents($path);
    if ($data === false) {
        return null;
    }
    $mime = 'image/' . $allowed[$ext];
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

/* ── Build image HTML ───────────────────────────────────────────────────── */
$imageHtml = '';
foreach ($images as $img) {
    $b64 = imageToBase64($img['image_url']);
    if ($b64) {
        $imageHtml .= '<img src="' . htmlspecialchars($b64, ENT_QUOTES, 'UTF-8')
            . '" alt="Property photo" style="max-width:100%;height:auto;margin-bottom:10px;border-radius:4px;">';
    }
}
if ($imageHtml === '') {
    $imageHtml = '<p style="color:#888;">[No photos available]</p>';
}

/* ── Format helpers ─────────────────────────────────────────────────────── */
$e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$addr = implode(', ', array_filter([
    $property['street'] ?? '',
    $property['location'] ?? '',
    $property['state'] ?? '',
    $property['zip'] ?? '',
]));
$price    = '₹' . number_format((float)($property['price'] ?? 0));
$listedOn = !empty($property['created_at']) ? date('d M Y', strtotime($property['created_at'])) : '—';

/* ── PDF HTML template ─────────────────────────────────────────────────── */
$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Property Brochure</title>
<style>
  body  { font-family: DejaVu Sans, sans-serif; font-size:13px; color:#222; margin:0; padding:0; }
  .cover{ background:#1a3c5e; color:#fff; padding:30px 36px 20px; }
  .cover h1 { margin:0 0 6px; font-size:22px; }
  .cover .sub { font-size:14px; opacity:.85; }
  .cover .price { font-size:28px; font-weight:bold; margin-top:12px; }
  .section { padding:18px 36px; border-bottom:1px solid #e8e8e8; }
  .section h2 { font-size:15px; color:#1a3c5e; margin:0 0 10px; border-left:4px solid #d4a017; padding-left:8px; }
  .grid  { display:table; width:100%; }
  .col   { display:table-cell; width:50%; padding-right:16px; vertical-align:top; }
  .spec  { margin-bottom:6px; }
  .spec label { font-weight:bold; color:#555; display:inline-block; min-width:110px; }
  .photos { padding:18px 36px; }
  .photos h2 { font-size:15px; color:#1a3c5e; margin:0 0 10px; border-left:4px solid #d4a017; padding-left:8px; }
  .footer { background:#f5f5f5; padding:14px 36px; font-size:11px; color:#888; }
  .badge { display:inline-block; background:#d4a017; color:#fff; border-radius:3px; padding:2px 8px; font-size:11px; margin-left:6px; }
</style>
</head>
<body>

<div class="cover">
  <h1>{$e($property['title'])} <span class="badge">{$e($property['property_type'])}</span></h1>
  <div class="sub">{$e($addr)}</div>
  <div class="price">{$price}</div>
</div>

<div class="section">
  <h2>Property Details</h2>
  <div class="grid">
    <div class="col">
      <div class="spec"><label>Type:</label> {$e($property['property_type'])}</div>
      <div class="spec"><label>Status:</label> {$e(ucfirst($property['status'] ?? ''))}</div>
      <div class="spec"><label>Bedrooms:</label> {$e((int)($property['bedrooms'] ?? 0))}</div>
      <div class="spec"><label>Bathrooms:</label> {$e((int)($property['bathrooms'] ?? 0))}</div>
      <div class="spec"><label>Area:</label> {$e(number_format((int)($property['size_sqft'] ?? 0)))} sqft</div>
    </div>
    <div class="col">
      <div class="spec"><label>Listed:</label> {$listedOn}</div>
      <div class="spec"><label>Bus access:</label> {$e($property['bus_availability'] ?? '—')}</div>
      <div class="spec"><label>Tram access:</label> {$e($property['tram_availability'] ?? '—')}</div>
      <div class="spec"><label>Pool:</label> {$e($property['pool_available'] ?? '—')}</div>
      <div class="spec"><label>Pet friendly:</label> {$e($property['is_dog_friendly'] ?? '—')}</div>
    </div>
  </div>
</div>

<div class="section">
  <h2>Description</h2>
  <p>{$e($property['description'] ?? 'No description provided.')}</p>
</div>

<div class="section">
  <h2>Location</h2>
  <div class="spec"><label>Address:</label> {$e($addr)}</div>
HTML;

if (!empty($property['nearest_school'])) {
    $html .= '  <div class="spec"><label>Nearby school:</label> ' . $e($property['nearest_school']) . '</div>';
}

$html .= <<<HTML

</div>

<div class="section">
  <h2>Agent / Listing Contact</h2>
  <div class="spec"><label>Name:</label> {$e($property['agent_name'])}</div>
  <div class="spec"><label>Email:</label> {$e($property['agent_email'])}</div>
  <div class="spec"><label>Phone:</label> {$e($property['agent_phone'])}</div>
</div>

<div class="photos">
  <h2>Property Photos</h2>
  {$imageHtml}
</div>

<div class="footer">
  Brochure generated by EstateScope &mdash; For information only. Prices and details are subject to change.
  Generated on {$e(date('d M Y, H:i'))}
</div>

</body>
</html>
HTML;

/* ── Dompdf render ──────────────────────────────────────────────────────── */
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

ob_end_clean();

/* ── Safe filename ──────────────────────────────────────────────────────── */
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $property['title'] ?? 'property');
$safeName = trim(preg_replace('/-+/', '-', $safeName), '-');
$filename = 'EstateScope-' . $safeName . '-' . $pid . '.pdf';

$dompdf->stream($filename, ['Attachment' => true]);
exit;

