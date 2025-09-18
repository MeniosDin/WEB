<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_role('teacher');
$pdo = db();

$tid = $_GET['thesis_id'] ?? '';
if (!$tid) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Λείπει thesis_id']); exit; }

$st = $pdo->prepare("SELECT id, thesis_id, author_id, text, created_at
                     FROM thesis_notes
                     WHERE thesis_id = :tid AND author_id = :uid
                     ORDER BY created_at DESC");
$st->execute([':tid'=>$tid, ':uid'=>$me['id']]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'items'=>$rows], JSON_UNESCAPED_UNICODE);
