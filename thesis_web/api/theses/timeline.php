<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);

if ($user['role'] === 'student') {
  $own = $pdo->prepare("SELECT 1 FROM theses WHERE id=? AND student_id=?");
  $own->execute([$thesis_id, $user['id']]);
  if (!$own->fetchColumn()) bad('Forbidden', 403);
}

$st = $pdo->prepare("
  SELECT event_type, from_status, to_status, details, created_at
  FROM events_log
  WHERE thesis_id=?
  ORDER BY created_at DESC
");
$st->execute([$thesis_id]);
ok(['items'=>$st->fetchAll()]);
