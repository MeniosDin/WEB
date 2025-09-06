<?php
function require_login(): array {
if (!isset($_SESSION['user'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'UNAUTHENTICATED']); exit; }
return $_SESSION['user'];
}
function require_role(string ...$roles): array {
$u = require_login();
if (!in_array($u['role'], $roles, true)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'FORBIDDEN']); exit; }
return $u;
}
?>