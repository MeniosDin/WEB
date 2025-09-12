<?php
// api/users/update_profile.php
require_once __DIR__ . '/../bootstrap.php';

$u = require_role('student'); // ή require_login() αν θέλεις και διδάσκοντες/γραμματεία

$in = $_POST ?: body_json();

$address       = trim((string)($in['address'] ?? ''));
$email         = trim((string)($in['email'] ?? ''));
$phone_mobile  = trim((string)($in['phone_mobile'] ?? ''));
$phone_landline= trim((string)($in['phone_landline'] ?? ''));

// basic validation
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  bad('Μη έγκυρο email', 422);
}
if (mb_strlen($address) > 500) bad('Πολύ μεγάλο address (max 500)', 422);
if (mb_strlen($phone_mobile) > 50 || mb_strlen($phone_landline) > 50) bad('Πολύ μεγάλο τηλέφωνο (max 50)', 422);

// προαιρετικά: απλή μάσκα τηλεφώνου
$phoneRe = '/^[0-9+\-() ]*$/u';
if ($phone_mobile !== '' && !preg_match($phoneRe, $phone_mobile))  bad('Μη έγκυρο κινητό', 422);
if ($phone_landline !== '' && !preg_match($phoneRe, $phone_landline)) bad('Μη έγκυρο σταθερό', 422);

// uniqueness email
$chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
$chk->execute([$email, $u['id']]);
if ($chk->fetch()) bad('Το email χρησιμοποιείται ήδη', 409);

// update
$upd = $pdo->prepare("
  UPDATE users
     SET email=?, address=?, phone_mobile=?, phone_landline=?
   WHERE id=?
  LIMIT 1
");
$upd->execute([$email, $address, $phone_mobile, $phone_landline, $u['id']]);

ok(['message'=>'Το προφίλ ενημερώθηκε επιτυχώς.']);
