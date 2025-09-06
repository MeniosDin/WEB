<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('teacher');
$in = read_json();
$inv_id = must('invitation_id', $in);
$answer = must('answer', $in); // accepted | declined


$pdo = db();
// map user -> persons.id
$pstmt = $pdo->prepare('SELECT id FROM persons WHERE user_id=?');
$pstmt->execute([$u['id']]);
$me = $pstmt->fetch();
if (!$me) fail('No person mapping for user', 400);


$upd = $pdo->prepare('UPDATE committee_invitations SET status=?, responded_at=NOW() WHERE id=? AND person_id=?');
$upd->execute([$answer, $inv_id, $me['id']]);
ok(['updated'=>true]);
?>