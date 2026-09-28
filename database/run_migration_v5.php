<?php
/**
 * Migration runner for features_v5_company_staff.sql
 * Run: php database/run_migration_v5.php
 */
require_once __DIR__ . '/../admin/dbcon.php';

if (!$con) {
    echo "ERROR: Database connection failed.\n";
    exit(1);
}

$sql_file = __DIR__ . '/features_v5_company_staff.sql';
$sql = file_get_contents($sql_file);
if ($sql === false) {
    echo "ERROR: Could not read $sql_file\n";
    exit(1);
}

// Remove USE statement if present
$sql = preg_replace('/^USE\s+\w+;\s*$/mi', '', $sql);

if (mysqli_multi_query($con, $sql)) {
    $i = 0;
    do {
        $i++;
        if ($result = mysqli_store_result($con)) {
            mysqli_free_result($result);
        }
        if (mysqli_errno($con)) {
            echo "WARNING on statement #$i: " . mysqli_error($con) . "\n";
        }
    } while (mysqli_next_result($con));

    if (mysqli_errno($con)) {
        echo "ERROR after processing: " . mysqli_error($con) . "\n";
    } else {
        echo "SUCCESS: Migration features_v5_company_staff.sql executed successfully.\n";
    }
} else {
    echo "ERROR: " . mysqli_error($con) . "\n";
    exit(1);
}

// Verify company_staff table
$check = mysqli_query($con, "SHOW TABLES LIKE 'company_staff'");
if ($check && mysqli_num_rows($check) > 0) {
    echo "  ✓ Table 'company_staff' exists\n";
    $cols = mysqli_query($con, "DESCRIBE company_staff");
    while ($c = mysqli_fetch_assoc($cols)) {
        echo "    - {$c['Field']} ({$c['Type']})\n";
    }
} else {
    echo "  ✗ Table 'company_staff' NOT found\n";
    exit(1);
}

echo "\nMigration v5 completed.\n";
