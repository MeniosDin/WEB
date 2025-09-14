<?php
header('Content-Type: application/json; charset=UTF-8');

try {
  // Γύρισέ το σε false όταν κουμπώσεις DB & guards
  define('MOCK_MODE', true);

  $thesis_id = $_GET['thesis_id'] ?? null;
  if (!$thesis_id) { echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }

  if (MOCK_MODE) {
    // Κενό αλλά OK → το UI δεν θα γράφει "server error"
    echo json_encode(['ok'=>true,'item'=>null], JSON_UNESCAPED_UNICODE); 
    exit;
  }

  @require_once __DIR__ . '/../utils/bootstrap.php';
  @require_once __DIR__ . '/../utils/auth_guard.php';

  // Fallbacks για dev ώστε να μη ρίχνουν fatal
  if (!function_exists('ensure_logged_in'))        { function ensure_logged_in(){} }
  if (!function_exists('assert_student_owns_thesis')) { function assert_student_owns_thesis($x){ return true; } }

  ensure_logged_in();
  assert_student_owns_thesis($thesis_id);

  $item = null;
  if (isset($pdo)) {
    // Προσαρμόσε το σε δικά σου table/columns αν διαφέρουν
    $sql = "SELECT when_dt, mode, room_or_link, published_at
            FROM presentations
            WHERE thesis_id = ?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$thesis_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $item = $row;
  }

  echo json_encode(['ok'=>true,'item'=>$item], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
