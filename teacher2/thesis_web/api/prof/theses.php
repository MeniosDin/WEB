<?php
declare(strict_types=1);

// ---------------- JSON helpers ----------------
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

// ---------------- Bootstrap / DB / Auth ----------------
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

// επιτρέπει μόνο role=teacher
$me  = require_role('teacher');             // ['id'=>..., 'role'=>'teacher']
$pdo = db();                                // PDO instance

// users.id -> persons.id (για τριμελείς)
$ME_USER = $me['id'];
$st = $pdo->prepare("SELECT id FROM persons WHERE user_id = :u LIMIT 1");
$st->execute([':u' => $ME_USER]);
$ME_PERSON = $st->fetchColumn() ?: $ME_USER;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

function teacher_has_access(PDO $pdo, string $meUserId, string $mePersonId, string $thesisId): bool {
  $sql = "SELECT 1
          FROM theses t
          WHERE t.id = :id
            AND (t.supervisor_id = :me_user
                 OR EXISTS (
                   SELECT 1 FROM committee_members cm
                   WHERE cm.thesis_id = t.id AND cm.person_id = :me_person
                 ))";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':id'        => $thesisId,
    ':me_user'   => $meUserId,
    ':me_person' => $mePersonId
  ]);
  return (bool)$st->fetchColumn();
}

/* =======================================================
   DETAILS  (GET?action=details&id=<uuid>)
   ======================================================= */
if ($method === 'GET' && $action === 'details') {
  $id = trim((string)($_GET['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  if (!teacher_has_access($pdo, $ME_USER, $ME_PERSON, $id)) {
    j_err('Δεν βρέθηκε ή δεν έχετε πρόσβαση', 403);
  }

  // thesis + student + supervisor
  $sql = "
    SELECT 
      t.*,
      stu.name  AS student_name,
      stu.student_number,
      sup.name  AS supervisor_name,
      sup.email AS supervisor_email
    FROM theses t
    JOIN users stu ON stu.id = t.student_id AND stu.role = 'student'
    JOIN users sup ON sup.id = t.supervisor_id AND sup.role = 'teacher'
    WHERE t.id = :id
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':id'=>$id]);
  $thesis = $st->fetch(PDO::FETCH_ASSOC);
  if (!$thesis) j_err('Thesis not found', 404);

  // committee
  // committee (ΜΟΝΟ ό,τι υπάρχει σίγουρα στον committee_members)
    $sqlC = "
        SELECT 
        u.id, u.name, u.email, 
        cm.role_in_committee,
        cm.added_at
        FROM committee_members cm
        JOIN users u ON u.id = cm.person_id
        WHERE cm.thesis_id = :id
        ORDER BY (cm.role_in_committee='supervisor') DESC, u.name
    ";
$cst = $pdo->prepare($sqlC);
$cst->execute([':id'=>$id]);
$committee = $cst->fetchAll(PDO::FETCH_ASSOC);


  j_ok(['thesis'=>$thesis, 'committee'=>$committee]);
}

// =========================
// LIST  (GET?action=list&status=&role=)
// =========================
if ($method === 'GET' && ($action === '' || $action === 'list')) {
  // προαιρετικά φίλτρα από το UI
  $status = isset($_GET['status']) && $_GET['status'] !== '' ? trim((string)$_GET['status']) : null;
  $role   = isset($_GET['role'])   && $_GET['role']   !== '' ? trim((string)$_GET['role'])   : null;

  // βασικό query: είμαι επιβλέπων (users.id) ή μέλος τριμελούς (persons.id)
  $sql = "
    SELECT
      t.id,
      t.status,
      t.created_at,
      t.updated_at,
      t.official_assign_date,
      t.assigned_at,
      tp.title            AS topic_title,
      stu.name            AS student_name,
      stu.student_number,
      sup.name            AS supervisor_name,
      CASE
        WHEN t.supervisor_id = :me_user THEN 'supervisor'
        WHEN EXISTS (
          SELECT 1 FROM committee_members cm2
          WHERE cm2.thesis_id = t.id AND cm2.person_id = :me_person
        ) THEN 'member'
        ELSE NULL
      END AS my_role
    FROM theses t
    JOIN users  stu ON stu.id = t.student_id    AND stu.role = 'student'
    JOIN users  sup ON sup.id = t.supervisor_id AND sup.role = 'teacher'
    JOIN topics tp  ON tp.id = t.topic_id
    WHERE ( t.supervisor_id = :me_user
            OR EXISTS (
              SELECT 1 FROM committee_members cm
              WHERE cm.thesis_id = t.id AND cm.person_id = :me_person
            )
          )
  ";

  // βασικές παράμετροι
  $params = [
    ':me_user'   => $ME_USER,    // users.id
    ':me_person' => $ME_PERSON,  // persons.id
  ];

  // φίλτρο κατάστασης (π.χ. under_assignment|active|under_review|completed|canceled)
  if ($status !== null) {
    $sql .= " AND t.status = :status";
    $params[':status'] = $status;
  }

  // φίλτρο ρόλου (supervisor / member)
  if ($role === 'supervisor') {
    $sql .= " AND t.supervisor_id = :me_user";
  } elseif ($role === 'member') {
    $sql .= " AND t.supervisor_id <> :me_user
              AND EXISTS (
                SELECT 1 FROM committee_members cm3
                WHERE cm3.thesis_id = t.id AND cm3.person_id = :me_person
              )";
  }

  $sql .= " ORDER BY t.updated_at DESC, t.created_at DESC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  j_ok($st->fetchAll(PDO::FETCH_ASSOC));
}

/* =======================================================
   ADD NOTE (POST?action=add_note)
   Body: thesis_id, body (<=300)
   Σημειώσεις ορατές μόνο στον δημιουργό (author_prof_id = me)
   ======================================================= */
if ($method === 'POST' && $action === 'add_note') {
  $thesis_id = trim((string)($_POST['thesis_id'] ?? ''));
  $body      = trim((string)($_POST['body'] ?? ''));
  if ($thesis_id === '' || $body === '') j_err('Λείπουν στοιχεία', 422);
  if (mb_strlen($body) > 300) j_err('Σημείωση έως 300 χαρακτήρες', 422);

  if (!teacher_has_access($pdo, $ME_USER, $ME_PERSON, $id)) {
    j_err('Δεν συμμετέχετε σε αυτή τη ΔΕ', 403);
  }

  // μόνο για ACTIVE
  $chk = $pdo->prepare("SELECT status FROM theses WHERE id = :id");
  $chk->execute([':id'=>$thesis_id]);
  $st = $chk->fetchColumn();
  if ($st !== 'active') j_err('Επιτρέπεται μόνο σε ACTIVE', 403);

  $ins = $pdo->prepare("INSERT INTO notes (thesis_id, author_prof_id, body, created_at)
                        VALUES (:t, :p, :b, NOW())");
  $ins->execute([':t'=>$thesis_id, ':p'=>$me['id'], ':b'=>$body]);
  j_ok(['id'=>$pdo->lastInsertId()]);
}

/* =======================================================
   MARK UNDER REVIEW (POST?action=mark_under_exam)
   Από ACTIVE -> UNDER_REVIEW (ως επιβλέπων)
   ======================================================= */
if ($method === 'POST' && $action === 'mark_under_exam') {
  $thesis_id = trim((string)($_POST['thesis_id'] ?? ''));
  if ($thesis_id === '') j_err('Λείπει thesis_id', 422);

  $st = $pdo->prepare("UPDATE theses
                       SET status='under_review', updated_at=NOW()
                       WHERE id=:id AND supervisor_id=:me AND status='active'");
  $st->execute([':id'=>$thesis_id, ':me'=>$me['id']]);
  if ($st->rowCount() === 0) j_err('Δεν επιτρέπεται ή δεν είναι ACTIVE', 403);
  j_ok(['thesis_id'=>$thesis_id,'status'=>'under_review']);
}

/* =======================================================
   CANCEL AFTER 2Y (POST?action=cancel_after_2y)
   Θέλει: thesis_id, canceled_gs_number, canceled_gs_year, (προαιρετικά) canceled_reason
   Από ACTIVE -> CANCELED, αν έχουν περάσει ≥2 έτη από official_assign_date
   ======================================================= */
if ($method === 'POST' && $action === 'cancel_after_2y') {
  $thesis_id = trim((string)($_POST['thesis_id'] ?? ''));
  $gs_num    = trim((string)($_POST['canceled_gs_number'] ?? ''));
  $gs_year   = trim((string)($_POST['canceled_gs_year'] ?? ''));
  $reason    = trim((string)($_POST['canceled_reason'] ?? 'by professor'));
  if ($thesis_id==='' || $gs_num==='' || $gs_year==='') j_err('Λείπουν στοιχεία', 422);

  $sql = "UPDATE theses
          SET status='canceled',
              canceled_gs_number=:n,
              canceled_gs_year=:y,
              canceled_reason=:r,
              updated_at=NOW()
          WHERE id=:id AND supervisor_id=:me AND status='active'
            AND TIMESTAMPDIFF(YEAR, official_assign_date, NOW()) >= 2";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':n'=>$gs_num, ':y'=>$gs_year, ':r'=>$reason,
    ':id'=>$thesis_id, ':me'=>$me['id']
  ]);
  if ($st->rowCount() === 0) j_err('Δεν πληροί προϋποθέσεις (2 έτη, ACTIVE, supervisor)', 403);
  j_ok(['thesis_id'=>$thesis_id, 'status'=>'canceled']);
}

// =======================================================
// INVITATIONS INBOX (GET?action=invitations)
// Ενεργές προσκλήσεις: status='pending' (ή NULL) και χωρίς responded_at
// =======================================================
if ($method === 'GET' && $action === 'invitations') {
  $sql = "
    SELECT
      inv.id        AS invitation_id,
      inv.thesis_id,
      inv.invited_at,
      inv.status     AS invitation_status,
      t.status       AS thesis_status,
      tp.title       AS topic_title,
      stu.name       AS student_name,
      stu.student_number,
      sup.name       AS supervisor_name
    FROM committee_invitations inv
    JOIN theses t   ON t.id = inv.thesis_id
    JOIN topics tp  ON tp.id = t.topic_id
    JOIN users  stu ON stu.id = t.student_id    AND stu.role='student'
    JOIN users  sup ON sup.id = t.supervisor_id AND sup.role='teacher'
    WHERE inv.person_id = :me           -- persons.id του Α
      AND inv.accepted_at IS NULL
      AND inv.rejected_at IS NULL
    ORDER BY inv.invited_at DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':me' => $ME_PERSON]);
  j_ok($st->fetchAll(PDO::FETCH_ASSOC));
}

// =======================================================
// ACCEPT / REJECT INVITATION (POST)
// Body: invitation_id
// =======================================================
if ($method === 'POST' && ($action === 'accept_invitation' || $action === 'reject_invitation')) {
  $inv_id = trim((string)($_POST['invitation_id'] ?? ''));
  if ($inv_id === '') j_err('Λείπει invitation_id', 422);

  $isAccept = $action === 'accept_invitation';
  $col      = $isAccept ? 'accepted_at' : 'rejected_at';
  $newSt    = $isAccept ? 'accepted'    : 'rejected';

  $pdo->beginTransaction();

  // 1) Update της πρόσκλησης (μόνο αν δεν έχει ήδη απαντηθεί)
  $up = $pdo->prepare("
    UPDATE committee_invitations
    SET status=:st, $col=NOW(), responded_at=NOW()
    WHERE id=:id
      AND person_id=:me                 -- persons.id
      AND accepted_at IS NULL
      AND rejected_at IS NULL
  ");
  $up->execute([':st'=>$newSt, ':id'=>$inv_id, ':me'=>$ME_PERSON]);
  if ($up->rowCount() === 0) { $pdo->rollBack(); j_err('Δεν βρέθηκε ενεργή πρόσκληση', 404); }

  // 2) Αν accepted -> πρόσθεσε στο committee_members (αν δεν υπάρχει ήδη)
  if ($isAccept) {
    $tid = $pdo->prepare("SELECT thesis_id FROM committee_invitations WHERE id=:id");
    $tid->execute([':id'=>$inv_id]);
    $thesis_id = $tid->fetchColumn();

    if ($thesis_id) {
      $ins = $pdo->prepare("
        INSERT INTO committee_members (id, thesis_id, person_id, role_in_committee, added_at)
        SELECT UUID(), :t, :p, 'member', NOW()
        FROM DUAL
        WHERE NOT EXISTS (
          SELECT 1 FROM committee_members
          WHERE thesis_id=:t AND person_id=:p
        )
      ");
      $ins->execute([':t'=>$thesis_id, ':p'=>$ME_PERSON]);
    }
  }

  $pdo->commit();
  j_ok(['invitation_id'=>$inv_id, 'status'=>$newSt]);
}

/* =======================================================
   SHOW (GET?action=show&id=<uuid>)
   Αναλυτική προβολή + τελευταία notes του χρήστη
   ======================================================= */
if ($method === 'GET' && $action === 'show') {
  $id = trim((string)($_GET['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  if (!teacher_has_access($pdo, $ME_USER, $ME_PERSON, $id)) {
    j_err('Δεν έχετε πρόσβαση ή δεν βρέθηκε', 403);
  }

  $sql = "
    SELECT
      t.id, t.status, t.created_at, t.updated_at,
      t.official_assign_date, t.assigned_at,
      u.name AS student_name, u.student_number,
      tp.title AS topic_title
    FROM theses t
    JOIN users  u  ON u.id = t.student_id AND u.role='student'
    JOIN topics tp ON tp.id = t.topic_id
    WHERE t.id = :id
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':id'=>$id]);
  $thesis = $st->fetch(PDO::FETCH_ASSOC);

  $cm = $pdo->prepare("
    SELECT cm.person_id, cm.role_in_committee, cm.added_at, u.name
    FROM committee_members cm
    JOIN users u ON u.id = cm.person_id
    WHERE cm.thesis_id = :id
    ORDER BY (cm.role_in_committee='supervisor') DESC, u.name
  ");
  $cm->execute([':id'=>$id]);
  $committee = $cm->fetchAll(PDO::FETCH_ASSOC);

  // τελευταίες σημειώσεις ΜΟΝΟ του τρέχοντος καθηγητή
  $notes = [];
  if ($pdo->query("SHOW TABLES LIKE 'notes'")->fetch()) {
    $nn = $pdo->prepare("
      SELECT n.id, n.body, n.created_at
      FROM notes n
      WHERE n.thesis_id = :id AND n.author_prof_id = :me
      ORDER BY n.created_at DESC
      LIMIT 10
    ");
    $nn->execute([':id'=>$id, ':me'=>$me['id']]);
    $notes = $nn->fetchAll(PDO::FETCH_ASSOC);
  }

  j_ok(['thesis'=>$thesis, 'committee'=>$committee, 'notes'=>$notes]);
}

// default
j_err('Άγνωστη ενέργεια', 400, ['method'=>$method,'action'=>$action]);
