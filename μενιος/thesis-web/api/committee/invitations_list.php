<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo  = db();
  $me   = require_login(); // ['id'=>..., 'role'=> 'student'|'teacher'|'secretariat']

  // helper για απάντηση
  $OK = function(array $data = []) { ok(['data' => $data]); };

  // ====== Αν δόθηκε thesis_id → Thesis-mode (student ή teacher με σχέση στο thesis)
  $thesisId = isset($_GET['thesis_id']) ? trim($_GET['thesis_id']) : '';
  if ($thesisId !== '') {
    // Έλεγχος δικαιώματος πρόσβασης
    $isAllowed = false;

    if ($me['role'] === 'student') {
      // ο φοιτητής της διπλωματικής
      $st = $pdo->prepare("SELECT 1 FROM theses WHERE id = ? AND student_id = ? LIMIT 1");
      $st->execute([$thesisId, $me['id']]);
      $isAllowed = (bool)$st->fetchColumn();
    } else if ($me['role'] === 'teacher' || $me['role'] === 'secretariat') {
      // επιβλέπων ή μέλος τριμελούς
      // επιβλέπων:
      $st1 = $pdo->prepare("SELECT 1 FROM theses WHERE id = ? AND supervisor_id = ? LIMIT 1");
      $st1->execute([$thesisId, $me['id']]);
      $isAllowed = (bool)$st1->fetchColumn();

      if (!$isAllowed) {
        // μέλος τριμελούς (μέσω persons.user_id)
        $st2 = $pdo->prepare("
          SELECT 1
            FROM committee_members cm
            JOIN persons p ON p.id = cm.person_id
           WHERE cm.thesis_id = ? AND p.user_id = ?
           LIMIT 1
        ");
        $st2->execute([$thesisId, $me['id']]);
        $isAllowed = (bool)$st2->fetchColumn();
      }
    }

    if (!$isAllowed) { bad('Forbidden', 403); }

    // Metadate thesis (για header στο UI φοιτητή)
    $meta = $pdo->prepare("
      SELECT t.id,
             tp.title               AS topic_title,
             stu.name               AS student_name,
             stu.student_number     AS student_number,
             sup.name               AS supervisor_name
        FROM theses t
        JOIN topics tp ON tp.id = t.topic_id
        JOIN users  stu ON stu.id = t.student_id
        JOIN users  sup ON sup.id = t.supervisor_id
       WHERE t.id = ?
       LIMIT 1
    ");
    $meta->execute([$thesisId]);
    $thesis = $meta->fetch(PDO::FETCH_ASSOC) ?: [];

    // Προσκλήσεις για το συγκεκριμένο thesis
    $q = $pdo->prepare("
      SELECT ci.id,
             ci.thesis_id,
             ci.person_id,
             ci.status,
             ci.invited_at,
             CONCAT_WS(' ', p.first_name, p.last_name) AS member_name
        FROM committee_invitations ci
        JOIN persons p ON p.id = ci.person_id
       WHERE ci.thesis_id = ?
       ORDER BY ci.invited_at DESC
    ");
    $q->execute([$thesisId]);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);

    $OK(['thesis' => $thesis, 'items' => $items]);
  }

  // ====== Χωρίς thesis_id → Teacher-mode: λίστα προσκλήσεων του τρέχοντος καθηγητή
  require_role('teacher', 'secretariat'); // εδώ πρέπει να είναι teacher (ή γραμματεία)
  // χαρτογράφηση user -> person
  $p = $pdo->prepare("SELECT id FROM persons WHERE user_id = ? LIMIT 1");
  $p->execute([$me['id']]);
  $personId = $p->fetchColumn();
  if (!$personId) { $OK(['items' => []]); }

  $q = $pdo->prepare("
    SELECT ci.id,
           ci.status,
           ci.invited_at,
           t.id                    AS thesis_id,
           tp.title                AS topic_title,
           stu.name                AS student_name,
           stu.student_number      AS student_number,
           sup.name                AS supervisor_name
      FROM committee_invitations ci
      JOIN theses t      ON t.id = ci.thesis_id
      JOIN topics tp     ON tp.id = t.topic_id
      JOIN users  stu    ON stu.id = t.student_id
      JOIN users  sup    ON sup.id = t.supervisor_id
     WHERE ci.person_id = ?
     ORDER BY ci.invited_at DESC
  ");
  $q->execute([$personId]);
  $items = $q->fetchAll(PDO::FETCH_ASSOC);

  $OK(['items' => $items]);

} catch (Throwable $e) {
  bad($e->getMessage(), 500);
}
