<?php
function require_login(){ if (!isset($_SESSION['uid'])) need_auth(); }
function require_role($role){ require_login(); if (($_SESSION['role'] ?? null) !== $role) forbid(); }
function current_user(){ return ['id'=>$_SESSION['uid'] ?? null, 'role'=>$_SESSION['role'] ?? null]; }