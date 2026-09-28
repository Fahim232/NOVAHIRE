<?php
require_once __DIR__ . '/../includes/bootstrap.php';

echo "Running Phase 2 Migrations (features_v11_phase2.sql)...\n";

$sql = file_get_contents(__DIR__ . '/features_v11_phase2.sql');

if (!$sql) {
    die("Failed to read features_v11_phase2.sql");
}

// Split queries (handle SET and PREPARE safely)
// For complex migrations with prepared statements, it's safer to use a custom runner or just multi_query
if (mysqli_multi_query($con, $sql)) {
    do {
        if ($result = mysqli_store_result($con)) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($con) && mysqli_next_result($con));
    
    if (mysqli_error($con)) {
        echo "Error during migration: " . mysqli_error($con) . "\n";
    } else {
        echo "Migration completed successfully!\n";
    }
} else {
    echo "Error starting migration: " . mysqli_error($con) . "\n";
}
