<?php
require_once __DIR__ . '/../admin/dbcon.php';

echo "Running Phase 3 Migration (features_v7_interview_feedback.sql)...\n";

$sql = file_get_contents(__DIR__ . '/features_v7_interview_feedback.sql');
if (!$sql) {
    die("Failed to read SQL file.\n");
}

if (mysqli_multi_query($con, $sql)) {
    do {
        if ($res = mysqli_store_result($con)) {
            mysqli_free_result($res);
        }
    } while (mysqli_more_results($con) && mysqli_next_result($con));
    echo "Migration completed successfully!\n";
} else {
    echo "Error executing migration: " . mysqli_error($con) . "\n";
}
