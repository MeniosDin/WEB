<?php
require_once __DIR__.'/../bootstrap.php'; require_role('secretariat');
$in = body_json(); $rows = $in['users'] ?? [];
$db = db(); $db->beginTransaction();
try{
$stmt = $db->prepare("INSERT INTO users(id,role,student_number,name,email,password_hash)
VALUES(UUID(),?,?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role), student_number=VALUES(student_number), name=VALUES(name)");
foreach($rows as $u){
must($u,['role','name','email','password']);
$stmt->execute([$u['role'],$u['student_number']??null,$u['name'],$u['email'],password_hash($u['password'],PASSWORD_DEFAULT)]);
}
$db->commit(); ok(['count'=>count($rows)]);
}catch(Throwable $e){ $db->rollBack(); bad('Αποτυχία εισαγωγής: '.$e->getMessage()); }