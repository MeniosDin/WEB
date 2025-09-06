<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('teacher');
$in = read_json();
$thesis_id = must('thesis_id', $in);
$rubric_id = must('rubric_id', $in);
$criteria = $in['criteria_scores_json'] ?? null; // object {goals, duration, text, presentation}
if (!$criteria || !is_array($criteria)) fail('criteria_scores_json required as object', 400);


$pdo = db();
// Βρες persons.id για το μέλος
$pstmt = $pdo->prepare('SELECT id FROM persons WHERE user_id=?');
$pstmt->execute([$u['id']]);
$me = $pstmt->fetch();
if (!$me) fail('No person mapping for user', 400);


$sql = 'INSERT INTO grades(id, thesis_id, person_id, rubric_id, criteria_scores_json) VALUES(UUID(),?,?,?,?)
ON DUPLICATE KEY UPDATE rubric_id=VALUES(rubric_id), criteria_scores_json=VALUES(criteria_scores_json)';
$stmt = $pdo->prepare($sql);
$stmt->execute([$thesis_id, $me['id'], $rubric_id, json_encode($criteria, JSON_UNESCAPED_UNICODE)]);
ok(['saved'=>true]);
?>