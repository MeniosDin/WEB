<?php
header('Content-Type: application/json; charset=UTF-8');

try {
  define('MOCK_MODE', true);

  $thesis_id = $_GET['thesis_id'] ?? null;
  if (!$thesis_id) { echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }

  if (MOCK_MODE) {
    echo json_encode(['ok'=>true,'items'=>[]], JSON_UNESCAPED_UNICODE); exit;
  }

  @require_once __DIR__ . '/../utils/bootstrap.php';
  @require_once __DIR__ . '/../utils/auth_guard.php';
  if (!function_exists('ensure_logged_in')) { function ensure_logged_in(){} }
  if (!function_exists('assert_student_owns_thesis')) { function assert_student_owns_thesis($x){return true;} }

  ensure_logged_in(); assert_student_owns_thesis($thesis_id);

  $sql = "SELECT u.first_name, u.last_name, u.email, cm.role_in_committee
          FROM committee_members cm JOIN users u ON u.id = cm.user_id
          WHERE cm.thesis_id = ? ORDER BY (cm.role_in_committee='supervisor') DESC, u.last_name ASC";
  $st = $pdo->prepare($sql); $st->execute([$thesis_id]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
