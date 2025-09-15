<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php'; // session, db(), ok(), bad(), require_role()
header('Content-Type: application/json; charset=utf-8');

$user = require_role('student'); // μόνο φοιτητής/τρια
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  bad('Method not allowed', 405);
}

try {
  // Διάβασε σώμα: υποστήριξη JSON *και* POST form-data
  $raw = file_get_contents('php://input');
  $in  = [];
  if (!empty($raw) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $in = json_decode($raw, true) ?: [];
  } else {
    $in = $_POST;
  }

  $thesisId   = trim((string)($in['thesis_id'] ?? ''));
  $url        = trim((string)($in['nimeritis_url'] ?? $in['url'] ?? ''));
  // δεχόμαστε και τα δύο ονόματα:
  $depositRaw = trim((string)($in['nimeritis_deposit_date'] ?? $in['deposit_date'] ?? ''));

  if ($thesisId === '' || $url === '' || $depositRaw === '') {
    bad('Bad request: thesis_id, nimeritis_url και deposit_date είναι υποχρεωτικά.', 400);
  }

  // Βασική επικύρωση URL
  if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
    bad('Μη έγκυρος σύνδεσμος Νημερτής.', 400);
  }

  // Επικύρωση ημερομηνίας (Y-m-d)
  $d = DateTime::createFromFormat('Y-m-d', $depositRaw);
  $dateOk = $d && $d->format('Y-m-d') === $depositRaw;
  if (!$dateOk) {
    bad('Μη έγκυρη ημερομηνία κατάθεσης (μορφή YYYY-MM-DD).', 400);
  }
  $depositDate = $d->format('Y-m-d');

  $pdo = db();

  // Έλεγχος ότι η ΔΕ ανήκει στον συγκεκριμένο φοιτητή
  $st = $pdo->prepare("SELECT id FROM theses WHERE id = ? AND student_id = ? LIMIT 1");
  $st->execute([$thesisId, $user['id']]);
  if (!$st->fetchColumn()) {
    bad('Forbidden', 403);
  }

  // (προαιρετικό) μπορείς να επιβάλεις ότι πρέπει να υπάρχουν βαθμοί:
  // $hasGrades = (bool)db()->query("SELECT EXISTS(SELECT 1 FROM grades WHERE thesis_id = ".$pdo->quote($thesisId).")")->fetchColumn();
  // if (!$hasGrades) bad('Δεν έχουν καταχωρηθεί ακόμα βαθμοί.', 400);

  // Αποθήκευση
  $st = $pdo->prepare("
    UPDATE theses
       SET nimeritis_url          = :url,
           nimeritis_deposit_date = :dd
     WHERE id = :id
  ");
  $st->execute([
    ':url' => $url,
    ':dd'  => $depositDate,
    ':id'  => $thesisId,
  ]);

  // (προαιρετικά) γράψε και timeline event
  // $tl = $pdo->prepare("INSERT INTO thesis_timeline(id, thesis_id, event_type, created_at) VALUES (UUID(), ?, 'nimeritis_set', NOW())");
  // $tl->execute([$thesisId]);

  ok(['saved' => true]);

} catch (Throwable $e) {
  bad('SQL error: '.$e->getMessage(), 500);
}
