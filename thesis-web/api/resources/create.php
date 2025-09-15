<?php
// /api/resources/create.php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
$me  = require_login();  // student ή teacher ή admin
$pdo = db();

/* body */
$isMultipart = (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') === 0);
$in = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: $_POST);

$thesis_id = trim((string)($in['thesis_id'] ?? ''));
$kind      = trim((string)($in['kind'] ?? 'draft')); // draft|code|video|image|other
if ($thesis_id === '') { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }

/* Δικαιώματα:
   - Student: πρέπει η thesis να ανήκει στον φοιτητή
   - Teacher/Admin: αφήνουμε το upload (όπως ήδη δούλευε για καθηγητή) */
if ($me['role'] === 'student') {
  $st = $pdo->prepare("SELECT 1 FROM theses WHERE id=? AND student_id=?");
  $st->execute([$thesis_id, $me['id']]);
  if (!$st->fetchColumn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }
}

$publicUrl = null;

if ($isMultipart && isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
  // επιτρέπεις μόνο PDF (ασφαλής προεπιλογή)
  $mime = mime_content_type($_FILES['file']['tmp_name']) ?: '';
  $ext  = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
  if ($ext !== 'pdf' || stripos($mime,'pdf') === false) {
    http_response_code(415);
    echo json_encode(['ok'=>false,'error'=>'Μόνο PDF επιτρέπεται'], JSON_UNESCAPED_UNICODE); exit;
  }

  // Φάκελος: /public/uploads/theses/{thesis_id}/
  $safe = preg_replace('/[^a-zA-Z0-9\-_]/','_', $thesis_id);
  $base = dirname(__DIR__); // project root
  $dir  = $base . '/public/uploads/theses/' . $safe;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $fname = 'draft_'.date('Ymd_His').'.pdf';
  $dest  = $dir . '/' . $fname;

  if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Αποτυχία μεταφόρτωσης'], JSON_UNESCAPED_UNICODE); exit;
  }
  $publicUrl = '/uploads/theses/' . $safe . '/' . $fname;
} else {
  /* εναλλακτικά: δήλωση link */
  $publicUrl = trim((string)($in['url_or_path'] ?? ''));
  if ($publicUrl === '') { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'url_or_path or file required']); exit; }
}

if (!in_array($kind, ['draft','code','video','image','other'], true)) $kind = 'other';

$ins = $pdo->prepare("INSERT INTO resources(thesis_id, kind, url_or_path) VALUES (?,?,?)");
$ins->execute([$thesis_id, $kind, $publicUrl]);

echo json_encode(['ok'=>true,'item'=>['thesis_id'=>$thesis_id,'kind'=>$kind,'url_or_path'=>$publicUrl]], JSON_UNESCAPED_UNICODE);
