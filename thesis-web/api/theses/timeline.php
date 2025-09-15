<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $me  = require_login();

  $thesisId = trim((string)($_GET['thesis_id'] ?? ''));
  if ($thesisId === '') bad('Bad request', 400);

  // Access check (όπως στα υπόλοιπα endpoints)
  $can = false;
  if ($me['role'] === 'student') {
    $st = $pdo->prepare('SELECT 1 FROM theses WHERE id = ? AND student_id = ? LIMIT 1');
    $st->execute([$thesisId, $me['id']]);
    $can = (bool)$st->fetchColumn();
  } elseif ($me['role'] === 'teacher' || $me['role'] === 'secretariat') {
    $st = $pdo->prepare('SELECT 1 FROM theses WHERE id = ? AND supervisor_id = ? LIMIT 1');
    $st->execute([$thesisId, $me['id']]);
    $can = (bool)$st->fetchColumn();
    if (!$can) {
      $st = $pdo->prepare('
        SELECT 1
          FROM committee_members cm
          JOIN persons p ON p.id = cm.person_id
         WHERE cm.thesis_id = ? AND p.user_id = ?
         LIMIT 1
      ');
      $st->execute([$thesisId, $me['id']]);
      $can = (bool)$st->fetchColumn();
    }
  }
  if (!$can) bad('Forbidden', 403);

  // ΜΗΝ ζητήσεις "meta" – δεν το χρειάζεται το UI
  $q = $pdo->prepare('
    SELECT event_type, from_status, to_status, created_at
      FROM thesis_timeline
     WHERE thesis_id = ?
     ORDER BY created_at DESC
  ');
  $q->execute([$thesisId]);
  $items = $q->fetchAll(PDO::FETCH_ASSOC);

  ok(['items' => $items]);
} catch (Throwable $e) {
  bad($e->getMessage(), 500);
}
