<?php
require_once __DIR__ . '/_bootstrap.php';

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
  $stm = $pdo->prepare("
    SELECT tp.id, tp.title, tp.summary, tp.academic_year, u.name AS supervisor_name, u.id AS supervisor_id
    FROM topics tp
    JOIN users u ON u.id = tp.supervisor_id
    WHERE tp.title LIKE :q OR tp.summary LIKE :q
    ORDER BY tp.created_at DESC
    LIMIT 100
  ");
  $stm->execute([':q' => '%'.$q.'%']);
} else {
  $stm = $pdo->query("
    SELECT tp.id, tp.title, tp.summary, tp.academic_year, u.name AS supervisor_name, u.id AS supervisor_id
    FROM topics tp
    JOIN users u ON u.id = tp.supervisor_id
    ORDER BY tp.created_at DESC
    LIMIT 100
  ");
}
$rows = $stm->fetchAll();
json_ok(['items'=>$rows]);
