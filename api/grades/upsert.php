<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';

try {
  $pdo = db();
  $u   = require_role('teacher','admin');   // μόνο διδάσκοντες/διαχειριστές

  $user_id = (string)$u['id'];

  // --- Βρες person_id του χρήστη (από το session αν υπάρχει, αλλιώς fallback μέσω persons.user_id)
  $session_person_id = $_SESSION['person_id'] ?? null;
  if ($session_person_id) {
    $person_id = (string)$session_person_id;
  } else {
    $q = $pdo->prepare('SELECT id FROM persons WHERE user_id = :uid LIMIT 1');
    $q->execute([':uid'=>$user_id]);
    $row = $q->fetch();
    if (!$row) bad('Δεν βρέθηκε πρόσωπο για τον χρήστη.', 403);
    $person_id = (string)$row['id'];
  }

  // --- Input
  $thesis_id = $_POST['thesis_id'] ?? '';
  $total     = isset($_POST['total']) ? (float)$_POST['total'] : null;
  $criteria  = $_POST['criteria_scores_json'] ?? null; // JSON string (προαιρετικό)

  $rubric_id = $_POST['rubric_id'] ?? null;

// Αν δεν στάλθηκε rubric_id, πάρε το ενεργό από grading_rubrics
if ($rubric_id === null || $rubric_id === '') {
  $q = $pdo->query("
    SELECT id
    FROM grading_rubrics
    WHERE effective_from <= CURDATE()
      AND (effective_to IS NULL OR effective_to >= CURDATE())
    ORDER BY effective_from DESC
    LIMIT 1
  ");
  $rubric_id = $q->fetchColumn() ?: null;
}

// Αν το grades.rubric_id είναι NOT NULL στη βάση σου και δεν βρέθηκε ενεργό rubric,
// βάλε ένα σταθερό που υπάρχει ήδη ή άλλαξε το column σε NULLABLE.
if ($rubric_id === null) {
  // είτε: $rubric_id = 'f11816d7-8e6d-11f0-8503-d8bbc1070448'; // παράδειγμα υπαρκτού rubric
  // είτε: κάνε το column grades.rubric_id NULLABLE
}


  if (!$thesis_id || $total === null) bad('Λείπουν πεδία (thesis_id, total).', 400);
  if ($total < 0 || $total > 10)      bad('Ο βαθμός πρέπει να είναι 0–10.', 422);

  // --- ΔΕ: πρέπει να είναι under_review + ενεργή βαθμολόγηση
  $t = $pdo->prepare('SELECT id,status,supervisor_id,grading_enabled_at FROM theses WHERE id=:id LIMIT 1');
  $t->execute([':id'=>$thesis_id]);
  $th = $t->fetch();
  if (!$th)                              bad('Δεν βρέθηκε διπλωματική.', 404);
  if ($th['status'] !== 'under_review')  bad('Η βαθμολόγηση επιτρέπεται μόνο σε «Υπό εξέταση».', 409);
  if (empty($th['grading_enabled_at']))  bad('Η βαθμολόγηση δεν είναι ενεργή για αυτή τη ΔΕ.', 409);

  // --- Authorization (επιβλέπων ή μέλος τριμελούς)
  $is_supervisor = false;

  // (A) supervisor_id = persons.id
  $q1 = $pdo->prepare('SELECT 1 FROM theses WHERE id=:tid AND supervisor_id=:pid LIMIT 1');
  $q1->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
  $is_supervisor = (bool)$q1->fetchColumn();

  // (B) supervisor_id = users.id (join μέσω persons.user_id)
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

  // Μήπως είναι μέλος τριμελούς;
  $is_committee = false;
  try {
    // committee_members.person_id
    $c1 = $pdo->prepare('SELECT 1 FROM committee_members WHERE thesis_id=:tid AND person_id=:pid LIMIT 1');
    $c1->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
    $is_committee = (bool)$c1->fetchColumn();

    // committee_members.user_id (αν υπάρχει)
    if (!$is_committee) {
      $c2 = $pdo->prepare('SELECT 1 FROM committee_members WHERE thesis_id=:tid AND user_id=:uid LIMIT 1');
      $c2->execute([':tid'=>$thesis_id, ':uid'=>$user_id]);
      $is_committee = (bool)$c2->fetchColumn();
    }

    // ή accepted invitation
    if (!$is_committee) {
      $c3 = $pdo->prepare('
        SELECT 1 FROM committee_invitations
        WHERE thesis_id=:tid
          AND (person_id=:pid OR user_id=:uid)
          AND inv_status="accepted"
        LIMIT 1
      ');
      $c3->execute([':tid'=>$thesis_id, ':pid'=>$person_id, ':uid'=>$user_id]);
      $is_committee = (bool)$c3->fetchColumn();
    }
  } catch (\Throwable $e) {
    /* αν δεν υπάρχουν οι πίνακες/στήλες, απλά το αγνοούμε */
  }

  if (!$is_supervisor && !$is_committee) bad('Δεν έχεις δικαίωμα καταχώρισης βαθμού.', 403);

  // --- Βρες ενεργή ρουμπρίκα (για να ΜΗΝ είναι NULL το rubric_id)
  $rubric_id = null;
  $r = $pdo->query("
    SELECT id
    FROM grading_rubrics
    WHERE (effective_from IS NULL OR effective_from <= CURDATE())
      AND (effective_to   IS NULL OR effective_to   >  CURDATE())
    ORDER BY effective_from DESC
    LIMIT 1
  ")->fetch();
  if ($r) {
    $rubric_id = (string)$r['id'];
  } else {
    // fallback: πάρε οποιαδήποτε υπάρχει
    $rr = $pdo->query("SELECT id FROM grading_rubrics ORDER BY effective_from DESC LIMIT 1")->fetch();
    if ($rr) $rubric_id = (string)$rr['id'];
  }
  if (!$rubric_id) bad('Δεν υπάρχει διαθέσιμη ρουμπρίκα βαθμολόγησης.', 500);

  // --- UPSERT: ο δικός ΜΟΥ βαθμός γι’ αυτή τη ΔΕ
  $sql = "
    INSERT INTO grades (thesis_id, person_id, rubric_id, criteria_scores_json, total, created_at)
    VALUES (:tid, :pid, :rub, :crit, :tot, NOW())
    ON DUPLICATE KEY UPDATE
      rubric_id = VALUES(rubric_id),
      criteria_scores_json = VALUES(criteria_scores_json),
      total = VALUES(total),
      created_at = NOW()
  ";
  $u = $pdo->prepare($sql);
  $u->execute([
    ':tid'=>$thesis_id,
    ':pid'=>$person_id,
    ':rub'=>$rubric_id,
    ':crit'=>$criteria,
    ':tot'=>$total,
  ]);

  ok(['saved'=>true]);

} catch (Throwable $e) {
  bad('Σφάλμα: '.$e->getMessage(), 500);
}
