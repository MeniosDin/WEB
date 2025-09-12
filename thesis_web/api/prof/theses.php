<?php
declare(strict_types=1);

// === self-contained JSON helpers ===
function j_ok($data=[], int $code=200){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function j_err($msg, int $code=400, $extra=[]){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg,'extra'=>$extra], JSON_UNESCAPED_UNICODE); exit; }

// === DB (χρησιμοποιεί το δικό σου api/db.php) ===
require_once __DIR__ . '/../db.php'; // στο screenshot βλέπω ήδη api/db.php

require_once __DIR__ . '/../bootstrap.php';

// Εξασφάλισε ότι τρέχει μόνο για role=teacher
$me = require_role('teacher');

// $me έχει: ['id'=>..., 'role'=>'teacher']
$pdo = $GLOBALS['pdo'];


$pdo = db(); // από το δικό σου api/db.php
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// ΣΗΜΑΝΤΙΚΟ: πιθανόν να έχεις διαφορετικά ονόματα πινάκων/πεδίων.
// Οι παρακάτω queries είναι “ασφαλείς” και εύκολα τροποποιήσιμες.

if ($method === 'GET' && ($action === '' || $action === 'list')) {
  $status = $_GET['status'] ?? null;   // π.χ. UNDER_ASSIGNMENT/ACTIVE/UNDER_EXAM/COMPLETED/CANCELED
  $role   = $_GET['role']   ?? null;   // 'supervisor' | 'member' | null

  $sql = "
    SELECT
      t.id,
      s.name AS student,          -- από users.name
      tp.title,
      t.status,
      t.created_at,
      t.updated_at
    FROM theses t
    JOIN users  s  ON s.id = t.student_id AND s.role = 'student'
    JOIN topics tp ON tp.id = t.topic_id
    WHERE
      (
        t.supervisor_id = :me
        OR EXISTS (
          SELECT 1
          FROM committee_members cm
          WHERE cm.thesis_id = t.id
            AND cm.person_id = :me2      -- ΣΩΣΤΗ στήλη στο δικό σου schema
        )
      )
  ";

  $params = [':me' => $me['id'], ':me2' => $me['id']];

  // προαιρετικό φίλτρο κατάστασης
  if ($status !== null && $status !== '') {
    $sql .= " AND t.status = :status";
    $params[':status'] = $status;
  }

  // φίλτρο ρόλου στην εκάστοτε διπλωματική
  if ($role === 'supervisor') {
    $sql .= " AND t.supervisor_id = :me";
  } elseif ($role === 'member') {
    $sql .= " AND t.supervisor_id <> :me
             AND EXISTS (
               SELECT 1 FROM committee_members cm2
               WHERE cm2.thesis_id = t.id AND cm2.person_id = :me
             )";
  }

  $sql .= " ORDER BY t.updated_at DESC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  j_ok($st->fetchAll());
}

// ιδιωτική σημείωση (ACTIVE μόνο)
if ($method === 'POST' && $action === 'add_note') {
  $thesis_id = (int)($_POST['thesis_id'] ?? 0);
  $body = trim((string)($_POST['body'] ?? ''));
  if ($thesis_id<=0 || $body==='') j_err('Λείπουν στοιχεία');
  if (mb_strlen($body)>300) j_err('Σημείωση έως 300 χαρακτήρες');

  $check = $pdo->prepare("SELECT t.id, t.status,
                                 (t.supervisor_id=:me) AS is_sup,
                                 EXISTS(SELECT 1 FROM committee_members cm WHERE cm.thesis_id=t.id AND cm.professor_id=:me) AS is_mem
                          FROM theses t WHERE t.id=:id");
  $check->execute([':me'=>$me['id'], ':id'=>$thesis_id]);
  $row = $check->fetch();
  if (!$row) j_err('Δεν βρέθηκε διπλωματική',404);
  if ($row['status']!=='ACTIVE') j_err('Επιτρέπεται μόνο σε ACTIVE');
  if (!$row['is_sup'] && !$row['is_mem']) j_err('Δεν συμμετέχετε σε αυτή τη ΔΕ',403);

  $st = $pdo->prepare("INSERT INTO notes (thesis_id, author_prof_id, body, created_at)
                       VALUES (:t,:p,:b, NOW())");
  $st->execute([':t'=>$thesis_id, ':p'=>$me['id'], ':b'=>$body]);
  j_ok(['id'=>$pdo->lastInsertId()]);
}

// αλλαγή σε UNDER_EXAM (supervisor only από ACTIVE)
if ($method === 'POST' && $action === 'mark_under_exam') {
  $thesis_id = (int)($_POST['thesis_id'] ?? 0);
  if ($thesis_id<=0) j_err('Λείπει thesis_id');

  $st = $pdo->prepare("UPDATE theses
                       SET status='UNDER_EXAM', updated_at=NOW()
                       WHERE id=:id AND supervisor_id=:me AND status='ACTIVE'");
  $st->execute([':id'=>$thesis_id, ':me'=>$me['id']]);
  if ($st->rowCount()===0) j_err('Δεν επιτρέπεται ή δεν είναι ACTIVE',403);
  j_ok(['thesis_id'=>$thesis_id,'status'=>'UNDER_EXAM']);
}

// ακύρωση μετά 2 έτη (supervisor only)
if ($method === 'POST' && $action === 'cancel_after_2y') {
  $thesis_id = (int)($_POST['thesis_id'] ?? 0);
  $mn = trim((string)($_POST['minutes_number'] ?? ''));
  $my = trim((string)($_POST['minutes_year'] ?? ''));
  if ($thesis_id<=0 || $mn==='' || $my==='') j_err('Λείπουν στοιχεία');

  $st = $pdo->prepare("UPDATE theses
                       SET status='CANCELED', minutes_number=:mn, minutes_year=:my, updated_at=NOW()
                       WHERE id=:id AND supervisor_id=:me AND status='ACTIVE'
                         AND TIMESTAMPDIFF(YEAR, official_assign_date, NOW()) >= 2");
  $st->execute([':mn'=>$mn, ':my'=>$my, ':id'=>$thesis_id, ':me'=>$me['id']]);
  if ($st->rowCount()===0) j_err('Δεν πληροί προϋποθέσεις (2 έτη, ACTIVE, supervisor)',403);
  j_ok(['thesis_id'=>$thesis_id,'status'=>'CANCELED']);
}

// προσκλήσεις τριμελούς (inbox)
if ($method === 'GET' && $action === 'invitations') {
  $st = $pdo->prepare("SELECT cm.thesis_id, tp.title, s.full_name AS student,
                              cm.invited_at, cm.accepted_at, cm.rejected_at
                       FROM committee_members cm
                       JOIN theses t ON t.id=cm.thesis_id
                       JOIN topics tp ON tp.id=t.topic_id
                       JOIN students s ON s.id=t.student_id
                       WHERE cm.professor_id=:me AND cm.accepted_at IS NULL AND cm.rejected_at IS NULL
                       ORDER BY cm.invited_at DESC");
  $st->execute([':me'=>$me['id']]);
  j_ok($st->fetchAll());
}

// αποδοχή / απόρριψη πρόσκλησης
if ($method === 'POST' && ($action === 'accept_invitation' || $action === 'reject_invitation')) {
  $thesis_id = (int)($_POST['thesis_id'] ?? 0);
  if ($thesis_id<=0) j_err('Λείπει thesis_id');
  $col = $action === 'accept_invitation' ? 'accepted_at' : 'rejected_at';
  $st = $pdo->prepare("UPDATE committee_members SET $col=NOW()
                       WHERE thesis_id=:t AND professor_id=:me AND accepted_at IS NULL AND rejected_at IS NULL");
  $st->execute([':t'=>$thesis_id, ':me'=>$me['id']]);
  if ($st->rowCount()===0) j_err('Δεν βρέθηκε ενεργή πρόσκληση',404);
  j_ok(['thesis_id'=>$thesis_id, $col=>true]);
}

if ($method==='GET' && $action==='details') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id<=0) j_err('Λείπει id',422);

  // Βασικά στοιχεία + έλεγχος πρόσβασης
  $st = $pdo->prepare("
    SELECT
      t.*,
      s.full_name      AS student_name,
      s.student_number AS student_number,
      sup.full_name    AS supervisor_name
    FROM theses t
    JOIN students s      ON s.id = t.student_id
    JOIN professors sup  ON sup.id = t.supervisor_id
    WHERE t.id=:id
      AND (t.supervisor_id=:me OR EXISTS(
        SELECT 1 FROM committee_members cm
        WHERE cm.thesis_id=t.id AND cm.professor_id=:me
      ))
    LIMIT 1
  ");
  $st->execute([':id'=>$id, ':me'=>$me['id']]);
  $th = $st->fetch();
  if (!$th) j_err('Δεν βρέθηκε ή δεν έχετε πρόσβαση',404);

  // Μέλη τριμελούς
  $st = $pdo->prepare("
    SELECT cm.user_id, p.full_name AS name, cm.role_in_committee
    FROM committee_members cm
    JOIN professors p ON p.id = cm.user_id
    WHERE cm.thesis_id=:id
    ORDER BY p.full_name
  ");
  $st->execute([':id'=>$id]);
  $committee = $st->fetchAll();

  j_ok([
    'thesis'    => $th,
    'committee' => $committee,
  ]);
}

// =========================
// SHOW (GET?action=show&id=...)
// =========================
if ($method === 'GET' && $action === 'show') {
  $id = trim((string)($_GET['id'] ?? ''));
  if ($id === '') j_err('Λείπει id', 422);

  // Ο χρήστης πρέπει να είναι επιβλέπων ή μέλος τριμελούς αυτής της ΔΕ
  $check = $pdo->prepare("
    SELECT t.id
    FROM theses t
    WHERE t.id=:id AND (
      t.supervisor_id = :me OR
      EXISTS (
        SELECT 1 FROM committee_members cm
        WHERE cm.thesis_id = t.id AND cm.person_id = :me
      )
    )
    LIMIT 1
  ");
  $check->execute([':id'=>$id, ':me'=>$me['id']]);
  if (!$check->fetch()) j_err('Δεν έχετε πρόσβαση ή δεν βρέθηκε', 403);

  // βασικά στοιχεία
  $st = $pdo->prepare("
    SELECT
      t.id, t.status, t.created_at, t.updated_at,
      t.official_assign_date, t.assigned_at,
      u.name AS student_name, u.student_number,
      tp.title AS topic_title
    FROM theses t
    JOIN users  u  ON u.id = t.student_id AND u.role='student'
    JOIN topics tp ON tp.id = t.topic_id
    WHERE t.id=:id
    LIMIT 1
  ");
  $st->execute([':id'=>$id]);
  $thesis = $st->fetch();

  // μέλη τριμελούς
  $cm = $pdo->prepare("
    SELECT cm.person_id, cm.role_in_committee, cm.added_at, u.name
    FROM committee_members cm
    JOIN users u ON u.id = cm.person_id
    WHERE cm.thesis_id=:id
    ORDER BY (cm.role_in_committee='supervisor') DESC, u.name
  ");
  $cm->execute([':id'=>$id]);
  $committee = $cm->fetchAll();

  // πρόσφατες σημειώσεις (αν έχεις πίνακα notes)
  $notes = [];
  if ($pdo->query("SHOW TABLES LIKE 'notes'")->fetch()) {
    $nn = $pdo->prepare("
      SELECT n.id, n.body, n.created_at, u.name AS author
      FROM notes n
      JOIN users u ON u.id = n.author_prof_id
      WHERE n.thesis_id=:id
      ORDER BY n.created_at DESC
      LIMIT 10
    ");
    $nn->execute([':id'=>$id]);
    $notes = $nn->fetchAll();
  }

  j_ok(['thesis'=>$thesis, 'committee'=>$committee, 'notes'=>$notes]);
}

j_err('Άγνωστη ενέργεια',400,['method'=>$method,'action'=>$action]);
