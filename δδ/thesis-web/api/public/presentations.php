<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$rows = $pdo->query("SELECT * FROM vw_public_presentations ORDER BY when_dt DESC")->fetchAll();
echo json_encode(['success'=>true,'items'=>$rows], JSON_UNESCAPED_UNICODE);
