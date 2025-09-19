<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();
$u   = require_login();

$thesis_id = $_POST['thesis_id'] ?? '';
if (!$thesis_id) bad('Λείπει thesis_id.');

$stmt = $pdo->prepare("SELECT id FROM persons WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $u['id']]);
$me = $stmt->fetch();
if (!$me) bad('Δεν βρέθηκε πρόσωπο για τον χρήστη.', 403);
$person_id = $me['id'];

// Πρέπει να είσαι επιβλέπων
$tx = $pdo->prepare("SELECT status, grading_enabled_at, supervisor_id FROM theses WHERE id = :id LIMIT 1");
$tx->execute([':id'=>$thesis_id]);
$th = $tx->fetch();
if (!$th) bad('Δεν βρέθηκε διπλωματική.', 404);
if ($th['supervisor_id'] !== $person_id) bad('Μόνο ο επιβλέπων μπορεί να ενεργοποιήσει τη βαθμολόγηση.', 403);
if ($th['status'] !== 'under_review') bad('Επιτρέπεται μόνο σε «Υπό εξέταση».', 409);

// Αν ήδη ενεργοποιημένο → ok
if (!empty($th['grading_enabled_at'])) ok(['already'=>true,'grading_enabled_at'=>$th['grading_enabled_at']]);

$u2 = $pdo->prepare("UPDATE theses
                     SET grading_enabled_at = NOW()
                     WHERE id = :id AND status = 'under_review' AND grading_enabled_at IS NULL");
$u2->execute([':id'=>$thesis_id]);

ok(['message'=>'Η βαθμολόγηση ενεργοποιήθηκε.']);
