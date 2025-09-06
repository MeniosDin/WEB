<?php
require_once __DIR__ . '/../../api/db.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$from = $_GET['from'] ?? date('Y-m-d 00:00:00');
$to = $_GET['to'] ?? date('Y-m-d 23:59:59', strtotime('+30 day'));
$sql = 'SELECT when_dt, mode, room_or_link, published_at, thesis_id FROM presentation WHERE when_dt BETWEEN ? AND ? ORDER BY when_dt ASC';
$stmt=$pdo->prepare($sql); $stmt->execute([$from,$to]);
echo json_encode(['items'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
?>