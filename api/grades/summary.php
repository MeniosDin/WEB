<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $me  = require_login();

  $thesisId = trim((string)($_GET['thesis_id'] ?? ''));
  if ($thesisId === '') bad('Bad request', 400);

  // access control ίδιο όπως πάνω (συνάρτηση αν θέλεις)
  $can = false;
  if ($me['role'] === 'student') {
    $st = $pdo->prepare('SELECT 1 FROM theses WHERE id=? AND student_id=? LIMIT 1');
    $st->execute([$thesisId, $me['id']]);
    $can = (bool)$st->fetchColumn();
  } else if ($me['role'] === 'teacher' || $me['role'] === 'secretariat') {
    $st = $pdo->prepare('SELECT 1 FROM theses WHERE id=? AND supervisor_id=? LIMIT 1');
    $st->execute([$thesisId, $me['id']]);
    $can = (bool)$st->fetchColumn();
    if (!$can) {
      $st = $pdo->prepare('
        SELECT 1 FROM committee_members cm
        JOIN persons p ON p.id = cm.person_id
        WHERE cm.thesis_id=? AND p.user_id=? LIMIT 1
      ');
      $st->execute([$thesisId, $me['id']]);
      $can = (bool)$st->fetchColumn();
    }
  }
  if (!$can) bad('Forbidden', 403);

  $q = $pdo->prepare('
    SELECT COUNT(*) AS cnt, COALESCE(AVG(total),0) AS avg_total
      FROM grades
     WHERE thesis_id = ?
  ');
  $q->execute([$thesisId]);
  $summary = $q->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0, 'avg_total'=>0];

  ok(['summary' => $summary]);
} catch (Throwable $e) {
  bad($e->getMessage(), 500);
}
