<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$uid = $_SESSION['uid'];
$q = db()->prepare("SELECT t.id, t.title, t.summary, t.pdf_path, u.name AS supervisor
FROM topics t JOIN users u ON u.id=t.supervisor_id
WHERE t.supervisor_id=? ORDER BY t.created_at DESC");
$q->execute([$uid]); ok(['items'=>$q->fetchAll()]);