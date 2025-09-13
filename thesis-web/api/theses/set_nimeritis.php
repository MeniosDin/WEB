<?php
// api/theses/set_nimeritis.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

session_start();

// === 1) Auth / Guard ===
// Προσαρμόσέ το στο δικό σου auth (π.χ. $_SESSION['user'])
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'student') {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

$studentUserId = (int) $_SESSION['user']['id'];

// === 2) Input ===
// Δέξου JSON ή form-data για ευελιξία
$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '[]', true);
if (!is_array($in) || empty($in)) {
  $in = $_POST; // fallback αν ήρθε multipart/form-data
}

$thesis_id = isset($in['thesis_id']) ? trim((string)$in['thesis_id']) : '';
$nimeritis_url = isset($in['nimeritis_url']) ? trim((string)$in['nimeritis_url']) : '';
$nimeritis_deposit_date = isset($in['nimeritis_deposit_date']) ? trim((string)$in['nimeritis_deposit_date']) : '';

if ($thesis_id === '' || $nimeritis_url === '' || $nimeritis_deposit_date === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing fields']);
  exit;
}

// Βασικός έλεγχος URL
if (!filter_var($nimeritis_url, FILTER_VALIDATE_URL)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
  exit;
}

// Βασικός έλεγχος ημερομηνίας (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nimeritis_deposit_date)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid date']);
  exit;
}

// === 3) DB ===
require_once __DIR__ . '/../_db.php'; // προσαρμογή στο include σου
// _db.php πρέπει να φτιάχνει $pdo (PDO) σε UTF8 & exceptions

try {
  // (α) Βεβαιώσου ότι η διπλωματική ανήκει στον φοιτητή (ή είναι assigned σε αυτόν)
  $sql = "SELECT t.id
          FROM theses t
          WHERE t.id = :id
            AND t.student_user_id = :uid
          LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':id' => $thesis_id, ':uid' => $studentUserId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
  }

  // (β) Ενημέρωσε πεδία Νημερτή
  $upd = $pdo->prepare(
    "UPDATE theses
     SET nimeritis_url = :url,
         nimeritis_deposit_date = :dd,
         updated_at = NOW()
     WHERE id = :id"
  );
  $upd->execute([
    ':url' => $nimeritis_url,
    ':dd'  => $nimeritis_deposit_date,
    ':id'  => $thesis_id
  ]);

  // (γ) Προαιρετικά: γράψε event στο timeline
  // Αν έχεις πίνακα thesis_timeline(event_type, thesis_id, created_at, meta_json)
  // ξεσχολίασε:
  /*
  $meta = json_encode(['nimeritis_url' => $nimeritis_url, 'date' => $nimeritis_deposit_date], JSON_UNESCAPED_UNICODE);
  $ins = $pdo->prepare(
    "INSERT INTO thesis_timeline (thesis_id, event_type, created_at, meta_json)
     VALUES (:id, 'nimeritis_set', NOW(), :meta)"
  );
  $ins->execute([':id' => $thesis_id, ':meta' => $meta]);
  */

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error']);
}
