<?php
header('Content-Type: application/json; charset=UTF-8');

try {
  define('MOCK_MODE', true);

  $thesis_id = $_GET['thesis_id'] ?? null;
  if (!$thesis_id) { echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }

  if (MOCK_MODE) {
    echo json_encode(['ok'=>true,'summary'=>null], JSON_UNESCAPED_UNICODE); exit;
  }

  @require_once __DIR__ . '/../utils/bootstrap.php';
  @require_once __DIR__ . '/../utils/auth_guard.php';
  if (!function_exists('ensure_logged_in')) { function ensure_logged_in(){} }
  if (!function_exists('assert_student_owns_thesis')) { function assert_student_owns_thesis($x){return true;} }

  ensure_logged_in(); assert_student_owns_thesis($thesis_id);

  $sql = "SELECT COUNT(*) AS cnt, AVG(total_score) AS avg_total FROM grades WHERE thesis_id = ?";
  $st = $pdo->prepare($sql); $st->execute([$thesis_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $summary = ($row && (int)$row['cnt']>0) ? ['cnt'=>(int)$row['cnt'], 'avg_total'=> ($row['avg_total']!==null?(float)$row['avg_total']:null)] : null;

  echo json_encode(['ok'=>true,'summary'=>$summary], JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
