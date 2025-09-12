<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('student');

$st = $pdo->prepare("
  SELECT t.id, t.topic_id, t.status, t.created_at
  FROM theses t
  WHERE t.student_id = ?
  ORDER BY t.created_at DESC
");
$st->execute([$u['id']]);
ok(['items' => $st->fetchAll()]);
