<?php
require_once __DIR__ . '/../admin/dbcon.php';

$sql = file_get_contents(__DIR__ . '/features_v10_staff_auth.sql');

if (mysqli_multi_query($con, $sql)) {
    do {
        if ($result = mysqli_store_result($con)) {
            mysqli_free_result($result);
        }
    } while (mysqli_next_result($con));
    echo "Migration v10 completed successfully.\n";
} else {
    echo "Migration failed: " . mysqli_error($con) . "\n";
}
