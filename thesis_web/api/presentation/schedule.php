<?php
require_once __DIR__ . '/../bootstrap.php';
$u = _require_login();

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST ?: [];
$thesis_id   = trim($in['thesis_id'] ?? '');
$when_dt     = trim($in['when_dt'] ?? '');
$mode        = trim($in['mode'] ?? 'in_person');
$room_or_link= trim($in['room_or_link'] ?? '');

if ($thesis_id==='') { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }
if ($when_dt==='' || $room_or_link===''){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'when_dt & room_or_link required']); exit; }
if (!in_array($mode,['in_person','online'],true)){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'invalid mode']); exit; }

_assert_student_owns_thesis($pdo, $thesis_id, $u['id']);

try{
  // upsert
  $pdo->prepare("INSERT INTO presentation(thesis_id, when_dt, mode, room_or_link)
                 VALUES(?,?,?,?)
                 ON DUPLICATE KEY UPDATE when_dt=VALUES(when_dt), mode=VALUES(mode), room_or_link=VALUES(room_or_link)")
      ->execute([$thesis_id, $when_dt, $mode, $room_or_link]);
  echo json_encode(['ok'=>true]);
} catch(Throwable $e){
  // π.χ. triggers για 21–60 ημέρες
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
