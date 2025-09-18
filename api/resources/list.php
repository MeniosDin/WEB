<?php
// /api/resources/list.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utils/bootstrap.php';
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  ensure_logged_in(); // έλεγχος session

  // Required
  $thesis_id = isset($_GET['thesis_id']) ? trim($_GET['thesis_id']) : '';
  if ($thesis_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'thesis_id is required']);
    exit;
  }

  // Optional kind/type filter
  $kind = isset($_GET['kind']) ? trim($_GET['kind']) : '';
  if ($kind === '' && isset($_GET['type'])) {
    $kind = trim($_GET['type']); // δέχεται και ?type=
  }
  $allowedKinds = ['draft','link'];

  // -------- Ρύθμιση public prefix για URLs --------
  // ΣΤΟ ΔΙΚΟ ΣΟΥ setup τα δημόσια αρχεία σερβίρονται από:
  //   http://localhost/thesis-web/public/...
  // Άρα όλα τα relative paths (π.χ. "uploads/theses/...") θα γίνουν:
  //   /thesis-web/public/uploads/theses/...
  $PUBLIC_PREFIX = '/thesis-web/public';

  // Βοηθητικό: μετατρέπει path από DB σε ασφαλές public URL
  $toPublicUrl = function (?string $path) use ($PUBLIC_PREFIX): ?string {
    if (!$path) return null;

    // απόλυτος http(s) σύνδεσμος: κράτα όπως είναι
    if (preg_match('#^https?://#i', $path)) {
      return $path;
    }

    // ήδη ξεκινάει από /thesis-web/ => άφησέ το
    if (stripos($path, '/thesis-web/') === 0) {
      return $path;
    }

    // αν ξεκινά με '/', τότε είναι μονοπάτι τύπου '/uploads/...'
    if ($path[0] === '/') {
      return rtrim($PUBLIC_PREFIX, '/') . $path; // /thesis-web/public + /uploads/...
    }

    // αλλιώς είναι 'uploads/...'
    return rtrim($PUBLIC_PREFIX, '/') . '/' . ltrim($path, '/');
  };

  // -------- Query --------
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

  // normalize
  $items = array_map(function(array $r) use ($toPublicUrl) {
    return [
      'id'         => $r['id'],
      'thesis_id'  => $r['thesis_id'],
      'type'       => $r['type'],               // 'draft' | 'link'
      'url'        => $toPublicUrl($r['path']), // τελικό URL για <a href=...>
      'path'       => $r['path'],               // raw DB τιμή
      'filename'   => $r['filename'],
      'mimetype'   => $r['mimetype'],
      'file_size'  => is_null($r['file_size']) ? null : (int)$r['file_size'],
      'created_at' => $r['uploaded_at'],
    ];
  }, $rows);

  echo json_encode([
    'ok'   => true,
    'data' => ['items' => $items]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server error']);
}
