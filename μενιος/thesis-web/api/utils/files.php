<?php
function safe_path(string $p): string { return preg_replace('#[^A-Za-z0-9_./-]#','_', $p); }
?>