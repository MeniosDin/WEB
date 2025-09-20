<?php
// /api/theses/cancel.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* ---------- JSON helpers ---------- */
function j_ok($data = [], int $code = 200){
  http_response_code($code);
  echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
function j_err($msg, int $code = 400, $extra = []){
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg,'extra'=>$extra], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Bootstrap / DB / Auth ---------- */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_role('teacher');   // ['id'=>..., 'role'=>'teacher']
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  j_err('Μη αποδεκτή μέθοδος.');
}

/* ---------- Input ---------- */
$thesis_id      = trim((string)($_POST['thesis_id'] ?? ''));
$council_number = trim((string)($_POST['council_number'] ?? '')); // αριθμός ΓΣ που δίνει ο καθηγητής για την ΑΚΥΡΩΣΗ
$council_year   = trim((string)($_POST['council_year'] ?? ''));

if ($thesis_id === '')                j_err('Λείπει thesis_id.', 422);
if ($council_number === '')           j_err('Δώσε αριθμό Γ.Σ.', 422);
if ($council_year === '' || !preg_match('/^\d{4}$/', $council_year)) {
  j_err('Δώσε έγκυρο έτος Γ.Σ. (π.χ. 2027).', 422);
}

/* ---------- Tx ---------- */
$pdo->beginTransaction();

try {
  // Κλείδωμα γραμμής
  $st = $pdo->prepare("
    SELECT id, topic_id, supervisor_id, status
    FROM theses
    WHERE id = :id
    FOR UPDATE
  ");
  $st->execute([':id'=>$thesis_id]);
  $th = $st->fetch(PDO::FETCH_ASSOC);

  if (!$th) {
    $pdo->rollBack();
    j_err('Η διπλωματική δεν βρέθηκε.', 404);
  }
  if ((string)$th['supervisor_id'] !== (string)$me['id']) {
    $pdo->rollBack();
    j_err('Επιτρέπεται μόνο στον επιβλέποντα.', 403);
  }

  // Επιτρεπτές καταστάσεις για ακύρωση σύμφωνα με την εκφώνηση
  if (!in_array($th['status'], ['under_assignment','active'], true)) {
    $pdo->rollBack();
    j_err('Η ακύρωση επιτρέπεται μόνο πριν περάσει σε under_review/ολοκλήρωση.', 409);
  }

  // ⚠️ Καμιά λογική «2 έτη» εδώ.

  // Καθάρισε προσκλήσεις/μέλη (best-effort)
  foreach ([
    ["DELETE FROM committee_invitations WHERE thesis_id = :id", [':id'=>$thesis_id]],
    ["DELETE FROM committee_members      WHERE thesis_id = :id", [':id'=>$thesis_id]],
  ] as [$sql,$p]) {
    try { $pdo->prepare($sql)->execute($p); } catch (Throwable $e) {}
  }

  // Ακύρωση με σωστές στήλες του schema:
  // theses.canceled_gs_number, theses.canceled_gs_year, theses.canceled_reason
  $upd = $pdo->prepare("
    UPDATE theses
       SET status = 'canceled',
           canceled_gs_number = :cnum,
           canceled_gs_year   = :cy,
           canceled_reason    = 'teacher',
           updated_at = NOW()
     WHERE id = :id
  ");
  $upd->execute([
    ':cnum' => $council_number,
    ':cy'   => (int)$council_year,
    ':id'   => $thesis_id,
  ]);

  // Κάνε το θέμα διαθέσιμο ξανά
  if (!empty($th['topic_id'])) {
    try {
      $pdo->prepare("UPDATE topics SET is_available = 1, updated_at = NOW() WHERE id = :tid")
          ->execute([':tid'=>$th['topic_id']]);
    } catch (Throwable $e) {}
  }

  $pdo->commit();
  j_ok(['canceled' => true]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  j_err('SQL error: '.$e->getMessage(), 500);
}
