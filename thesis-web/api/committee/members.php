<?php
// api/committee/members.php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php'; // session, db(), ok(), bad(), require_login()
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $me  = require_login();

  $thesisId = trim((string)($_GET['thesis_id'] ?? ''));
  if ($thesisId === '') bad('Bad request', 400);

  // ---- Access check (student owns thesis, or teacher is supervisor / in committee, or secretariat)
  $can = false;

  if ($me['role'] === 'student') {
    $st = $pdo->prepare('SELECT 1 FROM theses WHERE id = ? AND student_id = ? LIMIT 1');
    $st->execute([$thesisId, $me['id']]);
    $can = (bool)$st->fetchColumn();
  } elseif ($me['role'] === 'teacher' || $me['role'] === 'secretariat') {
    // supervisor?
    $st = $pdo->prepare('SELECT 1 FROM theses WHERE id = ? AND supervisor_id = ? LIMIT 1');
    $st->execute([$thesisId, $me['id']]);
    $can = (bool)$st->fetchColumn();

    // committee member by persons.user_id?
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

  // ---- Supervisor (from theses.supervisor_id)
  $qSup = $pdo->prepare('
    SELECT
      "supervisor" AS role_in_committee,
      u.id  AS user_id,
      u.email,
      p.id  AS person_id,
      p.first_name,
      p.last_name
    FROM theses t
    LEFT JOIN users   u ON u.id = t.supervisor_id
    LEFT JOIN persons p ON p.user_id = u.id
    WHERE t.id = ?
    LIMIT 1
  ');
  $qSup->execute([$thesisId]);
  $super = $qSup->fetch(PDO::FETCH_ASSOC);

  // ---- Members (exclude any rows marked as supervisor to avoid duplicates)
  $qMem = $pdo->prepare('
    SELECT
      cm.role_in_committee,
      cm.added_at,
      u.id  AS user_id,
      u.email,
      p.id  AS person_id,
      p.first_name,
      p.last_name
    FROM committee_members cm
    JOIN persons p ON p.id = cm.person_id
    LEFT JOIN users   u ON u.id = p.user_id
    WHERE cm.thesis_id = ?
      AND cm.role_in_committee <> "supervisor"
    ORDER BY
      FIELD(cm.role_in_committee, "supervisor","member","external","examiner","other"),
      p.last_name, p.first_name
  ');
  $qMem->execute([$thesisId]);
  $members = $qMem->fetchAll(PDO::FETCH_ASSOC);

  // Build final list (supervisor first if exists) + members
  $items = [];
  if ($super && !empty($super['user_id'])) {
    $items[] = $super;
  }
  $items = array_merge($items, $members);

  ok(['items' => $items]);
} catch (Throwable $e) {
  bad($e->getMessage(), 500);
}
