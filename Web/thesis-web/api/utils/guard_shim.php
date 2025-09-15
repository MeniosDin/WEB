<?php
// api/utils/guard_shim.php
if (!function_exists('ensure_logged_in')) {
  function ensure_logged_in() { /* dev shim: no-op */ }
}
if (!function_exists('assert_student_owns_thesis')) {
  // dev shim: no-op. Βάλε εδώ τον πραγματικό έλεγχο ιδιοκτησίας όταν συνδέσεις το auth_guard.
  function assert_student_owns_thesis($thesis_id) { return true; }
}
