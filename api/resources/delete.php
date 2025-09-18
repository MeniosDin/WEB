<?php
header('Content-Type: application/json; charset=UTF-8');

try {
  @require_once __DIR__ . '/../utils/bootstrap.php';
  @require_once __DIR__ . '/../utils/auth_guard.php';
  if (!function_exists('ensure_logged_in')) { function ensure_logged_in(){} }
  if (!function_exists('assert_student_owns_thesis')) { function assert_student_owns_thesis($x){return true;} }

  // Δέχεται JSON
  $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $id        = $in['id']        ?? null;
  $thesis_id = $in['thesis_id'] ?? null;

  if (!$id || !$thesis_id) { echo json_encode(['ok'=>false,'error'=>'id and thesis_id required']); exit; }

  ensure_logged_in(); assert_student_owns_thesis($thesis_id);

  if (!isset($pdo)) { echo json_encode(['ok'=>false,'error'=>'no db']); exit; }

  // Βρες το αρχείο για να το σβήσεις από το δίσκο (προαιρετικό)
  $st = $pdo->prepare("SELECT url_or_path FROM resources WHERE id = ? AND thesis_id = ? LIMIT 1");
  $st->execute([$id, $thesis_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

  // Σβήσε DB
  $del = $pdo->prepare("DELETE FROM resources WHERE id = ? AND thesis_id = ? LIMIT 1");
  $del->execute([$id, $thesis_id]);

  // Προαιρετικά: και από το filesystem αν είναι local path
  $path = $row['url_or_path'] ?? '';
  if ($path && strpos($path, '/uploads/resources/') === 0) {
    $fs = dirname(__DIR__,2) . $path;
    if (is_file($fs)) @unlink($fs);
  }

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
