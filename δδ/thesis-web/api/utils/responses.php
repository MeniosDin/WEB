<?php
function ok($payload=[]){ echo json_encode(['ok'=>true]+$payload, JSON_UNESCAPED_UNICODE); exit; }
function bad($msg='Σφάλμα', $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function need_auth(){ bad('Απαιτείται σύνδεση', 401); }
function forbid(){ bad('Απαγορεύεται', 403); }
function body_json(){ return json_decode(file_get_contents('php://input'), true) ?: []; }