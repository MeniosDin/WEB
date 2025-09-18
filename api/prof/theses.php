<?php
declare(strict_types=1);

/* ------------- JSON helpers ------------- */
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

/* ------------- Bootstrap / DB / Auth ------------- */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_role('teacher');      // ['id'=>..., 'role'=>'teacher']
$pdo = db();                         // PDO

// users.id -> persons.id (fallback σε user_id αν δεν υπάρχει)
$ME_USER = (string)$me['id'];
try {
  $st = $pdo->prepare("SELECT id FROM persons WHERE user_id = :u LIMIT 1");
  $st->execute([':u' => $ME_USER]);
  $ME_PERSON = (string)($st->fetchColumn() ?: $ME_USER);
} catch (Throwable $e) {
  $ME_PERSON = $ME_USER;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

/* ------------- Access helper ------------- */
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

/* ------------- small schema helpers ------------- */
$hasTable = function(string $t) use ($pdo): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $st->execute([':t'=>$t]); return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
};
$hasCol = function(string $t, string $c) use ($pdo): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t'=>$t, ':c'=>$c]); return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
};

/* =======================================================
   DETAILS  (GET?action=details&id=<uuid>)
   ======================================================= */
if ($method === 'GET' && $action === 'details') {
  $id = trim((string)($_GET['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  if (!teacher_has_access($pdo, $ME_USER, $ME_PERSON, $id)) {
    j_err('Δεν βρέθηκε ή δεν έχετε πρόσβαση', 403);
  }

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

  // Επιτροπή (ό,τι υπάρχει στον committee_members)
 // --- Επιτροπή (safe για κάθε schema) ---
$sqlC = "
  SELECT
    COALESCE(u.id, cm.person_id)           AS id,
    COALESCE(u.name, cm.person_id)         AS name,
    u.email                                AS email,
    cm.role_in_committee,
    cm.added_at
  FROM committee_members cm
  LEFT JOIN persons p ON p.id = cm.person_id                -- αν το cm.person_id δείχνει σε persons.id
  LEFT JOIN users   u ON u.id = p.user_id OR u.id = cm.person_id  -- fallback: κατευθείαν σε users.id
  WHERE cm.thesis_id = :id
  ORDER BY (cm.role_in_committee='supervisor') DESC, name
";
$cst = $pdo->prepare($sqlC);
$cst->execute([':id'=>$id]);
$committee = $cst->fetchAll(PDO::FETCH_ASSOC);


  $cst = $pdo->prepare($sqlC);
  $cst->execute([':id'=>$id]);
  $committee = $cst->fetchAll(PDO::FETCH_ASSOC);

  /* ----------------- GRADING block ----------------- */
  $grading = [
    'enabled'  => false,
    'deadline' => null,
    'my'       => ['submitted' => false, 'scores' => null, 'updated_at' => null],
    'others'   => [],
    'average'  => null,
  ];

  // enabled?  (προτίμηση σε στήλη theses.grading_enabled, αλλιώς "ενεργό αν υπάρχουν βαθμοί")
  $enabled = false;
  if ($hasCol('theses','grading_enabled')) {
    $ge = $pdo->prepare("SELECT grading_enabled FROM theses WHERE id=:tid");
    $ge->execute([':tid'=>$id]);
    $enabled = (bool)$ge->fetchColumn();
  } elseif ($hasTable('grades')) {
    $ge = $pdo->prepare("SELECT 1 FROM grades WHERE thesis_id=:tid LIMIT 1");
    $ge->execute([':tid'=>$id]);
    $enabled = (bool)$ge->fetchColumn();
  }
  $grading['enabled'] = $enabled;

  if ($enabled) {
    // δικός μου βαθμός (δοκιμάζουμε είτε με person_id=ME_PERSON είτε με person_id=ME_USER)
    if ($hasTable('grades')) {
      $mg = $pdo->prepare("
        SELECT criteria_scores_json, updated_at
        FROM grades
        WHERE thesis_id=:tid AND (person_id=:pid OR person_id=:uid)
        ORDER BY updated_at DESC
        LIMIT 1
      ");
      $mg->execute([':tid'=>$id, ':pid'=>$ME_PERSON, ':uid'=>$ME_USER]);
      if ($row = $mg->fetch(PDO::FETCH_ASSOC)) {
        $grading['my'] = [
          'submitted'  => true,
          'scores'     => json_decode($row['criteria_scores_json'] ?? 'null', true),
          'updated_at' => $row['updated_at'] ?? null,
        ];
      }
    }

    // οι υπόλοιποι της τριμελούς (όνομα/ρόλος/αν υπέβαλαν)
    $others = [];
    foreach ($committee as $cm) {
      $uid = (string)($cm['id'] ?? '');
      $submitted = false;
      if ($uid !== '' && $hasTable('grades')) {
        // έλεγχος ύπαρξης βαθμού είτε με person_id=users.id είτε persons.id (αν υπάρχει mapping)
        $pid = $uid;
        if ($hasTable('persons') && $hasCol('persons','user_id')) {
          $qpid = $pdo->prepare("SELECT id FROM persons WHERE user_id=:uid LIMIT 1");
          $qpid->execute([':uid'=>$uid]);
          $pid = (string)($qpid->fetchColumn() ?: $uid);
        }
        $qg = $pdo->prepare("SELECT 1 FROM grades WHERE thesis_id=:tid AND (person_id=:uid OR person_id=:pid) LIMIT 1");
        $qg->execute([':tid'=>$id, ':uid'=>$uid, ':pid'=>$pid]);
        $submitted = (bool)$qg->fetchColumn();
      }
      $others[] = [
        'person_id' => $uid,
        'name'      => $cm['name'] ?? null,
        'role'      => $cm['role_in_committee'] ?? null,
        'submitted' => $submitted,
        'total'     => null, // αν κρατάς συνολικό, γέμισέ το από το schema σου
      ];
    }
    $grading['others'] = $others;
  }

  j_ok(['thesis'=>$thesis, 'committee'=>$committee, 'grading'=>$grading]);
}

/* =======================================================
   LIST  (GET?action=list&status=&role=)
   - Αποφεύγουμε HY093: ΚΑΘΕ placeholder μοναδικό
   ======================================================= */
if ($method === 'GET' && ($action === '' || $action === 'list')) {
  $status = isset($_GET['status']) && $_GET['status'] !== '' ? trim((string)$_GET['status']) : null;
  $role   = isset($_GET['role'])   && $_GET['role']   !== '' ? trim((string)$_GET['role'])   : null;

  $sql = "
    SELECT
      t.id,
      t.status,
      t.created_at,
      t.updated_at,
      NULL AS official_assign_date,      /* βάλε εδώ την πραγματική στήλη αν υπάρχει */
      t.assigned_at,
      tp.title            AS topic_title,
      stu.name            AS student_name,
      stu.student_number,
      sup.name            AS supervisor_name,
      CASE
        WHEN t.supervisor_id = :me_user_base THEN 'supervisor'
        WHEN EXISTS (
          SELECT 1 FROM committee_members cm2
          WHERE cm2.thesis_id = t.id AND cm2.person_id = :me_person_base
        ) THEN 'member'
        ELSE NULL
      END AS my_role
    FROM theses t
    JOIN users  stu ON stu.id = t.student_id    AND stu.role = 'student'
    JOIN users  sup ON sup.id = t.supervisor_id AND sup.role = 'teacher'
    JOIN topics tp  ON tp.id = t.topic_id
    WHERE ( t.supervisor_id = :me_user_or
            OR EXISTS (
              SELECT 1 FROM committee_members cm
              WHERE cm.thesis_id = t.id AND cm.person_id = :me_person_or
            )
          )
  ";

  $params = [
    ':me_user_base'   => $ME_USER,
    ':me_person_base' => $ME_PERSON,
    ':me_user_or'     => $ME_USER,
    ':me_person_or'   => $ME_PERSON,
  ];

  if ($status !== null) {
    $sql .= " AND t.status = :st_filter";
    $params[':st_filter'] = $status;
  }

  if ($role === 'supervisor') {
    $sql .= " AND t.supervisor_id = :me_user_role";
    $params[':me_user_role'] = $ME_USER;
  } elseif ($role === 'member') {
    $sql .= " AND t.supervisor_id <> :me_user_role_not
              AND EXISTS (
                SELECT 1 FROM committee_members cm3
                WHERE cm3.thesis_id = t.id AND cm3.person_id = :me_person_role
              )";
    $params[':me_user_role_not'] = $ME_USER;
    $params[':me_person_role']   = $ME_PERSON;
  }

  $sql .= " ORDER BY t.updated_at DESC, t.created_at DESC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  j_ok($st->fetchAll(PDO::FETCH_ASSOC));
}

/* =======================================================
   ADD NOTE (POST?action=add_note)
   Body: thesis_id, body (<=300)
   ======================================================= */
if ($method === 'POST' && $action === 'add_note') {
  $thesis_id = trim((string)($_POST['thesis_id'] ?? ''));
  $body      = trim((string)($_POST['body'] ?? ''));
  if ($thesis_id === '' || $body === '') j_err('Λείπουν στοιχεία', 422);
  if (mb_strlen($body) > 300) j_err('Σημείωση έως 300 χαρακτήρες', 422);

  if (!teacher_has_access($pdo, $ME_USER, $ME_PERSON, $thesis_id)) {
    j_err('Δεν συμμετέχετε σε αυτή τη ΔΕ', 403);
  }

  // μόνο για ACTIVE
  $chk = $pdo->prepare("SELECT status FROM theses WHERE id = :id");
  $chk->execute([':id'=>$thesis_id]);
  $stt = $chk->fetchColumn();
  if ($stt !== 'active') j_err('Επιτρέπεται μόνο σε ACTIVE', 403);

  $ins = $pdo->prepare("INSERT INTO notes (thesis_id, author_prof_id, body, created_at)
                        VALUES (:t, :p, :b, NOW())");
  $ins->execute([':t'=>$thesis_id, ':p'=>$ME_USER, ':b'=>$body]);
  j_ok(['id'=>$pdo->lastInsertId()]);
}

/* =======================================================
   MARK UNDER REVIEW (POST?action=mark_under_exam)
   ======================================================= */
if ($method === 'POST' && $action === 'mark_under_exam') {
  $thesis_id = trim((string)($_POST['thesis_id'] ?? ''));
  if ($thesis_id === '') j_err('Λείπει thesis_id', 422);

  $st = $pdo->prepare("UPDATE theses
                       SET status='under_review', updated_at=NOW()
                       WHERE id=:id AND supervisor_id=:me AND status='active'");
  $st->execute([':id'=>$thesis_id, ':me'=>$ME_USER]);
  if ($st->rowCount() === 0) j_err('Δεν επιτρέπεται ή δεν είναι ACTIVE', 403);
  j_ok(['thesis_id'=>$thesis_id,'status'=>'under_review']);
}

/* =======================================================
   CANCEL AFTER 2Y (POST?action=cancel_after_2y)
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
    ':id'=>$thesis_id, ':me'=>$ME_USER
  ]);
  if ($st->rowCount() === 0) j_err('Δεν πληροί προϋποθέσεις (2 έτη, ACTIVE, supervisor)', 403);
  j_ok(['thesis_id'=>$thesis_id, 'status'=>'canceled']);
}

/* =======================================================
   INVITATIONS INBOX (GET?action=invitations)
   ======================================================= */
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
    WHERE inv.person_id = :me_person_inbox
      AND inv.accepted_at IS NULL
      AND inv.rejected_at IS NULL
    ORDER BY inv.invited_at DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':me_person_inbox' => $ME_PERSON]);
  j_ok($st->fetchAll(PDO::FETCH_ASSOC));
}

/* =======================================================
   ACCEPT / REJECT INVITATION (POST)
   Body: invitation_id
   ======================================================= */
if ($method === 'POST' && ($action === 'accept_invitation' || $action === 'reject_invitation')) {
  $inv_id = trim((string)($_POST['invitation_id'] ?? ''));
  if ($inv_id === '') j_err('Λείπει invitation_id', 422);

  $isAccept = $action === 'accept_invitation';
  $col      = $isAccept ? 'accepted_at' : 'rejected_at';
  $newSt    = $isAccept ? 'accepted'    : 'rejected';

  $pdo->beginTransaction();

  $up = $pdo->prepare("
    UPDATE committee_invitations
    SET status=:st, $col=NOW(), responded_at=NOW()
    WHERE id=:id
      AND person_id=:me_person_upd
      AND accepted_at IS NULL
      AND rejected_at IS NULL
  ");
  $up->execute([':st'=>$newSt, ':id'=>$inv_id, ':me_person_upd'=>$ME_PERSON]);
  if ($up->rowCount() === 0) { $pdo->rollBack(); j_err('Δεν βρέθηκε ενεργή πρόσκληση', 404); }

  if ($isAccept) {
    $tid = $pdo->prepare("SELECT thesis_id FROM committee_invitations WHERE id=:id");
    $tid->execute([':id'=>$inv_id]);
    $thesis_id = (string)$tid->fetchColumn();

    if ($thesis_id !== '') {
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
      NULL AS official_assign_date,
      t.assigned_at,
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

  $notes = [];
  try {
    if ($pdo->query("SHOW TABLES LIKE 'notes'")->fetch()) {
      $nn = $pdo->prepare("
        SELECT n.id, n.body, n.created_at
        FROM notes n
        WHERE n.thesis_id = :id AND n.author_prof_id = :me
        ORDER BY n.created_at DESC
        LIMIT 10
      ");
      $nn->execute([':id'=>$id, ':me'=>$ME_USER]);
      $notes = $nn->fetchAll(PDO::FETCH_ASSOC);
    }
  } catch (Throwable $e) {}

  j_ok(['thesis'=>$thesis, 'committee'=>$committee, 'notes'=>$notes]);
}

/* default */
j_err('Άγνωστη ενέργεια', 400, ['method'=>$method,'action'=>$action]);
