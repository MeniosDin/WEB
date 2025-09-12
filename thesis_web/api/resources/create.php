<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_login();

$isMultipart = (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') === 0);
$in = $isMultipart ? $_POST : (body_json() ?: $_POST);

$thesis_id = trim((string)($in['thesis_id'] ?? ''));
$kind      = trim((string)($in['kind'] ?? 'draft')); // draft|code|video|image|other
if ($thesis_id === '') bad('thesis_id required', 422);
assert_student_owns_thesis($pdo, $thesis_id, $u['id']);

$url_or_path = null;

if ($isMultipart && isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
  // αποθήκευση αρχείου κάτω από /public/uploads/theses/{thesis_id}/
  $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
  $safeThesis = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $thesis_id);
  $base = dirname(__DIR__);                  // .../api -> project root
  $dir  = $base . '/public/uploads/theses/' . $safeThesis;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $fname = uniqid('res_', true) . ($ext ? ('.' . $ext) : '');
  $dest  = $dir . '/' . $fname;

  if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    bad('Αποτυχία μεταφόρτωσης', 400);
  }
  // public-relative URL
  $url_or_path = '/uploads/theses/' . $safeThesis . '/' . $fname;
} else {
  $url_or_path = trim((string)($in['url_or_path'] ?? ''));
  if ($url_or_path === '') bad('url_or_path or file required', 422);
}

if (!in_array($kind, ['draft','code','video','image','other'], true)) $kind = 'other';

$st = $pdo->prepare("INSERT INTO resources(thesis_id, kind, url_or_path) VALUES(?,?,?)");
$st->execute([$thesis_id, $kind, $url_or_path]);

ok(['item'=>['thesis_id'=>$thesis_id, 'kind'=>$kind, 'url_or_path'=>$url_or_path]]);
