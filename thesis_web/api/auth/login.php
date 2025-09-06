<?php
require_once __DIR__ . '/../bootstrap.php';
$in = read_json();
$email = $in['email'] ?? '';
$pass = $in['password'] ?? '';
if (!$email || !$pass) fail('Email/Password required', 400);


$pdo = db();
$stmt = $pdo->prepare('SELECT id, role, student_number, name, email, password_hash FROM users WHERE email=?');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user) fail('Invalid credentials', 401);
if (!password_verify($pass, $user['password_hash'])) fail('Invalid credentials', 401);
$_SESSION['user'] = $user;
ok(['user'=>$user]);
?>