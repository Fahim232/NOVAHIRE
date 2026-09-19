<?php
require 'admin/dbcon.php';
$res = mysqli_query($con, "SELECT id AS job_id, job_title, company_id FROM company_jobs WHERE status = 'active' ORDER BY id DESC LIMIT 50");
if (!$res) echo "ERROR: " . mysqli_error($con) . "\n";
else echo "SUCCESS\n";
