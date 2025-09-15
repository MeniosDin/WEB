<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $me  = require_login(); // ['id'=>..., 'role'=> 'student'|'teacher'|'secretariat']

  // helper για ομοιόμορφη επιτυχία
  $OK = function (array $data = []) { ok(['data' => $data]); };

  /* ==========================================================
   * 1) Thesis-mode: ?thesis_id=...
   * ========================================================== */
  $thesisId = isset($_GET['thesis_id']) ? trim($_GET['thesis_id']) : '';
  if ($thesisId !== '') {
    // Έλεγχος πρόσβασης
    $isAllowed = false;

    if ($me['role'] === 'student') {
      // ο φοιτητής της διπλωματικής
      $st = $pdo->prepare('SELECT 1 FROM theses WHERE id = ? AND student_id = ? LIMIT 1');
      $st->execute([$thesisId, $me['id']]);
      $isAllowed = (bool) $st->fetchColumn();

    } elseif ($me['role'] === 'teacher') {
      // επιβλέπων
      $st = $pdo->prepare('SELECT 1 FROM theses WHERE id = ? AND supervisor_id = ? LIMIT 1');
      $st->execute([$thesisId, $me['id']]);
      $isAllowed = (bool) $st->fetchColumn();

      // ή μέλος τριμελούς (μέσω persons.user_id)
      if (!$isAllowed) {
        $st2 = $pdo->prepare("
          SELECT 1
            FROM committee_members cm
            JOIN persons p ON p.id = cm.person_id
           WHERE cm.thesis_id = ? AND p.user_id = ?
           LIMIT 1
        ");
        $st2->execute([$thesisId, $me['id']]);
        $isAllowed = (bool) $st2->fetchColumn();
      }

    } elseif ($me['role'] === 'secretariat') {
      // η γραμματεία έχει ορατότητα σε όλα
      $isAllowed = true;
    }

    if (!$isAllowed) { bad('Forbidden', 403); }

    // Header μεταδεδομένα thesis (για φοιτητή)
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

    // Λίστα προσκλήσεων του thesis
    $q = $pdo->prepare("
      SELECT
          ci.id,
          ci.thesis_id,
          ci.person_id,
          ci.status           AS inv_status,   -- alias ώστε το UI να ξεχωρίζει από status διπλωματικής
          ci.status,                          -- προαιρετικά και ως 'status' για συμβατότητα
          ci.invited_at,
          ci.responded_at,
          CONCAT_WS(' ', p.first_name, p.last_name) AS member_name
        FROM committee_invitations ci
        JOIN persons p ON p.id = ci.person_id
       WHERE ci.thesis_id = ?
       ORDER BY ci.invited_at DESC, ci.id DESC
    ");
    $q->execute([$thesisId]);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);

    $OK(['thesis' => $thesis, 'items' => $items]);
  }

  /* ==========================================================
   * 2) Teacher-mode: χωρίς thesis_id -> προσκλήσεις καθηγητή
   * ========================================================== */
  require_role('teacher', 'secretariat');

  // users -> persons
  $p = $pdo->prepare('SELECT id FROM persons WHERE user_id = ? LIMIT 1');
  $p->execute([$me['id']]);
  $personId = $p->fetchColumn();

  if (!$personId) {
    // χρήστης χωρίς καταχώριση στο persons
    $OK(['items' => []]);
  }

  $q = $pdo->prepare("
    SELECT
        ci.id,
        ci.status           AS inv_status,
        ci.status,                           -- και ως 'status' αν το UI το περιμένει
        ci.invited_at,
        ci.responded_at,
        t.id                AS thesis_id,
        tp.title            AS topic_title,
        stu.name            AS student_name,
        stu.student_number  AS student_number,
        sup.name            AS supervisor_name
      FROM committee_invitations ci
      JOIN theses t   ON t.id = ci.thesis_id
      JOIN topics tp  ON tp.id = t.topic_id
      JOIN users  stu ON stu.id = t.student_id
      JOIN users  sup ON sup.id = t.supervisor_id
     WHERE ci.person_id = ?
     ORDER BY ci.invited_at DESC, ci.id DESC
  ");
  $q->execute([$personId]);
  $items = $q->fetchAll(PDO::FETCH_ASSOC);

  $OK(['items' => $items]);

} catch (Throwable $e) {
  bad($e->getMessage(), 500);
}
