<?php
require_once __DIR__ . '/../includes/bootstrap.php';

echo "Running Company Verification Migrations (features_v12_company_verification.sql)...\n";

$sql = file_get_contents(__DIR__ . '/features_v12_company_verification.sql');

if (!$sql) {
    die("Failed to read features_v12_company_verification.sql\n");
}

if (mysqli_multi_query($con, $sql)) {
    do {
        if ($result = mysqli_store_result($con)) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($con) && mysqli_next_result($con));
    
    if (mysqli_error($con)) {
        echo "Error during migration: " . mysqli_error($con) . "\n";
        exit(1);
    } else {
        echo "Company verification migration completed successfully!\n";
        exit(0);
    }
} else {
    echo "Error starting migration: " . mysqli_error($con) . "\n";
    exit(1);
}
