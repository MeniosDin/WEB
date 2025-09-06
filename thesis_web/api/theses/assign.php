<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('teacher');
$in = read_json();
$topic_id = must('topic_id', $in);
$student_id = must('student_id', $in);


$pdo = db();
// Βεβαίωση ότι ο teacher είναι supervisor του topic
$chk = $pdo->prepare('SELECT 1 FROM topics WHERE id=? AND supervisor_id=?');
$chk->execute([$topic_id, $u['id']]);
if (!$chk->fetch()) fail('Not your topic', 403);


$stmt = $pdo->prepare('INSERT INTO theses(id, student_id, topic_id, supervisor_id, status) VALUES (UUID(),?,?,?,"under_assignment")');
$stmt->execute([$student_id, $topic_id, $u['id']]);
ok(['thesis_created'=>true]);
?>