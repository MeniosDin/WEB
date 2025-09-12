<?php
require_once __DIR__ . '/../bootstrap.php';
$user = requireAuth(); requireRole($user, ['student','teacher']);

function checkAccess(PDO $pdo, string $thesis_id, string $user_id): bool {
  $q = $pdo->prepare("
    SELECT 1 FROM theses t
    LEFT JOIN committee_members cm ON cm.thesis_id=t.id
    WHERE t.id=:t AND (t.student_id=:u OR cm.person_id IN (SELECT id FROM persons WHERE user_id=:u))
    LIMIT 1");
  $q->execute([':t'=>$thesis_id, ':u'=>$user_id]);
  return (bool)$q->fetchColumn();
}

if (($_SERVER['CONTENT_TYPE'] ?? '') && str_starts_with($_SERVER['CONTENT_TYPE'], 'multipart/form-data')) {
  // --- File upload ---
  if (empty($_FILES['file'])) json_error('file required', 422);
  $thesis_id = $_POST['thesis_id'] ?? ''; if (!$thesis_id) json_error('thesis_id required', 422);
  $kind = $_POST['kind'] ?? 'draft';
  if (!checkAccess($pdo, $thesis_id, $user['id'])) json_error('Not allowed', 403);

  $dir = __DIR__ . '/../../uploads/' . $thesis_id;
  if (!is_dir($dir)) mkdir($dir, 0770, true);
  $safe = preg_replace('/[^a-zA-Z0-9._-]+/u', '_', $_FILES['file']['name']);
  $fname = bin2hex(random_bytes(6)) . '_' . $safe;
  $dest = $dir . '/' . $fname;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) json_error('upload failed', 500);

  $rel = 'uploads/'.$thesis_id.'/'.$fname;
  $pdo->prepare("INSERT INTO resources(thesis_id, kind, url_or_path) VALUES(:t,:k,:p)")
      ->execute([':t'=>$thesis_id, ':k'=>$kind, ':p'=>$rel]);
  json_ok(['ok'=>true,'path'=>$rel]);
} else {
  // --- JSON: URL resource ---
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  require_fields($in, ['thesis_id','url']);
  $kind = $in['kind'] ?? 'other';
  if (!checkAccess($pdo, $in['thesis_id'], $user['id'])) json_error('Not allowed', 403);

  $pdo->prepare("INSERT INTO resources(thesis_id, kind, url_or_path) VALUES(:t,:k,:u)")
      ->execute([':t'=>$in['thesis_id'], ':k'=>$kind, ':u'=>$in['url']]);
  json_ok(['ok'=>true]);
}
