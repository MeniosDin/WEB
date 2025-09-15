<?php
require_once __DIR__.'/../../api/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$q = $pdo->query("SELECT when_dt, mode, room_or_link, published_at,
thesis_id, topic_title, student_name, supervisor_name
FROM vw_public_presentations
WHERE published_at IS NOT NULL
ORDER BY when_dt DESC
LIMIT 100");
echo json_encode(['ok'=>true,'items'=>$q->fetchAll()], JSON_UNESCAPED_UNICODE);