<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php'; // db(), ok(), bad(), require_role()
header('Content-Type: application/json; charset=utf-8');

try {
  $me = require_role('teacher');
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bad('Method not allowed', 405);
  }

  $pdo      = db();
  $topicId  = trim((string)($_POST['topic_id'] ?? $_POST['id'] ?? ''));
  $studentIdIn = trim((string)($_POST['student_id'] ?? ''));

  if ($topicId === '') bad('Bad request', 400);

  // Φέρε το topic & επιβεβαίωσε ιδιοκτησία
  $q = $pdo->prepare("
    SELECT id, supervisor_id, provisional_student_id, is_available
      FROM topics
     WHERE id = :id
     LIMIT 1
  ");
  $q->execute([':id' => $topicId]);
  $topic = $q->fetch(PDO::FETCH_ASSOC);
  if (!$topic)                bad('Topic not found', 404);
  if ((string)$topic['supervisor_id'] !== (string)$me['id']) bad('Forbidden', 403);

  // Ποιον φοιτητή θα οριστικοποιήσουμε;
  $studentId = $studentIdIn !== '' ? $studentIdIn : (string)($topic['provisional_student_id'] ?? '');
  if ($studentId === '') bad('No student to assign (missing provisional student)', 422);

  // Βεβαιώσου ότι είναι υπαρκτός φοιτητής
  $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'student' LIMIT 1");
  $chk->execute([$studentId]);
  if (!$chk->fetchColumn()) bad('Student not found', 404);

  $pdo->beginTransaction();

  // Υπάρχει ήδη thesis γι’ αυτό το (topic, student);
  $sel = $pdo->prepare("
    SELECT id, status
      FROM theses
     WHERE topic_id = :tid AND student_id = :sid
     LIMIT 1
  ");
  $sel->execute([':tid' => $topicId, ':sid' => $studentId]);
  $th = $sel->fetch(PDO::FETCH_ASSOC);

  if ($th) {
    // Ήδη υπάρχει -> δεν ξαναφτιάχνουμε, απλώς απαντάμε θετικά
    $pdo->commit();
    ok(['already' => true, 'thesis_id' => $th['id'], 'status' => $th['status']]);
  }

  // Δημιούργησε thesis (υπό ανάθεση)
  $ins = $pdo->prepare("
    INSERT INTO theses (id, topic_id, student_id, supervisor_id, status, created_at, updated_at)
    VALUES (UUID(), :tid, :sid, :sup, 'under_assignment', NOW(), NOW())
  ");
  $ins->execute([
    ':tid' => $topicId,
    ':sid' => $studentId,
    ':sup' => $me['id'],
  ]);

  // (Προαιρετικά) κλείδωσε το topic και καθάρισε την προσωρινή ανάθεση
  $up = $pdo->prepare("
    UPDATE topics
       SET is_available = 0,
           provisional_student_id = NULL,
           provisional_since = NULL,
           updated_at = NOW()
     WHERE id = :tid
  ");
  $up->execute([':tid' => $topicId]);

  $pdo->commit();
  ok(['created' => true]);

} catch (Throwable $e) {
  if ($pdo?->inTransaction()) $pdo->rollBack();
  bad('SQL error: ' . $e->getMessage(), 500);
}
