<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('student');
$in = read_json();
$thesis_id = must('thesis_id', $in);
$person_id = must('person_id', $in); // persons.id (εσωτερικός/εξωτερικός)


$pdo = db();
// Εξασφάλιση ότι ο φοιτητής ανήκει στη συγκεκριμένη ΔΕ και status under_assignment
$chk = $pdo->prepare('SELECT 1 FROM theses WHERE id=? AND student_id=? AND status="under_assignment"');
$chk->execute([$thesis_id, $u['id']]);
if (!$chk->fetch()) fail('Not allowed at this state', 403);


$stmt = $pdo->prepare('INSERT INTO committee_invitations(id, thesis_id, person_id) VALUES (UUID(),?,?)');
$stmt->execute([$thesis_id, $person_id]);
ok(['invited'=>true]);
?>