<?php
// api/presentation/get.php
require_once __DIR__ . '/../bootstrap.php';

// επιτρέπουμε: student, teacher, secretariat
$u = require_role('student','teacher','secretariat');

$thesis_id = trim((string)($_GET['thesis_id'] ?? ''));
if ($thesis_id === '') bad('thesis_id required', 422);

// έλεγχος πρόσβασης ανά ρόλο
if ($u['role'] === 'student') {
  assert_student_owns_thesis($pdo, $thesis_id, $u['id']);
} elseif ($u['role'] === 'teacher') {
  // επιβλέπων ή μέλος τριμελούς
  $chk = $pdo->prepare("
    SELECT 1
    FROM theses t
    WHERE t.id = ?
      AND (
        t.supervisor_id = ?
        OR EXISTS (
          SELECT 1
          FROM committee_members cm
          JOIN persons p ON p.id = cm.person_id
          WHERE cm.thesis_id = t.id AND p.user_id = ?
        )
      )
    LIMIT 1
  ");
  $chk->execute([$thesis_id, $u['id'], $u['id']]);
  if (!$chk->fetchColumn()) bad('Forbidden', 403);
}

// φέρε παρουσίαση (αν υπάρχει)
$st = $pdo->prepare("
  SELECT thesis_id, when_dt, mode, room_or_link, published_at
  FROM presentation
  WHERE thesis_id = ?
  LIMIT 1
");
$st->execute([$thesis_id]);

ok(['item' => $st->fetch() ?: null]);
