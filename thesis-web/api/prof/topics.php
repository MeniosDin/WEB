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

$me  = require_role('teacher');          // ['id'=>..., 'role'=>'teacher']
$pdo = db();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

/* ---------- helpers ---------- */
function find_student_by_query(PDO $pdo, string $q) {
  // 1) ακριβές ΑΜ
  $st = $pdo->prepare("
    SELECT id, name, student_number
    FROM users
    WHERE role='student' AND student_number = :q
    LIMIT 1
  ");
  $st->execute([':q'=>$q]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;

  // 2) ή μέρος ονόματος
  $st = $pdo->prepare("
    SELECT id, name, student_number
    FROM users
    WHERE role='student' AND name LIKE :q
    ORDER BY name
    LIMIT 1
  ");
  $st->execute([':q'=>'%'.$q.'%']);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function topic_belongs_to(PDO $pdo, string $topicId, string $teacherId): bool {
  $st = $pdo->prepare("SELECT 1 FROM topics WHERE id=:id AND supervisor_id=:me LIMIT 1");
  $st->execute([':id'=>$topicId, ':me'=>$teacherId]);
  return (bool)$st->fetchColumn();
}

/* =========================================================
   LIST (GET?action=list)
   ========================================================= */
if ($method==='GET' && ($action==='' || $action==='list')) {
  $st = $pdo->prepare("
    SELECT
      t.id, t.title, t.summary, t.spec_pdf_path, t.is_available,
      t.created_at, t.updated_at,
      t.provisional_student_id,
      s.name AS provisional_student_name,
      s.student_number AS provisional_student_number
    FROM topics t
    LEFT JOIN users s ON s.id = t.provisional_student_id
    WHERE t.supervisor_id = :me
    ORDER BY t.updated_at DESC, t.created_at DESC
  ");
  $st->execute([':me'=>$me['id']]);
  j_ok($st->fetchAll(PDO::FETCH_ASSOC));
}

/* =========================================================
   CREATE (POST?action=create)
   ========================================================= */
if ($method === 'POST' && $action === 'create') {
  $title   = trim((string)($_POST['title']   ?? ''));
  $summary = trim((string)($_POST['summary'] ?? ''));
  $avail   = (($_POST['is_available'] ?? '1') === '1') ? 1 : 0;

  if ($title === '' || $summary === '') j_err('Τίτλος και σύνοψη απαιτούνται', 422);

  // προαιρετικό PDF
  $pdfPath = null;
  if (!empty($_FILES['spec_pdf']['name'])) {
    $public  = realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
    $destDir = $public . '/uploads/specs';
    if (!is_dir($destDir)) mkdir($destDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES['spec_pdf']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') j_err('Μόνο PDF επιτρέπεται', 422);

    $fname = uniqid('spec_', true) . '.pdf';
    $dest  = $destDir . '/' . $fname;
    if (!move_uploaded_file($_FILES['spec_pdf']['tmp_name'], $dest)) j_err('Αποτυχία αποθήκευσης αρχείου', 500);
    $pdfPath = '/uploads/specs/' . $fname;   // public URL
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

  j_ok(['saved'=>true], 201);
}

/* =========================================================
   UPDATE (POST?action=update)
   ========================================================= */
if ($method === 'POST' && $action === 'update') {
  $id = trim((string)($_POST['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  // ιδιοκτησία + τρέχον pdf
  $st = $pdo->prepare("SELECT spec_pdf_path, supervisor_id FROM topics WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) j_err('Δεν βρέθηκε', 404);
  if ((string)$row['supervisor_id'] !== (string)$me['id']) j_err('Forbidden', 403);

  $oldPdf = $row['spec_pdf_path'];

  $fields = [];
  $params = [':id'=>$id, ':me'=>$me['id']];

  if (array_key_exists('title', $_POST))   { $fields[]='title=:t';     $params[':t']=trim((string)$_POST['title']); }
  if (array_key_exists('summary', $_POST)) { $fields[]='summary=:s';   $params[':s']=trim((string)$_POST['summary']); }
  if (array_key_exists('is_available', $_POST)) {
    $fields[]='is_available=:a'; $params[':a']=(($_POST['is_available'] ?? '1')==='1')?1:0;
  }

  // delete pdf
  if (($_POST['remove_pdf'] ?? '0') === '1') {
    if ($oldPdf) {
      $public = realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
      @unlink($public . $oldPdf);
    }
    $fields[]='spec_pdf_path=:pdf'; $params[':pdf']=null;
  }

  // upload νέο pdf (αν ΔΕΝ ζητήθηκε διαγραφή)
  if (($_POST['remove_pdf'] ?? '0') !== '1' && !empty($_FILES['spec_pdf']['name'])) {
    $public  = realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
    $destDir = $public . '/uploads/specs';
    if (!is_dir($destDir)) mkdir($destDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES['spec_pdf']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') j_err('Μόνο PDF επιτρέπεται', 422);

    $fname = uniqid('spec_', true) . '.pdf';
    $dest  = $destDir . '/' . $fname;
    if (!move_uploaded_file($_FILES['spec_pdf']['tmp_name'], $dest)) j_err('Αποτυχία αποθήκευσης αρχείου', 500);

    if ($oldPdf) @unlink($public . $oldPdf);

    $fields[]='spec_pdf_path=:pdf'; $params[':pdf']='/uploads/specs/'.$fname;
  }

  if (!$fields) j_ok(['updated'=>false,'noop'=>true]);

  $sql = "UPDATE topics SET ".implode(', ',$fields).", updated_at=NOW()
          WHERE id=:id AND supervisor_id=:me";
  $up  = $pdo->prepare($sql);
  $up->execute($params);
  j_ok(['updated'=>true]);
}

/* =========================================================
   DELETE (POST?action=delete)
   ========================================================= */
if ($method==='POST' && $action==='delete') {
  $id = trim((string)($_POST['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  $st = $pdo->prepare("SELECT spec_pdf_path, supervisor_id FROM topics WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) j_err('Δεν βρέθηκε', 404);
  if ((string)$row['supervisor_id'] !== (string)$me['id']) j_err('Forbidden', 403);

  if (!empty($row['spec_pdf_path'])) {
    $public = realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
    $abs = $public . $row['spec_pdf_path'];
    if (is_file($abs)) @unlink($abs);
  }

  $del = $pdo->prepare("DELETE FROM topics WHERE id=:id");
  $del->execute([':id'=>$id]);

  j_ok(['deleted'=>true]);
}

/* =========================================================
   ASSIGN (POST?action=assign_student)
   ========================================================= */
if ($method==='POST' && $action==='assign_student') {
  $topicId = trim((string)($_POST['id'] ?? ''));
  $query   = trim((string)($_POST['student_query'] ?? ''));
  if ($topicId === '' || $query === '') j_err('Λείπει id ή αναζήτηση', 422);

  // Επαλήθευση ιδιοκτησίας
  if (!topic_belongs_to($pdo, $topicId, $me['id'])) j_err('Δεν βρέθηκε ή δεν σας ανήκει', 404);

  // Βρες φοιτητή (ΑΜ ή ονοματεπώνυμο)
  $stud = find_student_by_query($pdo, $query);
  if (!$stud) j_err('Δεν βρέθηκε φοιτητής', 404);

  $up = $pdo->prepare("
    UPDATE topics
    SET provisional_student_id = :sid,
        provisional_since      = NOW(),
        updated_at             = NOW()
    WHERE id = :id
      AND supervisor_id = :me
    LIMIT 1
  ");
  $up->execute([':sid'=>$stud['id'], ':id'=>$topicId, ':me'=>$me['id']]);

  if ($up->rowCount() !== 1) j_err('Η ανάθεση δεν ολοκληρώθηκε', 409);
  j_ok(['assigned'=>true, 'student'=>$stud]);
}

/* =========================================================
   UNASSIGN (POST?action=unassign_student)
   ========================================================= */
if ($method==='POST' && $action==='unassign_student') {
  $topicId = trim((string)($_POST['id'] ?? ''));
  if ($topicId === '') j_err('Λείπει id', 422);

  if (!topic_belongs_to($pdo, $topicId, $me['id'])) j_err('Δεν βρέθηκε ή δεν σας ανήκει', 404);

  $up = $pdo->prepare("
    UPDATE topics
    SET provisional_student_id = NULL,
        provisional_since      = NULL,
        updated_at             = NOW()
    WHERE id = :id
      AND supervisor_id = :me
    LIMIT 1
  ");
  $up->execute([':id'=>$topicId, ':me'=>$me['id']]);

  if ($up->rowCount() !== 1) j_err('Δεν έγινε αφαίρεση', 409);
  j_ok(['unassigned'=>true]);
}

/* =========================================================
   default
   ========================================================= */
j_err('Άγνωστη ενέργεια', 400, ['method'=>$method,'action'=>$action]);
