<?php
require 'admin/dbcon.php';

if (!$con) {
    die("Connection failed.");
}

$sql_file = __DIR__ . '/database/features_v4_assessment.sql';
$sql = file_get_contents($sql_file);

if ($sql === false) {
    die("Could not read SQL file.");
}

if (mysqli_multi_query($con, $sql)) {
    do {
        if ($result = mysqli_store_result($con)) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($con) && mysqli_next_result($con));
    echo "Migration completed successfully.";
} else {
    echo "Error executing migration: " . mysqli_error($con);
}
?>
