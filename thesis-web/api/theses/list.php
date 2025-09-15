<?php
// /api/theses/list.php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('student');

$st = $pdo->prepare("
  SELECT t.id, t.topic_id, t.status, t.created_at, t.assigned_at
  FROM theses t
  WHERE t.student_id = ?
  ORDER BY t.created_at DESC
");
$st->execute([$u['id']]);
ok(['items' => $st->fetchAll()]);
