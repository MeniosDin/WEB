<?php
// /api/resources/list.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  ensure_logged_in();

  $thesis_id = isset($_GET['thesis_id']) ? trim($_GET['thesis_id']) : '';
  if ($thesis_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'thesis_id is required']);
    exit;
  }

  $kind = isset($_GET['kind']) ? trim($_GET['kind']) : (isset($_GET['type']) ? trim($_GET['type']) : '');
  $allowedKinds = ['draft','link'];

  // Όλα τα relative paths σερβίρονται κάτω από /thesis-web/public
  $PUBLIC_PREFIX = '/thesis-web/public';

  $toPublicUrl = function (?string $path) use ($PUBLIC_PREFIX): ?string {
    if (!$path) return null;
    if (preg_match('#^https?://#i', $path)) return $path;
    if (stripos($path, '/thesis-web/') === 0) return $path;
    if ($path[0] === '/') return rtrim($PUBLIC_PREFIX, '/') . $path;
    return rtrim($PUBLIC_PREFIX, '/') . '/' . ltrim($path, '/');
  };

  $sql = "SELECT id, thesis_id, `type`, `path`, filename, mimetype, file_size, uploaded_at
            FROM thesis_resources
           WHERE thesis_id = ?";
  $params = [$thesis_id];

  if ($kind !== '' && in_array($kind, $allowedKinds, true)) {
    $sql .= " AND `type` = ?";
    $params[] = $kind;
  }
  $sql .= " ORDER BY uploaded_at DESC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $items = array_map(function(array $r) use ($toPublicUrl) {
    return [
      'id'         => $r['id'],
      'thesis_id'  => $r['thesis_id'],
      'type'       => $r['type'],
      'url'        => $toPublicUrl($r['path']),
      'path'       => $r['path'],
      'filename'   => $r['filename'],
      'mimetype'   => $r['mimetype'],
      'file_size'  => is_null($r['file_size']) ? null : (int)$r['file_size'],
      'created_at' => $r['uploaded_at'],
    ];
  }, $rows);

  echo json_encode(['ok'=>true, 'data'=>['items'=>$items]], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error'], JSON_UNESCAPED_UNICODE);
}
