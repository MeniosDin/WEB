<?php
require_once __DIR__ . '/../bootstrap.php';

/* Fallback αν για κάποιο λόγο δεν φορτώθηκε ο guard από το bootstrap */
if (!function_exists('require_login')) {
  function require_login(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['uid']) || empty($_SESSION['role'])) {
      http_response_code(401);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false,'error'=>'Unauthorized'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    return ['id'=>$_SESSION['uid'], 'role'=>$_SESSION['role']];
  }
}

$user = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);

// φοιτητής βλέπει μόνο τη δική του thesis
if ($user['role'] === 'student') {
  $own = $pdo->prepare("SELECT 1 FROM theses WHERE id=? AND student_id=?");
  $own->execute([$thesis_id, $user['id']]);
  if (!$own->fetchColumn()) bad('Forbidden', 403);
}

$stmt = $pdo->prepare("
  SELECT id, thesis_id, person_id, status, invited_at, responded_at
  FROM committee_invitations
  WHERE thesis_id=?
  ORDER BY invited_at DESC
");
$stmt->execute([$thesis_id]);

ok(['items' => $stmt->fetchAll()]);
