<?php
// /api/resources/create.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  ensure_logged_in(); // έλεγχος session

  // Helper: καθαρό JSON body όταν δεν είναι multipart
  $raw = file_get_contents('php://input');
  $asJson = json_decode($raw, true);
  $isMultipart = !empty($_FILES);

  // --- Κοινές παράμετροι ---
  $thesis_id = $isMultipart
    ? ($_POST['thesis_id'] ?? '')
    : ($asJson['thesis_id'] ?? ($_POST['thesis_id'] ?? ''));

  $kind = $isMultipart
    ? (($_POST['kind'] ?? 'draft'))
    : (($asJson['kind'] ?? ($_POST['kind'] ?? 'link')));

  // Χρησιμοποιούμε 'draft' για αρχεία φοιτητή, 'link' για URLs
  $kind = ($kind === 'link') ? 'link' : 'draft';

  if ($thesis_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'thesis_id is required']);
    exit;
  }

  // --- MODE A: multipart (ανέβασμα αρχείου) ---
  if ($isMultipart) {
    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'file is required']);
      exit;
    }

    $file = $_FILES['file'];
    $origName = basename($file['name']);
    $mime = $file['type'] ?? null;
    $size = (int)($file['size'] ?? 0);

    // Προαιρετικοί έλεγχοι τύπων/μεγέθους
    $maxBytes = 20 * 1024 * 1024; // 20MB
    if ($size <= 0 || $size > $maxBytes) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'Invalid file size']);
      exit;
    }

    // Υπολογισμός φυσικού προορισμού (ΜΕΣΑ στο /public)
    $baseDir = dirname(__DIR__, 2) . '/public/uploads/theses/' . $thesis_id;
    if (!is_dir($baseDir)) {
      if (!mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>'Failed to create upload directory']);
        exit;
      }
    }

    // Όνομα αρχείου στον δίσκο
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $stamp = date('Ymd_His');
    $storeName = ($kind === 'draft' ? "draft_{$stamp}" : "res_{$stamp}");
    if ($ext !== '') $storeName .= '.' . $ext;

    $destPath = $baseDir . '/' . $storeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
      http_response_code(500);
      echo json_encode(['ok'=>false, 'error'=>'Upload failed']);
      exit;
    }

    // URL που θα σερβίρει ο web server
    $publicPath = '/uploads/theses/' . $thesis_id . '/' . $storeName;

    // Εισαγωγή στη thesis_resources
    $sql = "INSERT INTO thesis_resources
              (id, thesis_id, `type`, `path`, filename, mimetype, file_size, uploaded_at)
            VALUES (UUID(), ?, ?, ?, ?, ?, ?, NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([$thesis_id, $kind, $publicPath, $origName, $mime, $size]);

    echo json_encode([
      'ok' => true,
      'data' => [
        'id'         => $pdo->lastInsertId() ?: null, // σε MariaDB με UUID() μπορεί να γυρίσει null, δεν πειράζει
        'type'       => $kind,
        'url'        => $publicPath,
        'filename'   => $origName,
        'mimetype'   => $mime,
        'file_size'  => $size,
        'created_at' => date('Y-m-d H:i:s'),
      ]
    ]);
    exit;
  }

  // --- MODE B: link (URL) ---
  $url = $asJson['url'] ?? ($_POST['url'] ?? ($asJson['url_or_path'] ?? ($_POST['url_or_path'] ?? '')));
  if ($url === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'url is required']);
    exit;
  }

  // Βάλε http/https μόνο
  if (!preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Invalid URL (must start with http/https)']);
    exit;
  }

  // Προαιρετικός τίτλος
  $title = $asJson['title'] ?? ($_POST['title'] ?? null);

  $sql = "INSERT INTO thesis_resources
            (id, thesis_id, `type`, `path`, filename, mimetype, file_size, uploaded_at)
          VALUES (UUID(), ?, 'link', ?, ?, NULL, NULL, NOW())";
  $st = $pdo->prepare($sql);
  // για link: path = ίδιο το URL, filename = τίτλος (αν θες να φαίνεται ωραία), mimetype/file_size null
  $st->execute([$thesis_id, $url, $title]);

  echo json_encode([
    'ok' => true,
    'data' => [
      'type'       => 'link',
      'url'        => $url,
      'filename'   => $title,
      'created_at' => date('Y-m-d H:i:s'),
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'server error']);
}
