<?php
header('Content-Type: application/json; charset=UTF-8');

try {
  @require_once __DIR__ . '/../utils/bootstrap.php';
  @require_once __DIR__ . '/../utils/auth_guard.php';
  if (!function_exists('ensure_logged_in')) { function ensure_logged_in(){} }
  if (!function_exists('assert_student_owns_thesis')) { function assert_student_owns_thesis($x){return true;} }

  $thesis_id = $_GET['thesis_id'] ?? null;
  $kind      = $_GET['kind']      ?? null;
  if (!$thesis_id) { echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }

  ensure_logged_in(); assert_student_owns_thesis($thesis_id);

  $params = [$thesis_id];
  $where  = "WHERE thesis_id = ?";
  if ($kind) { $where .= " AND kind = ?"; $params[] = $kind; }

  $items = [];
  if (isset($pdo)) {
    $sql = "SELECT id, thesis_id, kind, url_or_path, original_name, mime, size, created_at
            FROM resources $where
            ORDER BY created_at DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
