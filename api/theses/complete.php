<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['thesis_id','nimeritis_url','nimeritis_deposit_date','approval_gs_number','approval_gs_year']);
$s = db()->prepare("UPDATE theses
SET nimeritis_url=?, nimeritis_deposit_date=?, approval_gs_number=?, approval_gs_year=?, status='completed'
WHERE id=? AND supervisor_id=? AND status='under_review'");
$s->execute([$in['nimeritis_url'], $in['nimeritis_deposit_date'], $in['approval_gs_number'], (int)$in['approval_gs_year'], $in['thesis_id'], $_SESSION['uid']]);
if(!$s->rowCount()) bad('Αποτυχία ολοκλήρωσης (triggers/προϋποθέσεις).', 400);
ok();