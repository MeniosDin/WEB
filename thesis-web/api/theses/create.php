<?php
require_once __DIR__ . '/_bootstrap.php';
require_role('student');

$data = read_json_body();
$topic_id = (string)($data['topic_id'] ?? '');

if ($topic_id === '') json_err('topic_id is required', 422);

// find supervisor from topic
$stm = $pdo->prepare("SELECT supervisor_id FROM topics WHERE id = :id");
$stm->execute([':id'=>$topic_id]);
$topic = $stm->fetch();
if (!$topic) json_err('Topic not found', 404);

// ensure no other active/under_review/under_assignment thesis for this student
$chk = $pdo->prepare("
  SELECT COUNT(*) AS c FROM theses
  WHERE student_id = :sid AND status IN ('under_assignment','active','under_review')
");
$chk->execute([':sid'=>$user['id']]);
if ((int)$chk->fetch()['c'] > 0) json_err('You already have an active thesis', 409);

$id = null;
$pdo->beginTransaction();
try {
  $id = bin2hex(random_bytes(16));
  $ins = $pdo->prepare("
    INSERT INTO theses (id, student_id, topic_id, supervisor_id, status)
    VALUES (UNHEX(REPLACE(:id, '-', '')), :sid, :tid, :sup, 'under_assignment')
  ");
  // Above UNHEX trick expects hex-uuid; we prefer to just use UUID() server-side:
  $ins = $pdo->prepare("
    INSERT INTO theses (id, student_id, topic_id, supervisor_id, status)
    VALUES (UUID(), :sid, :tid, :sup, 'under_assignment')
  ");
  $ins->execute([':sid'=>$user['id'], ':tid'=>$topic_id, ':sup'=>$topic['supervisor_id']]);
  // fetch the id we just created
  $id = $pdo->query("SELECT id FROM theses WHERE student_id=".$pdo->quote($user['id'])." ORDER BY created_at DESC LIMIT 1")->fetchColumn();
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  json_err('DB error: '.$e->getMessage(), 500);
}

json_ok(['thesis_id'=>$id], 201);
