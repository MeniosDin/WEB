<?php
// /api/grades/mine.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  $me = require_login();
  $person_id = (string)$me['id'];
  $thesis_id = trim((string)($_GET['thesis_id'] ?? ''));

  if ($thesis_id === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }

  $st = $pdo->prepare("SELECT thesis_id, person_id, rubric_id, criteria_scores_json, total, created_at
                         FROM grades
                        WHERE thesis_id = ? AND person_id = ?");
  $st->execute([$thesis_id, $person_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) { echo json_encode(['ok'=>true,'data'=>null]); return; }

  $row['criteria'] = $row['criteria_scores_json'] ? json_decode($row['criteria_scores_json'], true) : null;
  unset($row['criteria_scores_json']);

  echo json_encode(['ok'=>true,'data'=>$row], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
