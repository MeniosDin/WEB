<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  $DB_HOST='127.0.0.1'; $DB_PORT='3307'; $DB_NAME='thesis_db'; $DB_USER='root'; $DB_PASS='';
  $pdo = new PDO("mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);

  session_start();
  $user_id   = $_SESSION['user_id']   ?? null;
  $person_id = $_SESSION['person_id'] ?? null;
  if (!$user_id || !$person_id) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }

  $thesis_id = $_GET['thesis_id'] ?? '';
  if (!$thesis_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Λείπει thesis_id']); exit; }

  // Επιτρέπουμε προβολή μόνο σε επιβλέποντα ή μέλος (ίδιος έλεγχος όπως πάνω)
  $th = $pdo->prepare('SELECT id, supervisor_id, status, grading_enabled_at FROM theses WHERE id=:id');
  $th->execute([':id'=>$thesis_id]);
  $t = $th->fetch();
  if (!$t) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Δεν βρέθηκε διπλωματική']); exit; }

  $isSupervisor = ($t['supervisor_id'] === $person_id);
  $isMember = false;
  if (!$isSupervisor) {
    $q = $pdo->prepare('SELECT 1 FROM committee_members WHERE thesis_id=:tid AND person_id=:pid');
    $q->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
    $isMember = (bool)$q->fetchColumn();
    if (!$isMember) {
      $q2 = $pdo->prepare('SELECT 1 FROM committee_invitations WHERE thesis_id=:tid AND person_id=:pid AND inv_status="accepted"');
      $q2->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
      $isMember = (bool)$q2->fetchColumn();
    }
  }
  if (!$isSupervisor && !$isMember) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Δεν επιτρέπεται']); exit; }

  $rows = $pdo->prepare('
    SELECT g.person_id, p.first_name, p.last_name, g.total, g.criteria_scores_json, g.created_at
    FROM grades g
    LEFT JOIN persons p ON p.id = g.person_id
    WHERE g.thesis_id = :tid
    ORDER BY g.created_at DESC
  ');
  $rows->execute([':tid'=>$thesis_id]);
  echo json_encode(['ok'=>true, 'items'=>$rows->fetchAll()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Σφάλμα: '.$e->getMessage()]);
}
