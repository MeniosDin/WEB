<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('teacher');
$in = read_json();
$title = must('title', $in);
$summary = $in['summary'] ?? null;
$pdf_path = $in['pdf_path'] ?? null;
$academic_year = $in['academic_year'] ?? null;


$pdo = db();
$stmt = $pdo->prepare('INSERT INTO topics(id, supervisor_id, title, summary, pdf_path, academic_year) VALUES (UUID(),?,?,?,?,?)');
$stmt->execute([$u['id'], $title, $summary, $pdf_path, $academic_year]);
ok(['id'=>$pdo->lastInsertId()]);
?>