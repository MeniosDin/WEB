<?php
declare(strict_types=1);

function j_ok($data=[], int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}
function j_err($msg, int $code=400, $extra=[]){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>$msg,'extra'=>$extra], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

function find_student_by_query(PDO $pdo, string $q) {
  // πρώτα ακριβές ταίριασμα σε ΑΜ
  $st = $pdo->prepare("SELECT id, name, student_number FROM users
                       WHERE role='student' AND student_number = :q LIMIT 1");
  $st->execute([':q'=>$q]);
  $row = $st->fetch();
  if ($row) return $row;

  // αλλιώς LIKE σε ονοματεπώνυμο (case-insensitive)
  $st = $pdo->prepare("SELECT id, name, student_number FROM users
                       WHERE role='student' AND name LIKE :q
                       ORDER BY name LIMIT 1");
  $st->execute([':q'=>'%'.$q.'%']);
  return $st->fetch() ?: null;
}

// Εξασφάλισε ότι τρέχει μόνο για role=teacher
$me = require_role('teacher');

// Πάρε PDO (διάλεξε ΕΝΑν τρόπο)
$pdo = db(); // ή: $pdo = $GLOBALS['pdo'];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

/* =========================
   LIST (GET?action=list)
   ========================= */
if ($method==='GET' && ($action==='' || $action==='list')) {
  $st = $pdo->prepare("
    SELECT
      t.id, t.title, t.summary, t.spec_pdf_path, t.is_available,
      t.created_at, t.updated_at,
      t.provisional_student_id,
      s.name  AS provisional_student_name,
      s.student_number AS provisional_student_number
    FROM topics t
    LEFT JOIN users s ON s.id = t.provisional_student_id
    WHERE t.supervisor_id = :me
    ORDER BY t.updated_at DESC
  ");
  $st->execute([':me'=>$me['id']]);
  j_ok($st->fetchAll());
}

/* =========================
   CREATE (POST?action=create)
   ========================= */
if ($method === 'POST' && $action === 'create') {
  $title   = trim($_POST['title']   ?? '');
  $summary = trim($_POST['summary'] ?? '');
  $avail   = ($_POST['is_available'] ?? '1') === '1' ? 1 : 0;

  if ($title === '' || $summary === '') {
    j_err('Τίτλος και σύνοψη απαιτούνται', 422);
  }

  // --- προαιρετικό PDF ---
  $pdfPath = null;
  if (!empty($_FILES['spec_pdf']['name'])) {
    $dirPublic = realpath(__DIR__ . '/../../public');
    if ($dirPublic === false) { $dirPublic = __DIR__ . '/../../public'; }

    $destDir = $dirPublic . '/uploads/specs';
    if (!is_dir($destDir)) {
      mkdir($destDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES['spec_pdf']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') j_err('Μόνο PDF επιτρέπεται', 422);

    $fname = uniqid('spec_', true) . '.pdf';
    $dest  = $destDir . '/' . $fname;

    if (!move_uploaded_file($_FILES['spec_pdf']['tmp_name'], $dest)) {
      j_err('Αποτυχία αποθήκευσης αρχείου', 500);
    }
    $pdfPath = '/uploads/specs/' . $fname; // public URL
  }

  $st = $pdo->prepare("
    INSERT INTO topics
      (id, supervisor_id, title, summary, spec_pdf_path, is_available, created_at, updated_at)
    VALUES
      (UUID(), :me, :title, :summary, :pdf, :av, NOW(), NOW())
  ");
  $st->execute([
    ':me'      => $me['id'],
    ':title'   => $title,
    ':summary' => $summary,
    ':pdf'     => $pdfPath,
    ':av'      => $avail,
  ]);

  j_ok(['saved' => true], 201);
}

/* =========================
   UPDATE (POST?action=update)
   ========================= */
if ($method === 'POST' && $action === 'update') {
  $id = trim((string)($_POST['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  // Βρες το θέμα & έλεγξε ιδιοκτησία
  $st = $pdo->prepare("SELECT spec_pdf_path, supervisor_id FROM topics WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  $row = $st->fetch();
  if (!$row) j_err('Δεν βρέθηκε', 404);
  if ((string)$row['supervisor_id'] !== (string)$me['id']) j_err('Forbidden', 403);

  $oldPath = $row['spec_pdf_path'];
  $newPdfPath = $oldPath;

  $fields = [];
  $params = [ ':id'=>$id, ':me'=>$me['id'] ];

  // Ενημέρωση τίτλου μόνο αν στάλθηκε
  if (array_key_exists('title', $_POST)) {
    $fields[]    = 'title=:t';
    $params[':t'] = trim((string)$_POST['title']);
  }

  // Ενημέρωση σύνοψης μόνο αν στάλθηκε
  if (array_key_exists('summary', $_POST)) {
    $fields[]     = 'summary=:s';
    $params[':s'] = trim((string)$_POST['summary']);
  }

  // Ενημέρωση διαθεσιμότητας μόνο αν στάλθηκε
  if (array_key_exists('is_available', $_POST)) {
    $fields[]     = 'is_available=:a';
    $params[':a'] = (($_POST['is_available'] ?? '1') === '1') ? 1 : 0;
  }

  // Διαγραφή υπάρχοντος PDF αν ζητήθηκε
  $removePdf = (($_POST['remove_pdf'] ?? '0') === '1');
  if ($removePdf) {
    if ($oldPath) {
      $public = realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
      @unlink($public . $oldPath);   // το $oldPath είναι /uploads/...
    }
    $newPdfPath = null;
    $fields[]   = 'spec_pdf_path=:pdf';
    $params[':pdf'] = $newPdfPath;
  }

  // Ανέβασμα νέου PDF (μόνο αν ΔΕΝ ζητήθηκε διαγραφή)
  if (!$removePdf && !empty($_FILES['spec_pdf']['name'])) {
    $public  = realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
    $destDir = $public . '/uploads/specs';
    if (!is_dir($destDir)) mkdir($destDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES['spec_pdf']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') j_err('Μόνο PDF επιτρέπεται', 422);

    $fname = uniqid('spec_', true) . '.pdf';
    $dest  = $destDir . '/' . $fname;
    if (!move_uploaded_file($_FILES['spec_pdf']['tmp_name'], $dest)) {
      j_err('Αποτυχία αποθήκευσης αρχείου', 500);
    }

    // σβήσε παλιό
    if ($oldPath) @unlink($public . $oldPath);

    $newPdfPath = '/uploads/specs/' . $fname;
    $fields[]   = 'spec_pdf_path=:pdf';
    $params[':pdf'] = $newPdfPath;
  }

  if (empty($fields)) {
    // τίποτα δεν άλλαξε – δεν κάνουμε άσκοπο UPDATE
    j_ok(['updated'=>false, 'noop'=>true]);
  } else {
    $sql = "UPDATE topics SET " . implode(', ', $fields) . ", updated_at=NOW()
            WHERE id=:id AND supervisor_id=:me";
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    j_ok(['updated'=>true]);
  }
}

/* =========================
   DELETE (POST?action=delete)
   ========================= */
if ($method==='POST' && $action==='delete') {
  $id = trim((string)($_POST['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  // Βρες & έλεγξε ιδιοκτησία
  $st = $pdo->prepare("SELECT spec_pdf_path, supervisor_id FROM topics WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  $row = $st->fetch();
  if (!$row) j_err('Δεν βρέθηκε', 404);
  if ((string)$row['supervisor_id'] !== (string)$me['id']) j_err('Forbidden', 403);

  // Διαγραφή αρχείου PDF (αν υπάρχει)
  if (!empty($row['spec_pdf_path'])) {
    $dirPublic = realpath(__DIR__ . '/../../public');
    if ($dirPublic === false) { $dirPublic = __DIR__ . '/../../public'; }
    $abs = $dirPublic . $row['spec_pdf_path'];
    if (is_file($abs)) @unlink($abs);
  }

  // Hard delete (αν θέλεις soft-delete, βάλε is_available=0)
  $st = $pdo->prepare("DELETE FROM topics WHERE id=:id");
  $st->execute([':id'=>$id]);

  j_ok(['deleted'=>true]);
}

/* =========================
   ASSIGN (POST?action=assign_student)
   ========================= */
if ($method==='POST' && $action==='assign_student') {
  $topicId = trim((string)($_POST['id'] ?? ''));
  $query   = trim((string)($_POST['student_query'] ?? ''));
  if ($topicId === '' || $query === '') j_err('Λείπει id ή αναζήτηση', 422);

  // Έλεγξε ιδιοκτησία θέματος
  $st = $pdo->prepare("SELECT id FROM topics WHERE id=:id AND supervisor_id=:me LIMIT 1");
  $st->execute([':id'=>$topicId, ':me'=>$me['id']]);
  if (!$st->fetch()) j_err('Δεν βρέθηκε ή δεν σας ανήκει', 404);

  // Βρες φοιτητή από ΑΜ ή ονοματεπώνυμο
  $stud = find_student_by_query($pdo, $query);
  if (!$stud) j_err('Δεν βρέθηκε φοιτητής', 404);

  // Καταχώριση προσωρινής ανάθεσης
  $st = $pdo->prepare("UPDATE topics
                       SET provisional_student_id=:sid, provisional_since=NOW(), updated_at=NOW()
                       WHERE id=:id");
  $st->execute([':sid'=>$stud['id'], ':id'=>$topicId]);

  j_ok(['assigned'=>true, 'student'=>$stud]);
}

/* =========================
   UNASSIGN (POST?action=unassign_student)
   ========================= */
if ($method==='POST' && $action==='unassign_student') {
  $topicId = trim((string)($_POST['id'] ?? ''));
  if ($topicId === '') j_err('Λείπει id', 422);

  // Έλεγξε ιδιοκτησία θέματος
  $st = $pdo->prepare("SELECT id FROM topics WHERE id=:id AND supervisor_id=:me LIMIT 1");
  $st->execute([':id'=>$topicId, ':me'=>$me['id']]);
  if (!$st->fetch()) j_err('Δεν βρέθηκε ή δεν σας ανήκει', 404);

  // Αφαίρεση προσωρινής ανάθεσης
  $st = $pdo->prepare("UPDATE topics
                       SET provisional_student_id=NULL, provisional_since=NULL, updated_at=NOW()
                       WHERE id=:id");
  $st->execute([':id'=>$topicId]);

  j_ok(['unassigned'=>true]);
}

/* =========================
   Unknown
   ========================= */
j_err('Άγνωστη ενέργεια', 400, ['method'=>$method,'action'=>$action]);
