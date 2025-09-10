<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);
if ($u['role'] === 'student') { assert_student_owns_thesis($pdo, $thesis_id, $u['id']); }

$st = $pdo->prepare("
  SELECT event_type, from_status, to_status, details, created_at
  FROM events_log
  WHERE thesis_id=?
  ORDER BY created_at DESC
");
$st->execute([$thesis_id]);
ok(['items' => $st->fetchAll()]);
