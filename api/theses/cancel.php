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
$thesis_id       = trim((string)($_POST['thesis_id'] ?? ''));
$council_number  = trim((string)($_POST['council_number'] ?? ''));
$council_year    = trim((string)($_POST['council_year'] ?? ''));

if ($thesis_id === '')                j_err('Λείπει thesis_id.', 422);
if ($council_number === '')           j_err('Δώσε αριθμό Γ.Σ.', 422);
if ($council_year === '' || !preg_match('/^\d{4}$/', $council_year)) {
  j_err('Δώσε έγκυρο έτος Γ.Σ. (π.χ. 2027).', 422);
}

/* ---------- helpers ---------- */
function column_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = :t
       AND COLUMN_NAME  = :c
  ");
  $st->execute([':t'=>$table, ':c'=>$col]);
  return (bool)$st->fetchColumn();
}

$hasActiveSince   = column_exists($pdo, 'theses', 'active_since');
$hasFinalAssigned = column_exists($pdo, 'theses', 'final_assigned_at');
$hasCanceledAt    = column_exists($pdo, 'theses', 'canceled_at');
$hasCancelReason  = column_exists($pdo, 'theses', 'cancel_reason');
$hasCouncilNumCol = column_exists($pdo, 'theses', 'council_number');
$hasCouncilYearCol= column_exists($pdo, 'theses', 'council_year');

/* ---------- Load thesis (FOR UPDATE) ---------- */
$select = "SELECT id, topic_id, supervisor_id, status, created_at, updated_at";
if ($hasActiveSince)   $select .= ", active_since";
if ($hasFinalAssigned) $select .= ", final_assigned_at";
$select .= " FROM theses WHERE id = :id FOR UPDATE";

$pdo->beginTransaction();
try {
  $st = $pdo->prepare($select);
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
  if ($th['status'] !== 'active') {
    $pdo->rollBack();
    j_err('Η ακύρωση επιτρέπεται μόνο όταν η διπλωματική είναι active.', 409);
  }

  // Πότε οριστικοποιήθηκε; Προτίμηση: active_since > final_assigned_at > created_at
  $assignedAt = null;
  if ($hasActiveSince   && !empty($th['active_since']))      $assignedAt = $th['active_since'];
  elseif ($hasFinalAssigned && !empty($th['final_assigned_at'])) $assignedAt = $th['final_assigned_at'];
  else                                                       $assignedAt = $th['created_at'];

  $assigned = new DateTime($assignedAt ?: 'now');
  $now      = new DateTime('now');
  $days     = (int)$assigned->diff($now)->days;

  if ($days < 730) { // ~2 έτη
    $pdo->rollBack();
    j_err('Η ακύρωση επιτρέπεται μόνο μετά την παρέλευση 2 ετών από την οριστική ανάθεση.', 409, [
      'assigned_at' => $assigned->format('Y-m-d H:i:s'),
      'days_since'  => $days
    ]);
  }

  // --------- Εκκαθαρίσεις σχετικών δεδομένων (προσκλήσεις/τριμελείς) ----------
  $try = [
    ["DELETE FROM committee_invitations WHERE thesis_id = :id", [':id'=>$thesis_id]],
    ["DELETE FROM committee_members      WHERE thesis_id = :id", [':id'=>$thesis_id]],
    ["DELETE FROM invitations            WHERE thesis_id = :id AND type='committee'", [':id'=>$thesis_id]],
  ];
  foreach ($try as [$sql,$p]) {
    try { $pdo->prepare($sql)->execute($p); } catch (Throwable $e) { /* ignore if table doesn't exist */ }
  }

  // --------- Ακύρωση διπλωματικής ----------
  $sets = ["status = 'canceled'", "updated_at = NOW()"];
  $params = [ ':id' => $thesis_id ];

  if ($hasCanceledAt)   $sets[] = "canceled_at = NOW()";
  if ($hasCancelReason) { $sets[] = "cancel_reason = :reason"; $params[':reason'] = 'teacher'; }
  if ($hasCouncilNumCol){ $sets[] = "council_number = :cnum";  $params[':cnum']   = $council_number; }
  if ($hasCouncilYearCol){$sets[] = "council_year = :cy";      $params[':cy']     = $council_year; }

  $sql = "UPDATE theses SET ".implode(', ',$sets)." WHERE id = :id";
  $pdo->prepare($sql)->execute($params);

  // --------- Κάνε το θέμα διαθέσιμο ξανά ----------
  if (!empty($th['topic_id'])) {
    try {
      $pdo->prepare("UPDATE topics SET is_available = 1, updated_at = NOW() WHERE id = :tid")
          ->execute([':tid' => $th['topic_id']]);
    } catch (Throwable $e) { /* ignore */ }
  }

  $pdo->commit();
  j_ok(['canceled' => true]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  j_err('SQL error: '.$e->getMessage(), 500);
}
