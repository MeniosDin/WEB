<?php
// /api/presentation/publish.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  // ιδανικά: require_role('teacher','supervisor');
  require_login();

  $thesis_id = trim($_POST['thesis_id'] ?? '');
  if ($thesis_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit;
  }

  $st = $pdo->prepare("UPDATE presentations SET published_at = NOW() WHERE thesis_id = ?");
  $st->execute([$thesis_id]);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
