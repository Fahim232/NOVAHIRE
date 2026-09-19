<?php
require 'c:/xampp/htdocs/Job_portal_and_grooming_SE-main/admin/dbcon.php';

function dumpTable($con, $table) {
    echo "--- Table: $table ---\n";
    $r = mysqli_query($con, "DESCRIBE $table");
    while($row = mysqli_fetch_assoc($r)) {
        echo $row['Field'] . " | " . $row['Type'] . "\n";
    }
}

dumpTable($con, 'company_jobs');
dumpTable($con, 'company_job_questions');
dumpTable($con, 'assessment_sessions');
