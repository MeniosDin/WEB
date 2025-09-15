<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* ---------- JSON helpers ---------- */
function j_ok($data = [], int $code = 200){
  http_response_code($code);
  echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
function j_err($msg, int $code = 400, $extra = []){
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg, 'extra'=>$extra], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Bootstrap / DB / Auth ---------- */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  j_err('Μόνο POST', 405);
}

$me  = require_role('teacher'); // ['id'=>..., 'role'=>'teacher']
$pdo = db();

/* ---------- Params (δέχεται και council_* και canceled_gs_* ονόματα) ---------- */
$thesis_id = trim((string)($_POST['thesis_id'] ?? ''));
$gs_num    = trim((string)($_POST['council_number'] ?? $_POST['canceled_gs_number'] ?? ''));
$gs_year   = trim((string)($_POST['council_year']   ?? $_POST['canceled_gs_year']   ?? ''));

if ($thesis_id === '')                j_err('Λείπει thesis_id', 422);
if ($gs_num === '' || $gs_year === '') j_err('Δώσε Αριθμό και Έτος Γ.Σ.', 422);
if (!ctype_digit($gs_num))             j_err('Ο Αριθμός Γ.Σ. πρέπει να είναι αριθμός', 422);
if (!ctype_digit($gs_year) || strlen($gs_year) !== 4) j_err('Το Έτος Γ.Σ. πρέπει να είναι έτος (π.χ. 2024)', 422);

/* ---------- Πρόσβαση & Προϋποθέσεις ---------- */
// Φέρνουμε στοιχεία διπλωματικής + έλεγχο 2ετίας.
// Χρησιμοποιούμε official_assign_date αν υπάρχει, αλλιώς assigned_at.
$sql = "
  SELECT 
    t.id,
    t.status,
    t.supervisor_id,
    /* αν δεν υπάρχει official_assign_date στη ΒΔ, COALESCE => assigned_at */
    TIMESTAMPDIFF(
      YEAR,
      COALESCE(t.official_assign_date, t.assigned_at),
      NOW()
    ) AS years_since
  FROM theses t
  WHERE t.id = :tid
  LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([':tid' => $thesis_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row)                          j_err('Δεν βρέθηκε διπλωματική', 404);
if ((string)$row['supervisor_id'] !== (string)$me['id']) j_err('Μόνο ο επιβλέπων μπορεί να ακυρώσει', 403);
if ($row['status'] !== 'active')    j_err('Επιτρέπεται μόνο όταν η κατάσταση είναι ACTIVE', 403);

$years = (int)($row['years_since'] ?? 0);
if ($years < 2) {
  j_err('Πρέπει να έχουν παρέλθει τουλάχιστον 2 έτη από την οριστική ανάθεση', 403, ['years_since'=>$years]);
}

/* ---------- Ενημέρωση κατάστασης + log ---------- */
$pdo->beginTransaction();
try {
  // Αν δεν υπάρχουν οι στήλες canceled_*, πρόσθεσέ τες (δες πιο κάτω migration).
  $up = $pdo->prepare("
    UPDATE theses
    SET status='canceled',
        canceled_reason='by professor',
        canceled_gs_number=:n,
        canceled_gs_year=:y,
        updated_at=NOW()
    WHERE id=:tid
  ");
  $up->execute([
    ':n'   => $gs_num,
    ':y'   => $gs_year,
    ':tid' => $thesis_id,
  ]);

  // Προαιρετικό: log στο thesis_events (αν υπάρχει)
  try {
    $log = $pdo->prepare("
      INSERT INTO thesis_events(thesis_id, event_type, from_status, to_status, created_by, created_at)
      VALUES(:tid, 'status_change', 'active', 'canceled', :uid, NOW())
    ");
    $log->execute([':tid'=>$thesis_id, ':uid'=>$me['id']]);
  } catch (Throwable $e) {
    // αν δεν υπάρχει ο πίνακας, απλώς προχωράμε
  }

  $pdo->commit();
  j_ok(['thesis_id'=>$thesis_id, 'status'=>'canceled', 'canceled_gs_number'=>$gs_num, 'canceled_gs_year'=>$gs_year]);
} catch (Throwable $e) {
  $pdo->rollBack();
  j_err('Αποτυχία ακύρωσης', 500, ['ex'=>$e->getMessage()]);
}
