<?php
require_once __DIR__ . '/../bootstrap.php';
$u = _require_login();

$q    = trim((string)($_GET['q'] ?? ''));
$role = ($_GET['role'] ?? 'teacher') === 'teacher' ? 'teacher' : 'teacher';

if ($q !== '') {
  $st = $pdo->prepare("SELECT id, name, email FROM users WHERE role='teacher' AND (name LIKE :q OR email LIKE :q) ORDER BY name LIMIT 50");
  $st->execute([':q'=>'%'.$q.'%']);
} else {
  $st = $pdo->query("SELECT id, name, email FROM users WHERE role='teacher' ORDER BY name LIMIT 50");
}
echo json_encode(['ok'=>true,'items'=>$st->fetchAll()]);
