<?php
require_once __DIR__.'/../bootstrap.php'; require_role('secretariat');
$term = '%'.($_GET['q'] ?? '').'%';
$s = db()->prepare("SELECT id, role, student_number, name, email FROM users
WHERE name LIKE ? OR email LIKE ? OR student_number LIKE ? LIMIT 200");
$s->execute([$term,$term,$term]); ok(['items'=>$s->fetchAll()]);