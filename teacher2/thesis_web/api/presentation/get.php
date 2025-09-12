<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);
if ($u['role'] === 'student') { assert_student_owns_thesis($pdo, $thesis_id, $u['id']); }

$st = $pdo->prepare("
  SELECT thesis_id, when_dt, mode, room_or_link, published_at
  FROM presentation WHERE thesis_id=? LIMIT 1
");
$st->execute([$thesis_id]);
ok(['item' => $st->fetch() ?: null]);
