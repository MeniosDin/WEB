<?php
function ok($data = [], int $code = 200): void { http_response_code($code); echo json_encode(['ok'=>true,'data'=>$data]); }
function fail(string $msg, int $code = 400, $extra = null): void { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg,'extra'=>$extra]); }
function read_json(): array { $raw = file_get_contents('php://input'); return $raw ? (json_decode($raw, true) ?? []) : []; }
?>