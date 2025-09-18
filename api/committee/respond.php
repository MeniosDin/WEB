<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php'; // session, db(), ok(), bad(), require_role()
header('Content-Type: application/json; charset=utf-8');

/*
 * ΣΗΜΕΙΩΣΗ:
 * Μην υπάρχει trigger που ενημερώνει το ίδιο table (committee_invitations)
 * κατά την αποδοχή, γιατί θα προκαλέσει σφάλμα 1442. Ο έλεγχος/προαγωγή γίνεται εδώ.
 */

$user = require_role('teacher');                 // μόνο καθηγητές
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  bad('Method not allowed', 405);
}

$pdo    = db();
$invId  = trim((string)($_POST['invitation_id'] ?? ''));
$action = trim((string)($_POST['action'] ?? '')); // 'accept' | 'decline'

if ($invId === '' || !in_array($action, ['accept','decline'], true)) {
  bad('Bad request', 400);
}

try {
  $pdo->beginTransaction();

  // Βρες το person_id του καθηγητή (κλείδωμα για ασφάλεια)
  $q = $pdo->prepare('SELECT id FROM persons WHERE user_id = ? LIMIT 1 FOR UPDATE');
  $q->execute([$user['id']]);
  $personId = $q->fetchColumn();
  if (!$personId) {
    $pdo->rollBack();
    bad('Teacher has no person mapping', 403);
  }

  // Φέρε και κλείδωσε την πρόσκληση που αφορά τον καθηγητή
  // Προσοχή: COALESCE(status,'pending') => NULL αντιμετωπίζεται ως pending
  $q = $pdo->prepare("
    SELECT id,
           thesis_id,
           person_id,
           COALESCE(status,'pending') AS status
      FROM committee_invitations
     WHERE id = ? AND person_id = ? FOR UPDATE
  ");
  $q->execute([$invId, $personId]);
  $inv = $q->fetch(PDO::FETCH_ASSOC);
  if (!$inv) {
    $pdo->rollBack();
    bad('Invitation not found', 404);
  }

  // Idempotent: αν έχει ήδη απαντηθεί (accepted/declined/canceled), μην ξαναγράψεις
  if ($inv['status'] !== 'pending') {
    $pdo->commit();
    ok(['already' => true, 'status' => $inv['status']]);
  }

  // Ενημέρωση πρόσκλησης (accept/decline) — πιάνει και παλιές NULL μέσω WHERE
  $newStatus = ($action === 'accept') ? 'accepted' : 'declined';
  $q = $pdo->prepare("
    UPDATE committee_invitations
       SET status = ?, responded_at = NOW()
     WHERE id = ? AND (status = 'pending' OR status IS NULL)
  ");
  $q->execute([$newStatus, $invId]);

  // Αν είναι απόρριψη, κλείσε εδώ
  if ($newStatus === 'declined') {
    $pdo->commit();
    ok(['status' => 'declined']);
  }

  // === ACCEPT ===
  $thesisId = $inv['thesis_id'];

  // Βάλε το μέλος στην επιτροπή (να μην διπλογραφεί)
  $q = $pdo->prepare("
    INSERT IGNORE INTO committee_members (id, thesis_id, person_id, role_in_committee, added_at)
    VALUES (UUID(), ?, ?, 'member', NOW())
  ");
  $q->execute([$thesisId, $personId]);

  // Έχει επιβλέποντα το thesis;
  $q = $pdo->prepare("SELECT 1 FROM theses WHERE id = ? AND supervisor_id IS NOT NULL LIMIT 1");
  $q->execute([$thesisId]);
  $hasSupervisor = (bool)$q->fetchColumn();

  // Πόσες αποδοχές μελών έχουμε στο thesis;
  $q = $pdo->prepare("
    SELECT COUNT(*)
      FROM committee_invitations
     WHERE thesis_id = ? AND status = 'accepted'
  ");
  $q->execute([$thesisId]);
  $acceptedMembers = (int)$q->fetchColumn();

  $promoted = false;
  $canceled = 0;

  // Αν υπάρχει επιβλέπων και έχουν γίνει ≥2 αποδοχές
  if ($hasSupervisor && $acceptedMembers >= 2) {
    // Κάνε ACTIVE (αν είναι ακόμη UNDER_ASSIGNMENT) και συμπλήρωσε assigned_at
    $q = $pdo->prepare("
      UPDATE theses
         SET status = 'active',
             assigned_at = COALESCE(assigned_at, NOW())
       WHERE id = ? AND status = 'under_assignment'
    ");
    $q->execute([$thesisId]);
    $promoted = $q->rowCount() > 0;

    // Ακύρωσε ΟΛΑ τα υπόλοιπα που είναι pending ή NULL
    $q = $pdo->prepare("
      UPDATE committee_invitations
         SET status = 'canceled',
             responded_at = COALESCE(responded_at, NOW())
       WHERE thesis_id = ?
         AND (status = 'pending' OR status IS NULL)
    ");
    $q->execute([$thesisId]);
    $canceled = $q->rowCount();
  }

  $pdo->commit();
  ok([
    'status'             => 'accepted',
    'promoted_to_active' => $promoted,
    'canceled_pending'   => $canceled
  ]);

} catch (Throwable $e) {
  if ($pdo?->inTransaction()) {
    $pdo->rollBack();
  }
  bad('SQL error: ' . $e->getMessage(), 500);
}
