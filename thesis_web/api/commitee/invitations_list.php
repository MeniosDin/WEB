<?php
require_once __DIR__ . '/../bootstrap.php';

$user = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);

// Ο φοιτητής βλέπει μόνο τη δική του thesis
if ($user['role'] === 'student') {
  assert_student_owns_thesis($pdo, $thesis_id, $user['id']);
}

$st = $pdo->prepare("
  SELECT id, thesis_id, person_id, status, invited_at, responded_at
  FROM committee_invitations
  WHERE thesis_id=?
  ORDER BY invited_at DESC
");
$st->execute([$thesis_id]);
ok(['items' => $st->fetchAll()]);
