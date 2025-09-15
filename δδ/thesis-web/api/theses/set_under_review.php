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

$me  = require_role('teacher'); // π.χ. ['id'=>..., 'role'=>'teacher']
$pdo = db();

/* ---------- Params ---------- */
$thesis_id = $_POST['thesis_id'] ?? $_GET['thesis_id'] ?? '';
if (!$thesis_id) j_err('Λείπει thesis_id', 422);

/* ---------- Επιχειρητικός κανόνας ---------- */
/* Επιτρέπουμε μόνο αν είσαι επιβλέπων στη συγκεκριμένη διπλωματική */
$st = $pdo->prepare("
  SELECT t.id, t.status, cm.role_in_committee
  FROM theses t
  JOIN committee_members cm
    ON cm.thesis_id = t.id
   AND cm.person_id = :uid
  WHERE t.id = :tid
  LIMIT 1
");
$st->execute([':uid'=>$me['id'], ':tid'=>$thesis_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  j_err('Δεν έχεις δικαίωμα στη διπλωματική.', 403);
}
if (($row['role_in_committee'] ?? '') !== 'supervisor') {
  j_err('Μόνο ο επιβλέπων μπορεί να αλλάξει σε «Υπό εξέταση».', 403);
}

/* idempotent: αν είναι ήδη under_review απάντησε ΟΚ */
if (($row['status'] ?? '') === 'under_review') {
  j_ok(['thesis_id'=>$thesis_id, 'status'=>'under_review']);
}

/* (προαιρετικό) έλεγχος επιτρεπόμενων μεταβάσεων */
$allowed_from = ['active', 'under_assignment']; // προσαρμόστε αν θέλετε
if ($row['status'] !== null && !in_array($row['status'], $allowed_from, true)) {
  j_err('Μη έγκυρη μετάβαση από «'.$row['status'].'» σε «under_review».', 409);
}

/* ---------- Update status + timeline ---------- */
$pdo->beginTransaction();
try {
  $u = $pdo->prepare("
    UPDATE theses
       SET status = 'under_review', updated_at = NOW()
     WHERE id = :tid
    LIMIT 1
  ");
  $u->execute([':tid'=>$thesis_id]);

  // Καταγραφή event (αν υπάρχει ο πίνακας thesis_events)
  try {
    $log = $pdo->prepare("
      INSERT INTO thesis_events(thesis_id, event_type, from_status, to_status, created_by)
      VALUES(:tid, 'status_change', :from, 'under_review', :uid)
    ");
    $log->execute([
      ':tid'=>$thesis_id,
      ':from'=>$row['status'] ?? null,
      ':uid'=>$me['id']
    ]);
  } catch (Throwable $e) {
    // Αν δεν υπάρχει ο πίνακας, μην αποτύχει όλη η συναλλαγή — προχώρα μόνο με το update
  }

  $pdo->commit();
  j_ok(['thesis_id'=>$thesis_id, 'status'=>'under_review']);
} catch (Throwable $e) {
  $pdo->rollBack();
  j_err('Αποτυχία ενημέρωσης', 500, ['ex'=>$e->getMessage()]);
}
