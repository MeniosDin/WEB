<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';

try {
  $pdo = db();

  // Μόνο διδάσκοντες ή admin
  $u = require_role('teacher', 'admin');
  $user_id = (string)$u['id'];

  // ===== Person resolution (session -> persons.user_id) =====
  $session_person_id = $_SESSION['person_id'] ?? null;
  if ($session_person_id) {
    $person_id = (string)$session_person_id;
  } else {
    $st = $pdo->prepare('SELECT id FROM persons WHERE user_id = :uid LIMIT 1');
    $st->execute([':uid' => $user_id]);
    $me = $st->fetch();
    if (!$me) bad('Δεν βρέθηκε πρόσωπο για τον χρήστη.', 403);
    $person_id = (string)$me['id'];
  }

  // ===== Input =====
  $thesis_id = $_POST['thesis_id'] ?? '';
  $total_raw = $_POST['total'] ?? null;
  $criteria  = $_POST['criteria_scores_json'] ?? '{}';
  $rubric_id = $_POST['rubric_id'] ?? null;

  // normalize total (δέχεται "8", 8, 8.0 κ.λπ.)
  $total = isset($total_raw) && $total_raw !== '' ? (float)$total_raw : null;

  // criteria: ποτέ null/empty → '{}'
  if ($criteria === '' || $criteria === null) { $criteria = '{}'; }

  if (!$thesis_id || $total === null) {
    bad('Λείπουν πεδία (thesis_id, total).', 400);
  }
  if ($total < 0 || $total > 10) {
    bad('Ο βαθμός πρέπει να είναι 0–10.', 422);
  }

  // ===== Thesis checks (status + grading_enabled) =====
  $tx = $pdo->prepare('SELECT id,status,supervisor_id,grading_enabled_at FROM theses WHERE id = :id LIMIT 1');
  $tx->execute([':id' => $thesis_id]);
  $th = $tx->fetch();
  if (!$th)                         bad('Δεν βρέθηκε διπλωματική.', 404);
  if ($th['status'] !== 'under_review')
                                   bad('Η βαθμολόγηση επιτρέπεται μόνο σε «Υπό εξέταση».', 409);
  if (empty($th['grading_enabled_at']))
                                   bad('Η βαθμολόγηση δεν είναι ενεργή για αυτή τη ΔΕ.', 409);

  // ===== Authorization: supervisor ή μέλος τριμελούς =====
  $is_supervisor = false;

  // (Α) supervisor_id = persons.id
  $q1 = $pdo->prepare('SELECT 1 FROM theses WHERE id=:tid AND supervisor_id=:pid LIMIT 1');
  $q1->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
  $is_supervisor = (bool)$q1->fetchColumn();

  // (Β) supervisor_id = users.id (join μέσω persons.user_id)
  if (!$is_supervisor) {
    $q2 = $pdo->prepare('
      SELECT 1
      FROM theses t
      JOIN persons sp ON sp.user_id = :uid
      WHERE t.id = :tid AND t.supervisor_id = sp.user_id
      LIMIT 1
    ');
    $q2->execute([':tid'=>$thesis_id, ':uid'=>$user_id]);
    $is_supervisor = (bool)$q2->fetchColumn();
  }

  // Is committee member?
  $is_committee = false;
  try {
    $c1 = $pdo->prepare('SELECT 1 FROM committee_members WHERE thesis_id=:tid AND person_id=:pid LIMIT 1');
    $c1->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
    $is_committee = (bool)$c1->fetchColumn();

    if (!$is_committee) {
      $c2 = $pdo->prepare('SELECT 1 FROM committee_members WHERE thesis_id=:tid AND user_id=:uid LIMIT 1');
      $c2->execute([':tid'=>$thesis_id, ':uid'=>$user_id]);
      $is_committee = (bool)$c2->fetchColumn();
    }

    if (!$is_committee) {
      $c3 = $pdo->prepare('
        SELECT 1
        FROM committee_invitations
        WHERE thesis_id = :tid
          AND (person_id = :pid OR user_id = :uid)
          AND inv_status = "accepted"
        LIMIT 1
      ');
      $c3->execute([':tid'=>$thesis_id, ':pid'=>$person_id, ':uid'=>$user_id]);
      $is_committee = (bool)$c3->fetchColumn();
    }
  } catch (\Throwable $e) {
    // αν λείπουν πίνακες/στήλες committee, απλά το προσπερνάμε
  }

  if (!$is_supervisor && !$is_committee) {
    bad('Δεν έχεις δικαίωμα καταχώρισης βαθμού.', 403);
  }

  // ===== Active rubric (if not provided) =====
  if (!$rubric_id) {
    $qRub = $pdo->query("
      SELECT id
      FROM grading_rubrics
      WHERE (effective_from IS NULL OR effective_from <= CURDATE())
        AND (effective_to   IS NULL OR effective_to   >  CURDATE())
      ORDER BY COALESCE(effective_from,'1900-01-01') DESC
      LIMIT 1
    ");
    $rubric_id = $qRub->fetchColumn() ?: null;
  }
  if (!$rubric_id) bad('Δεν υπάρχει ενεργός πίνακας κριτηρίων (rubric).', 409);

  // ===== UPSERT (idempotent) =====
  $sql = "
    INSERT INTO grades (thesis_id, person_id, rubric_id, criteria_scores_json, total, created_at)
    VALUES (:tid, :pid, :rub, :crit, :tot, NOW())
    ON DUPLICATE KEY UPDATE
      rubric_id            = VALUES(rubric_id),
      criteria_scores_json = VALUES(criteria_scores_json),
      total                = VALUES(total),
      updated_at           = NOW()
  ";
  $u = $pdo->prepare($sql);
  $u->execute([
    ':tid'  => $thesis_id,
    ':pid'  => $person_id,
    ':rub'  => $rubric_id,
    ':crit' => $criteria,
    ':tot'  => $total,
  ]);

  ok(['saved' => true, 'rubric_id' => $rubric_id]);

} catch (Throwable $e) {
  bad('Σφάλμα: '.$e->getMessage(), 500);
}
