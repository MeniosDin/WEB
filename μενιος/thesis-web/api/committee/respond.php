<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $me  = require_role('teacher'); // μόνο καθηγητές
} catch (Throwable $e) {
  bad('Unauthorized', 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  bad('Method not allowed', 405);
}

$invId  = trim($_POST['invitation_id'] ?? '');
$action = $_POST['action'] ?? '';

if ($invId === '' || !in_array($action, ['accept','decline'], true)) {
  bad('Missing or invalid parameters', 400);
}

try {
  $pdo->beginTransaction();

  // Βρες την πρόσκληση του ΣΥΓΚΕΚΡΙΜΕΝΟΥ καθηγητή (μέσω persons.user_id)
  $q = $pdo->prepare("
    SELECT ci.*
    FROM committee_invitations ci
    JOIN persons p ON p.id = ci.person_id
    WHERE ci.id = :id AND p.user_id = :uid
    FOR UPDATE
  ");
  $q->execute([':id'=>$invId, ':uid'=>$me['id']]);
  $inv = $q->fetch(PDO::FETCH_ASSOC);

  if (!$inv) {
    $pdo->rollBack();
    bad('Invitation not found', 404);
  }

  // Αν δεν είναι pending, θεωρούμε την κλήση ιδεμποτέντη -> 200 OK
  if ($inv['status'] !== 'pending') {
    $pdo->commit();
    ok([
      'message' => 'Already responded',
      'code'    => 'already_responded',
      'status'  => $inv['status'],
    ]);
  }

  if ($action === 'accept') {
    $u = $pdo->prepare("
      UPDATE committee_invitations
         SET status='accepted',
             accepted_at=NOW(),
             responded_at=NOW()
       WHERE id=:id
    ");
  } else {
    $u = $pdo->prepare("
      UPDATE committee_invitations
         SET status='declined',
             rejected_at=NOW(),
             responded_at=NOW()
       WHERE id=:id
    ");
  }
  $u->execute([':id'=>$invId]);

  $pdo->commit();
  ok(['message' => $action === 'accept' ? 'Accepted' : 'Declined']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  bad('Server error', 500);
}
