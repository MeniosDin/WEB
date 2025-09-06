<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('student');
$in = read_json();
$thesis_id = must('thesis_id', $in);
$when_dt = must('when_dt', $in); // "YYYY-MM-DD HH:MM:SS"
$mode = must('mode', $in); // in_person | online
$room_or_link = must('room_or_link', $in);


$pdo = db();
$chk = $pdo->prepare('SELECT 1 FROM theses WHERE id=? AND student_id=? AND status="under_review"');
$chk->execute([$thesis_id, $u['id']]);
if (!$chk->fetch()) fail('Thesis not under_review or not yours', 403);


$stmt = $pdo->prepare('INSERT INTO presentation(id, thesis_id, when_dt, mode, room_or_link) VALUES (UUID(),?,?,?,?) ON DUPLICATE KEY UPDATE when_dt=VALUES(when_dt), mode=VALUES(mode), room_or_link=VALUES(room_or_link)');
$stmt->execute([$thesis_id, $when_dt, $mode, $room_or_link]);
ok(['scheduled'=>true]);
?>