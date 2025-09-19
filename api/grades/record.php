<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();
$u   = require_login();

$stmt = $pdo->prepare("SELECT id FROM persons WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $u['id']]);
$me = $stmt->fetch();
if (!$me) bad('Δεν βρέθηκε πρόσωπο για τον χρήστη.', 403);
$person_id = $me['id'];

$thesis_id = $_GET['thesis_id'] ?? '';
if (!$thesis_id) bad('Λείπει thesis_id.');

$q = $pdo->prepare("SELECT total, criteria_scores_json AS criteria, created_at
                    FROM grades
                    WHERE thesis_id = :tid AND person_id = :pid
                    LIMIT 1");
$q->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
$row = $q->fetch();

ok(['grade' => $row ?: null]);
