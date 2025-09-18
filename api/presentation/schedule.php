<?php
header('Content-Type: application/json; charset=utf-8');
@require_once __DIR__ . '/../utils/bootstrap.php';
@require_once __DIR__ . '/../utils/auth_guard.php';

try {
  ensure_logged_in();

  $data = $_POST;
  if (empty($data)) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
  }

  $thesis_id    = $data['thesis_id']    ?? null;
  $when_dt      = $data['when_dt']      ?? null;
  $mode         = $data['mode']         ?? 'in_person';
  $room_or_link = $data['room_or_link'] ?? '';

  if (!$thesis_id || !$when_dt) {
    echo json_encode(['ok'=>false,'error'=>'thesis_id and when_dt required']); exit;
  }

  // αποθήκευση (αν υπάρχει ήδη, κάνε UPDATE)
  $sql = "INSERT INTO presentations (thesis_id, when_dt, mode, room_or_link, published_at)
          VALUES (?,?,?,?,NOW())
          ON DUPLICATE KEY UPDATE when_dt=VALUES(when_dt),
              mode=VALUES(mode), room_or_link=VALUES(room_or_link)";
  $st = $pdo->prepare($sql);
  $st->execute([$thesis_id,$when_dt,$mode,$room_or_link]);

  echo json_encode(['ok'=>true,'item'=>[
    'when_dt'=>$when_dt,
    'mode'=>$mode,
    'room_or_link'=>$room_or_link,
    'published_at'=>date('c')
  ]], JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
