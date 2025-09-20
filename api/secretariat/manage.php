<?php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';

require_role('secretariat');
$pdo = db();

function j_ok($p=[],$code=200){ http_response_code($code); echo json_encode(['ok'=>true]+$p, JSON_UNESCAPED_UNICODE); exit; }
function j_err($msg,$code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {

  /* -------- LIST: μικρή λίστα για το dropdown (active/under_review) -------- */
  if ($method === 'GET' && $action === 'list') {
    $sql = "
      SELECT t.id,
             t.status,
             tp.title AS topic_title
      FROM theses t
      JOIN topics tp ON tp.id = t.topic_id
      WHERE t.status IN ('active','under_review')
      ORDER BY t.created_at DESC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    j_ok(['data'=>$rows]);
  }

  /* -------- INFO: πληροφορίες για το panel -------- */
  if ($method === 'GET' && $action === 'info') {
    $id = $_GET['id'] ?? '';
    if ($id==='') j_err('missing_id',422);

    $sql = "
      SELECT t.id, t.status,
             t.approval_gs_number, t.approval_gs_year,
             t.canceled_reason, t.canceled_gs_number, t.canceled_gs_year,
             t.nimeritis_url, t.nimeritis_deposit_date,
             tp.title AS topic_title,
             stu.name AS student_name, stu.student_number
      FROM theses t
      JOIN topics tp  ON tp.id = t.topic_id
      JOIN users  stu ON stu.id = t.student_id
      WHERE t.id = ?
      LIMIT 1
    ";
    $st = $pdo->prepare($sql); $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) j_err('not_found',404);
    j_ok(['data'=>$row]);
  }

  /* -------- POST actions -------- */
  if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw,true) : $_POST;
    if (!is_array($body)) $body = [];

    $a = $body['action'] ?? $action;

    /* -- 1) Καταχώρηση ΑΠ ΓΣ (active) -- */
    if ($a === 'set_gs') {
      $id     = trim((string)($body['id'] ?? ''));
      $number = trim((string)($body['approval_gs_number'] ?? ''));
      $year   = (int)($body['approval_gs_year'] ?? 0);
      if ($id==='' || $number==='' || $year<2000) j_err('invalid_input',422);

      $sql = "UPDATE theses
              SET approval_gs_number = :n, approval_gs_year = :y
              WHERE id = :id AND status = 'active'";
      $st = $pdo->prepare($sql);
      $st->execute([':n'=>$number, ':y'=>$year, ':id'=>$id]);

      if ($st->rowCount()===0) j_err('no_update (wrong id or status not active)',409);
      j_ok(['message'=>'saved']);
    }

    /* -- 2) Ακύρωση ανάθεσης (active -> canceled) -- */
    if ($a === 'cancel') {
      $id     = trim((string)($body['id'] ?? ''));
      $cnum   = trim((string)($body['council_number'] ?? ''));
      $cyear  = (int)($body['council_year'] ?? 0);
      $reason = trim((string)($body['reason'] ?? 'κατόπιν αίτησης Φοιτητή/τριας'));
      if ($id==='' || $cnum==='' || $cyear<2000) j_err('invalid_input',422);

      $sql = "UPDATE theses
              SET status='canceled',
                  canceled_reason = :r,
                  canceled_gs_number = :n,
                  canceled_gs_year   = :y
              WHERE id = :id AND status = 'active'";
      $st = $pdo->prepare($sql);
      $st->execute([':r'=>$reason, ':n'=>$cnum, ':y'=>$cyear, ':id'=>$id]);

      if ($st->rowCount()===0) j_err('no_update (wrong id or status not active)',409);
      j_ok(['message'=>'canceled']);
    }

    /* -- 3) Ολοκλήρωση (under_review -> completed) -- */
  if ($a === 'complete') {
  $id = trim((string)($body['id'] ?? ''));
  if ($id==='') j_err('missing_id',422);

  $pdo->beginTransaction();
  try {
    /* 0) Κανονικοποίηση ρόλων */
    $st = $pdo->prepare("
      UPDATE committee_members
      SET role_in_committee = LOWER(TRIM(role_in_committee))
      WHERE thesis_id = ?
    ");
    $st->execute([$id]);

    /* 1) Ensure ότι υπάρχει supervisor στην επιτροπή (από τον theses.supervisor_id) */
    $st = $pdo->prepare("
      SELECT t.supervisor_id, p.id AS person_id
      FROM theses t
      LEFT JOIN persons p ON p.user_id = t.supervisor_id
      WHERE t.id = ?
      LIMIT 1
    ");
    $st->execute([$id]);
    $sup = $st->fetch(PDO::FETCH_ASSOC);

    if (!$sup || !$sup['supervisor_id']) {
      $pdo->rollBack();
      j_err('Λείπει supervisor από τη διπλωματική.', 422);
    }

    // Υπάρχει ήδη γραμμή supervisor;
    $st = $pdo->prepare("
      SELECT COUNT(*) FROM committee_members
      WHERE thesis_id=? AND role_in_committee='supervisor'
    ");
    $st->execute([$id]);
    $supCnt = (int)$st->fetchColumn();

    if ($supCnt === 0) {
      // Αν λείπει, τον προσθέτουμε από τον πίνακα persons (όπου person.user_id = theses.supervisor_id)
      if (empty($sup['person_id'])) {
        $pdo->rollBack();
        j_err('Δεν βρέθηκε αντίστοιχο πρόσωπο (persons) για τον επιβλέποντα.', 422);
      }
      $st = $pdo->prepare("
        INSERT INTO committee_members (id, thesis_id, person_id, role_in_committee, added_at)
        VALUES (UUID(), ?, ?, 'supervisor', NOW())
      ");
      $st->execute([$id, $sup['person_id']]);
      $supCnt = 1;
    } elseif ($supCnt > 1) {
      $pdo->rollBack();
      j_err('Υπάρχουν πολλαπλές εγγραφές supervisor στην επιτροπή.', 422);
    }

    /* 2) Μετρήσεις μελών/βαθμών/Νημερτή */
    $st = $pdo->prepare("
      SELECT
        SUM(role_in_committee='supervisor') AS sup,
        SUM(role_in_committee='member')     AS mem
      FROM committee_members
      WHERE thesis_id=?
    ");
    $st->execute([$id]);
    $cm = $st->fetch(PDO::FETCH_ASSOC) ?: ['sup'=>0,'mem'=>0];

    if ((int)$cm['sup'] !== 1) {
      $pdo->rollBack();
      j_err('Completion requires exactly 1 supervisor.', 422);
    }
    if ((int)$cm['mem'] < 2) {
      $pdo->rollBack();
      j_err('Completion requires at least 2 committee members.', 422);
    }

    // Βαθμολογήσεις (προσαρμόζεις αν έχεις άλλο table/όνομα στήλης)
    $st = $pdo->prepare("SELECT COUNT(*) FROM grades WHERE thesis_id=?");
    $st->execute([$id]);
    $gradesCnt = (int)$st->fetchColumn();
    if ($gradesCnt < 3) {
      $pdo->rollBack();
      j_err('Απαιτούνται 3 βαθμολογήσεις για ολοκλήρωση.', 422);
    }

    // Νημερτής
    $st = $pdo->prepare("
      SELECT nimeritis_url, nimeritis_deposit_date
      FROM theses
      WHERE id = ?
      FOR UPDATE
    ");
    $st->execute([$id]);
    $th = $st->fetch(PDO::FETCH_ASSOC);
    if (empty($th['nimeritis_url']) || empty($th['nimeritis_deposit_date'])) {
      $pdo->rollBack();
      j_err('Σύνδεσμος Νημερτή ή ημερομηνία κατάθεσης λείπουν.', 422);
    }

    /* 3) Επιτέλους: ολοκλήρωση */
    $st = $pdo->prepare("
      UPDATE theses
      SET status='completed', updated_at=NOW()
      WHERE id=? AND status='under_review'
    ");
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
      $pdo->rollBack();
      j_err('no_update (wrong id or status not under_review)', 409);
    }

    $pdo->commit();
    j_ok(['message'=>'completed']);
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Αν για κάποιο λόγο παρ’ όλα τα checks γκρινιάξει ο trigger:
    j_err($ex->getMessage(), 422);
  }
}


    j_err('unknown_action',400);
  }

  j_err('unsupported',400);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'internal_error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
