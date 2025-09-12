<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

function j_ok($d=[], $c=200){ http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE); exit; }
function j_err($m,$c=400){ http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

require_role('teacher'); // μόνο διδάσκοντες

$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') j_ok([]);

$sql = "
  SELECT id, name, student_number, email
  FROM users
  WHERE role='student'
    AND (student_number LIKE :q OR name LIKE :q OR email LIKE :q)
  ORDER BY name ASC
  LIMIT 10
";
$st = $pdo->prepare($sql);
$st->execute([':q'=>"%{$q}%"]);
j_ok($st->fetchAll());
