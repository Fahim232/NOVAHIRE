<?php
/**
 * NovaHire — Run Migration v6: Interview Scheduling & Interviewer Assignment
 * 
 * Applies database/features_v6_interview_scheduling.sql to extend
 * the `interviews` table with Phase 2 columns and indexes.
 *
 * Usage:  php database/run_migration_v6.php
 *    or   visit http://localhost/Job_portal_and_grooming_SE-main/database/run_migration_v6.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Connect using same multi-port fallback as admin/dbcon.php
mysqli_report(MYSQLI_REPORT_OFF);
$user = 'root';
$pass = '';
$db   = 'projects';

$con = @new mysqli('127.0.0.1', $user, $pass, $db, 3307);
if ($con->connect_error) {
    $con = @new mysqli('127.0.0.1', $user, $pass, $db, 3306);
}
if ($con->connect_error) {
    $con = @new mysqli('localhost', $user, $pass, $db, 3307);
}
if ($con->connect_error) {
    $con = @new mysqli('localhost', $user, $pass, $db, 3306);
}
if ($con->connect_error) {
    die("Connection failed on all ports: " . $con->connect_error);
}
$con->set_charset('utf8mb4');

echo "<h2>NovaHire — Migration v6: Interview Scheduling</h2>\n";
echo "<pre>\n";

// Verify the interviews table exists
$check = $con->query("SHOW TABLES LIKE 'interviews'");
if ($check->num_rows === 0) {
    echo "ERROR: 'interviews' table does not exist. Run the application first to auto-create it.\n";
    exit;
}

// Verify company_staff table exists (Phase 1 prerequisite)
$check2 = $con->query("SHOW TABLES LIKE 'company_staff'");
if ($check2->num_rows === 0) {
    echo "ERROR: 'company_staff' table does not exist. Run migration v5 first.\n";
    exit;
}

echo "✓ Prerequisites verified (interviews + company_staff tables exist)\n\n";

// Read and execute the SQL migration file
$sql_file = __DIR__ . '/features_v6_interview_scheduling.sql';
if (!file_exists($sql_file)) {
    echo "ERROR: Migration file not found: $sql_file\n";
    exit;
}

$sql = file_get_contents($sql_file);

// Execute multi-statement SQL
$con->multi_query($sql);

$stmt_count = 0;
$errors = [];

do {
    $stmt_count++;
    if ($result = $con->store_result()) {
        $result->free();
    }
    if ($con->errno) {
        $errors[] = "Statement $stmt_count: " . $con->error;
    }
} while ($con->more_results() && $con->next_result());

if (!empty($errors)) {
    echo "⚠ Completed with errors:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
} else {
    echo "✓ All migration statements executed successfully\n";
}

// Verify the new columns exist
echo "\n--- Verification ---\n";
$expected_cols = ['interviewer_id', 'round_number', 'title', 'duration_minutes', 'end_time', 'updated_at'];
$cols_result = $con->query("SHOW COLUMNS FROM `interviews`");
$existing_cols = [];
while ($row = $cols_result->fetch_assoc()) {
    $existing_cols[] = $row['Field'];
}

$all_ok = true;
foreach ($expected_cols as $col) {
    if (in_array($col, $existing_cols)) {
        echo "  ✓ Column '$col' exists\n";
    } else {
        echo "  ✗ Column '$col' MISSING\n";
        $all_ok = false;
    }
}

// Verify indexes
$idx_result = $con->query("SHOW INDEX FROM `interviews`");
$existing_idx = [];
while ($row = $idx_result->fetch_assoc()) {
    $existing_idx[$row['Key_name']] = true;
}

$expected_idx = ['idx_interviews_interviewer', 'idx_interviews_date_time'];
foreach ($expected_idx as $idx) {
    if (isset($existing_idx[$idx])) {
        echo "  ✓ Index '$idx' exists\n";
    } else {
        echo "  ✗ Index '$idx' MISSING\n";
        $all_ok = false;
    }
}

// Verify FK
$fk_result = $con->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND CONSTRAINT_NAME = 'fk_interviews_interviewer'");
if ($fk_result && $fk_result->num_rows > 0) {
    echo "  ✓ Foreign key 'fk_interviews_interviewer' exists\n";
} else {
    echo "  ✗ Foreign key 'fk_interviews_interviewer' MISSING\n";
    $all_ok = false;
}

echo "\n" . ($all_ok ? "═══ Migration v6 PASSED ═══" : "═══ Migration v6 INCOMPLETE — check errors above ═══") . "\n";
echo "</pre>\n";

$con->close();
?>
