<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_role('teacher');
$pdo = db();

$tid  = $_POST['thesis_id'] ?? '';
$text = trim((string)($_POST['text'] ?? ''));
if (!$tid)  { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Λείπει thesis_id']); exit; }
if ($text === '' || mb_strlen($text) > 300) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Κείμενο 1–300 χαρακτήρες']); exit; }

$st = $pdo->prepare("INSERT INTO thesis_notes(id, thesis_id, author_id, text, created_at)
                     VALUES(UUID(), :tid, :uid, :txt, NOW())");
$st->execute([':tid'=>$tid, ':uid'=>$me['id'], ':txt'=>$text]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
