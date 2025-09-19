<?php
// /api/resources/create.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  ensure_logged_in();

  $raw        = file_get_contents('php://input');
  $asJson     = json_decode($raw, true) ?: [];
  $isMultipart= !empty($_FILES);

  // ---- κοινές παράμετροι ----
  $thesis_id = $isMultipart ? ($_POST['thesis_id'] ?? '') : ($asJson['thesis_id'] ?? ($_POST['thesis_id'] ?? ''));
  $kind      = $isMultipart ? ($_POST['kind'] ?? 'draft')  : ($asJson['kind'] ?? ($_POST['kind'] ?? 'link'));
  $kind      = ($kind === 'link') ? 'link' : 'draft';

  if ($thesis_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'thesis_id is required']);
    exit;
  }

  // ---- MODE A: multipart upload (αρχείο) ----
  if ($isMultipart) {
    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'file is required']);
      exit;
    }

    $file     = $_FILES['file'];
    $origName = basename($file['name']);
    $mime     = $file['type'] ?? null;
    $size     = (int)($file['size'] ?? 0);

    $maxBytes = 20 * 1024 * 1024; // 20MB
    if ($size <= 0 || $size > $maxBytes) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'Invalid file size']);
      exit;
    }

    // === Ο ΚΟΙΝΟΣ ΦΑΚΕΛΟΣ ΠΟΥ ΖΗΤΗΣΕΣ ===
    // C:\xampp\htdocs\thesis-web\public\uploads\specs
    $baseDir = dirname(__DIR__, 2) . '/public/uploads/specs';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'Failed to create upload directory']);
      exit;
    }

    // φτιάχνουμε όνομα αρχείου (προαιρετικά μέσα βάζουμε και το thesis_id)
    $ext       = pathinfo($origName, PATHINFO_EXTENSION);
    $stamp     = date('Ymd_His');
    $storeName = "thesis{$thesis_id}_{$kind}_{$stamp}" . ($ext ? ".{$ext}" : '');
    $destPath  = $baseDir . '/' . $storeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'Upload failed']);
      exit;
    }

    // Public URL (relative στη /public)
    $publicPath = '/uploads/specs/' . $storeName;

    // Καταχώριση
    $sql = "INSERT INTO thesis_resources (id, thesis_id, `type`, `path`, filename, mimetype, file_size, uploaded_at)
            VALUES (UUID(), ?, ?, ?, ?, ?, ?, NOW())";
    $st  = $pdo->prepare($sql);
    $st->execute([$thesis_id, $kind, $publicPath, $origName, $mime, $size]);

    echo json_encode([
      'ok'   => true,
      'data' => [
        'type'       => $kind,
        'url'        => $publicPath,
        'filename'   => $origName,
        'mimetype'   => $mime,
        'file_size'  => $size,
        'created_at' => date('Y-m-d H:i:s'),
      ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ---- MODE B: link (URL) ----
  $url = $asJson['url'] ?? ($_POST['url'] ?? ($asJson['url_or_path'] ?? ($_POST['url_or_path'] ?? '')));
  if ($url === '' || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid URL (must start with http/https)']);
    exit;
  }
  $title = $asJson['title'] ?? ($_POST['title'] ?? null);

  $sql = "INSERT INTO thesis_resources (id, thesis_id, `type`, `path`, filename, mimetype, file_size, uploaded_at)
          VALUES (UUID(), ?, 'link', ?, ?, NULL, NULL, NOW())";
  $st  = $pdo->prepare($sql);
  $st->execute([$thesis_id, $url, $title]);

  echo json_encode([
    'ok'   => true,
    'data' => [
      'type'       => 'link',
      'url'        => $url,
      'filename'   => $title,
      'created_at' => date('Y-m-d H:i:s'),
    ]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error'], JSON_UNESCAPED_UNICODE);
}
