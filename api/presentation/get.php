<?php
// /api/presentation/get.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  require_login();

  $thesis_id = trim($_GET['thesis_id'] ?? '');
  if ($thesis_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'thesis_id is required']); exit;
  }


$sql = "SELECT thesis_id, when_dt, mode, room_or_link, published_at
        FROM presentations
        WHERE thesis_id = ?
        LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([$thesis_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'data' => ['item' => $row]]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
