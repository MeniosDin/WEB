<?php
declare(strict_types=1);

/* ---------- JSON helpers ---------- */
function j_ok($data = [], int $code = 200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
function j_err($msg, int $code = 400, $extra = []){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>$msg,'extra'=>$extra], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Bootstrap / DB / Auth ---------- */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_role('teacher'); // επιστρέφει τον συνδεδεμένο χρήστη (role=teacher)
$pdo = db();                    // PDO

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') j_err('Μη αποδεκτή μέθοδος.', 405);
$thesis_id = trim((string)($_POST['thesis_id'] ?? ''));
if ($thesis_id === '') j_err('Λάθος thesis_id.', 422);

/* ---------- Λογική ---------- */
try {
  $pdo->beginTransaction();

  // Φόρτωση & κλείδωμα της διπλωματικής
  $st = $pdo->prepare("SELECT id, topic_id, supervisor_id, status
                       FROM theses
                       WHERE id = :id
                       FOR UPDATE");
  $st->execute([':id' => $thesis_id]);
  $thesis = $st->fetch(PDO::FETCH_ASSOC);
  if (!$thesis) { $pdo->rollBack(); j_err('Η διπλωματική δεν βρέθηκε.', 404); }

  // Μόνο ο επιβλέπων & μόνο όταν είναι under_assignment
  if ((string)$thesis['supervisor_id'] !== (string)$me['id']) {
    $pdo->rollBack(); j_err('Μη εξουσιοδοτημένος χρήστης (όχι επιβλέπων).', 403);
  }
  if ($thesis['status'] !== 'under_assignment') {
    $pdo->rollBack(); j_err('Η διπλωματική δεν είναι under_assignment.', 409);
  }

  $topic_id = (string)$thesis['topic_id'];

  // Διαγραφή τυχόν προσκλήσεων/αναθέσεων τριμελούς (αν υπάρχουν πίνακες)
  try { $pdo->prepare("DELETE FROM committee_invitations WHERE thesis_id = :id")->execute([':id'=>$thesis_id]); } catch (Throwable $e) {}
  try { $pdo->prepare("DELETE FROM committee_members      WHERE thesis_id = :id")->execute([':id'=>$thesis_id]); } catch (Throwable $e) {}

  // Μαρκάρισμα της διπλωματικής ως canceled
  $pdo->prepare("UPDATE theses
                    SET status = 'canceled',
                        updated_at = NOW()
                  WHERE id = :id")
      ->execute([':id' => $thesis_id]);

  // ❗ ΚΑΘΑΡΙΣΜΑ «Προσωρινής ανάθεσης» στο ΘΕΜΑ + διαθέσιμο
  if ($topic_id !== '') {
    $pdo->prepare("
      UPDATE topics
         SET is_available = 1,
             provisional_student_id  = NULL,
             provisional_assigned_at = NULL,
             provisional_since       = NULL,
             updated_at = NOW()
       WHERE id = :tid
    ")->execute([':tid' => $topic_id]);

    // αν έχεις πίνακα κρατήσεων, καθάρισέ τον (προαιρετικό)
    try { $pdo->prepare("DELETE FROM topic_reservations WHERE topic_id = :tid")->execute([':tid'=>$topic_id]); } catch (Throwable $e) {}
  }

  $pdo->commit();
  j_ok(['thesis_id'=>$thesis_id,'topic_id'=>$topic_id,'status'=>'canceled']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  j_err($e->getMessage(), 500);
}
