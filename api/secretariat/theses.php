<?php
declare(strict_types=1);

/* ----- JSON helpers ----- */
function j_ok($p=[], int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true]+$p, JSON_UNESCAPED_UNICODE);
  exit;
}
function j_err($msg, int $code=400){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ----- Bootstrap / DB / Auth ----- */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_role('secretariat');   // πρέπει να είσαι γραμματεία
$pdo = db();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* =========================
 * LIST
 * GET /api/secretariat/theses.php?action=list&status=active,under_review
 * ========================= */
if ($action === 'list') {
  $statusParam = (string)($_GET['status'] ?? 'active,under_review');
  $allowed = ['under_assignment','active','under_review','completed','canceled'];
  $want = array_values(array_filter(
    array_map('trim', explode(',', $statusParam)),
    fn($s)=>in_array($s,$allowed,true)
  ));
  if (!$want) $want = ['active','under_review'];

  $in = implode(',', array_fill(0, count($want), '?'));
  $sql = "
    SELECT
      t.id, t.status, t.assigned_at, t.created_at, t.topic_id,
      tp.title       AS topic_title,
      tp.summary     AS topic_summary,
      COALESCE(tp.pdf_path, tp.spec_pdf_path) AS topic_pdf_path,
      stu.name       AS student_name,
      stu.student_number,
      sup.name       AS supervisor_name
    FROM theses t
    JOIN topics tp  ON tp.id = t.topic_id
    JOIN users  stu ON stu.id = t.student_id
    JOIN users  sup ON sup.id = t.supervisor_id
    WHERE t.status IN ($in)
    ORDER BY t.created_at DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($want);
  j_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* =========================
 * DETAILS
 * GET /api/secretariat/theses.php?action=details&id=THESIS_ID
 * ========================= */
if ($action === 'details') {
  $id = $_GET['id'] ?? '';
  if (!$id) j_err('Λείπει id', 422);

  // thesis + student + supervisor
  $th = $pdo->prepare("
    SELECT t.*, stu.name AS student_name, stu.student_number, sup.name AS supervisor_name
    FROM theses t
    JOIN users stu ON stu.id = t.student_id
    JOIN users sup ON sup.id = t.supervisor_id
    WHERE t.id = :id
    LIMIT 1
  ");
  $th->execute([':id'=>$id]);
  $thesis = $th->fetch(PDO::FETCH_ASSOC);
  if (!$thesis) j_err('Δεν βρέθηκε η διπλωματική.', 404);

  // topic
  $tp = $pdo->prepare("
    SELECT id, title, summary, COALESCE(pdf_path, spec_pdf_path) AS pdf_path
    FROM topics WHERE id=:tid LIMIT 1
  ");
  $tp->execute([':tid'=>$thesis['topic_id']]);
  $topic = $tp->fetch(PDO::FETCH_ASSOC) ?: [];

  // committee members (committee_members -> persons)
  $cm = $pdo->prepare("
    SELECT cm.role_in_committee, p.first_name, p.last_name, p.email
    FROM committee_members cm
    JOIN persons p ON p.id = cm.person_id
    WHERE cm.thesis_id = :tid
    ORDER BY (cm.role_in_committee='supervisor') DESC, p.last_name
  ");
  $cm->execute([':tid'=>$id]);
  $committee = $cm->fetchAll(PDO::FETCH_ASSOC);

  j_ok(['data'=>[
    'thesis'    => $thesis,
    'topic'     => $topic,
    'committee' => $committee
  ]]);
}

j_err('Άγνωστο action', 400);
