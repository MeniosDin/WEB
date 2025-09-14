<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$id = $_GET['id'] ?? '';
if ($id === '') bad('id required', 422);

$st = $pdo->prepare("SELECT id, title, summary, pdf_path FROM topics WHERE id=? LIMIT 1");
$st->execute([$id]);
$item = $st->fetch();
if (!$item) bad('Not found', 404);

ok(['item' => $item]);
