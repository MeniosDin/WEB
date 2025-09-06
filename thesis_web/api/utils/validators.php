<?php
function must(string $key, array $src): string { if (!isset($src[$key]) || $src[$key]==='') throw new Exception("Missing: $key"); return trim((string)$src[$key]); }
function email_valid(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }
?>