<?php
/**
 * NovaHire — Database Migration: Selection Workflow Enhancement
 *
 * Adds:
 * 1. candidate_employment_availability table — candidate's response to selection
 * 2. candidate_response_status column in hiring_decisions — tracks accept/decline
 *
 * Run: php database/migrate_selection_workflow.php
 */

require_once __DIR__ . '/../admin/dbcon.php';
global $con;

echo "Starting Selection Workflow Enhancement migration...\n\n";

function column_exists_sw($con, $table, $column) {
    $q = mysqli_query($con, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($q && mysqli_num_rows($q) > 0);
}

function table_exists_sw($con, $table) {
    $q = mysqli_query($con, "SHOW TABLES LIKE '$table'");
    return ($q && mysqli_num_rows($q) > 0);
}

// ── 1. candidate_employment_availability table ──
if (!table_exists_sw($con, 'candidate_employment_availability')) {
    $sql = "CREATE TABLE `candidate_employment_availability` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `application_id` INT NOT NULL,
        `candidate_id` INT NOT NULL,
        `ready_to_join` ENUM('yes','no') NOT NULL,
        `decline_reason` TEXT NULL,
        `currently_working` ENUM('yes','no') NULL,
        `current_company_name` VARCHAR(255) NULL,
        `expected_leaving_date` DATE NULL,
        `notice_period_days` INT NULL,
        `available_immediately` ENUM('yes','no') NULL,
        `approximate_joining_date` DATE NULL,
        `candidate_notes` TEXT NULL,
        `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_cea_app` (`application_id`),
        INDEX `idx_cea_cand` (`candidate_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (mysqli_query($con, $sql)) {
        echo "✓ Created table candidate_employment_availability\n";
    } else {
        echo "✗ Error creating candidate_employment_availability: " . mysqli_error($con) . "\n";
    }
} else {
    echo "✓ Table candidate_employment_availability already exists\n";
}

// ── 2. Add candidate_response_status to hiring_decisions ──
if (table_exists_sw($con, 'hiring_decisions')) {
    if (!column_exists_sw($con, 'hiring_decisions', 'candidate_response_status')) {
        $sql = "ALTER TABLE `hiring_decisions` ADD COLUMN `candidate_response_status`
                ENUM('pending','accepted','declined') NULL DEFAULT 'pending'
                AFTER `expected_leaving_date_noted`";
        if (mysqli_query($con, $sql)) {
            echo "✓ Added candidate_response_status column to hiring_decisions\n";
        } else {
            echo "✗ Error adding candidate_response_status: " . mysqli_error($con) . "\n";
        }
    } else {
        echo "✓ Column candidate_response_status already exists in hiring_decisions\n";
    }
} else {
    echo "✗ hiring_decisions table not found — run migrate_hiring_workflow.php first\n";
}

echo "\nSelection Workflow Enhancement migration completed!\n";
