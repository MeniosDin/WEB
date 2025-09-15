<?php
header('Content-Type: application/json; charset=utf-8');
@require_once __DIR__ . '/../utils/bootstrap.php';
@require_once __DIR__ . '/../utils/auth_guard.php';

try {
  ensure_logged_in();

  // Δέχεται είτε multipart (file upload) είτε JSON (link)
  $isMultipart = !empty($_FILES);

  if ($isMultipart) {
    $thesis_id = $_POST['thesis_id'] ?? null;
    $kind      = $_POST['kind']      ?? 'other';
    if (!$thesis_id || !isset($_FILES['file'])) {
      echo json_encode(['ok'=>false,'error'=>'thesis_id and file required']); exit;
    }

    // Αποθήκευση αρχείου (προσαρμόζεις paths/ονόματα)
    $dir = __DIR__ . '/../../uploads/resources';
    if (!is_dir($dir)) mkdir($dir,0777,true);

    $fname = basename($_FILES['file']['name']);
    $dest  = $dir . '/' . uniqid().'_'.$fname;
    if (!move_uploaded_file($_FILES['file']['tmp_name'],$dest)) {
      echo json_encode(['ok'=>false,'error'=>'upload failed']); exit;
    }

    $urlPath = '/uploads/resources/'.basename($dest);

    $sql = "INSERT INTO resources (thesis_id, kind, url_or_path, created_at)
            VALUES (?,?,?,NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([$thesis_id,$kind,$urlPath]);
    $id = $pdo->lastInsertId();

    echo json_encode(['ok'=>true,'resource_id'=>$id]);
    exit;
  }
  else {
    // JSON
    $data = json_decode(file_get_contents('php://input'),true) ?: [];
    $thesis_id   = $data['thesis_id']   ?? null;
    $kind        = $data['kind']        ?? 'other';
    $url_or_path = $data['url_or_path'] ?? null;

    if (!$thesis_id || !$url_or_path) {
      echo json_encode(['ok'=>false,'error'=>'thesis_id and url_or_path required']); exit;
    }

    $sql = "INSERT INTO resources (thesis_id, kind, url_or_path, created_at)
            VALUES (?,?,?,NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([$thesis_id,$kind,$url_or_path]);
    $id = $pdo->lastInsertId();

    echo json_encode(['ok'=>true,'resource_id'=>$id]);
  }
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}