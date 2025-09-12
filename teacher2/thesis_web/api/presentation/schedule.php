<?php
require_once __DIR__ . '/../bootstrap.php';
$user = requireAuth(); requireRole($user, ['student']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
require_fields($in, ['thesis_id','when_dt','mode','room_or_link']);

$chk = $pdo->prepare("SELECT 1 FROM theses WHERE id=:t AND student_id=:s AND status='under_review'");
$chk->execute([':t'=>$in['thesis_id'], ':s'=>$user['id']]);
if (!$chk->fetchColumn()) json_error('Not allowed (status must be under_review)', 403);

$pdo->prepare("INSERT INTO presentation(thesis_id, when_dt, mode, room_or_link)
               VALUES(:t,:w,:m,:r)
               ON DUPLICATE KEY UPDATE when_dt=VALUES(when_dt), mode=VALUES(mode), room_or_link=VALUES(room_or_link)")
    ->execute([':t'=>$in['thesis_id'], ':w'=>$in['when_dt'], ':m'=>$in['mode'], ':r'=>$in['room_or_link']]);

json_ok(['ok'=>true]);
