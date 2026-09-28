<?php
/**
 * NovaHire — Database Migration for Post-Interview Hiring Workflow
 *
 * Safely adds new columns and tables for:
 * 1. Staff interview feedback employment status & expected leaving date
 * 2. Company hiring decisions joining date calculation & confirmation
 * 3. Document requirements & candidate documents
 * 4. Appointment letter versions & joining date fields
 */

require_once __DIR__ . '/../admin/dbcon.php';
global $con;

echo "Starting migration for Post-Interview Hiring Workflow...\n\n";

function column_exists($con, $table, $column) {
    $q = mysqli_query($con, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($q && mysqli_num_rows($q) > 0);
}

function table_exists($con, $table) {
    $q = mysqli_query($con, "SHOW TABLES LIKE '$table'");
    return ($q && mysqli_num_rows($q) > 0);
}

// ── 1. interview_feedback extensions ──
if (table_exists($con, 'interview_feedback')) {
    if (!column_exists($con, 'interview_feedback', 'employment_status')) {
        $sql = "ALTER TABLE `interview_feedback` 
                ADD COLUMN `employment_status` ENUM('CURRENTLY_WORKING', 'NOT_CURRENTLY_WORKING') NULL DEFAULT NULL AFTER `recommendation`";
        if (mysqli_query($con, $sql)) {
            echo "✓ Added employment_status column to interview_feedback\n";
        } else {
            echo "✗ Error adding employment_status: " . mysqli_error($con) . "\n";
        }
    } else {
        echo "✓ Column employment_status already exists in interview_feedback\n";
    }

    if (!column_exists($con, 'interview_feedback', 'expected_leaving_date')) {
        $sql = "ALTER TABLE `interview_feedback` 
                ADD COLUMN `expected_leaving_date` DATE NULL DEFAULT NULL AFTER `employment_status`";
        if (mysqli_query($con, $sql)) {
            echo "✓ Added expected_leaving_date column to interview_feedback\n";
        } else {
            echo "✗ Error adding expected_leaving_date: " . mysqli_error($con) . "\n";
        }
    } else {
        echo "✓ Column expected_leaving_date already exists in interview_feedback\n";
    }
}

// ── 2. hiring_decisions extensions ──
if (table_exists($con, 'hiring_decisions')) {
    $hd_cols = [
        'suggested_joining_date'       => "DATE NULL DEFAULT NULL AFTER `final_notes`",
        'final_joining_date'           => "DATE NULL DEFAULT NULL AFTER `suggested_joining_date`",
        'joining_confirmed_by'         => "INT NULL DEFAULT NULL AFTER `final_joining_date`",
        'joining_confirmed_at'         => "DATETIME NULL DEFAULT NULL AFTER `joining_confirmed_by`",
        'employment_status_noted'      => "ENUM('CURRENTLY_WORKING', 'NOT_CURRENTLY_WORKING') NULL DEFAULT NULL AFTER `joining_confirmed_at`",
        'expected_leaving_date_noted'  => "DATE NULL DEFAULT NULL AFTER `employment_status_noted`",
    ];

    foreach ($hd_cols as $col => $def) {
        if (!column_exists($con, 'hiring_decisions', $col)) {
            $sql = "ALTER TABLE `hiring_decisions` ADD COLUMN `$col` $def";
            if (mysqli_query($con, $sql)) {
                echo "✓ Added $col column to hiring_decisions\n";
            } else {
                echo "✗ Error adding $col: " . mysqli_error($con) . "\n";
            }
        } else {
            echo "✓ Column $col already exists in hiring_decisions\n";
        }
    }
}

// ── 3. hiring_letters extensions ──
if (table_exists($con, 'hiring_letters')) {
    $hl_cols = [
        'joining_date'        => "DATE NULL DEFAULT NULL AFTER `content`",
        'current_version_id'  => "INT NULL DEFAULT NULL AFTER `joining_date`",
        'designation'         => "VARCHAR(150) NULL DEFAULT NULL AFTER `current_version_id`",
        'salary'              => "VARCHAR(100) NULL DEFAULT NULL AFTER `designation`",
        'work_location'       => "VARCHAR(255) NULL DEFAULT NULL AFTER `salary`",
    ];

    foreach ($hl_cols as $col => $def) {
        if (!column_exists($con, 'hiring_letters', $col)) {
            $sql = "ALTER TABLE `hiring_letters` ADD COLUMN `$col` $def";
            if (mysqli_query($con, $sql)) {
                echo "✓ Added $col column to hiring_letters\n";
            } else {
                echo "✗ Error adding $col: " . mysqli_error($con) . "\n";
            }
        } else {
            echo "✓ Column $col already exists in hiring_letters\n";
        }
    }
}

// ── 4. hiring_document_requirements table ──
if (!table_exists($con, 'hiring_document_requirements')) {
    $sql = "CREATE TABLE `hiring_document_requirements` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_id` INT NOT NULL,
        `application_id` INT NOT NULL,
        `candidate_id` INT NOT NULL,
        `document_type` VARCHAR(100) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT NULL,
        `is_required` TINYINT(1) NOT NULL DEFAULT 1,
        `status` ENUM('REQUESTED', 'UPLOADED', 'UNDER_REVIEW', 'VERIFIED', 'REJECTED') NOT NULL DEFAULT 'REQUESTED',
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_req_app` (`application_id`),
        INDEX `idx_req_comp` (`company_id`),
        INDEX `idx_req_cand` (`candidate_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (mysqli_query($con, $sql)) {
        echo "✓ Created table hiring_document_requirements\n";
    } else {
        echo "✗ Error creating hiring_document_requirements: " . mysqli_error($con) . "\n";
    }
} else {
    echo "✓ Table hiring_document_requirements already exists\n";
}

// ── 5. candidate_documents table ──
if (!table_exists($con, 'candidate_documents')) {
    $sql = "CREATE TABLE `candidate_documents` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `requirement_id` INT NOT NULL,
        `application_id` INT NOT NULL,
        `company_id` INT NOT NULL,
        `candidate_id` INT NOT NULL,
        `file_path` VARCHAR(255) NOT NULL,
        `original_filename` VARCHAR(255) NOT NULL,
        `file_size` INT NOT NULL,
        `mime_type` VARCHAR(100) NOT NULL,
        `status` ENUM('UPLOADED', 'UNDER_REVIEW', 'VERIFIED', 'REJECTED') NOT NULL DEFAULT 'UPLOADED',
        `rejection_reason` TEXT NULL,
        `verified_by` INT NULL,
        `verified_at` DATETIME NULL,
        `version` INT NOT NULL DEFAULT 1,
        `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_doc_req` (`requirement_id`),
        INDEX `idx_doc_app` (`application_id`),
        INDEX `idx_doc_comp` (`company_id`),
        INDEX `idx_doc_cand` (`candidate_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (mysqli_query($con, $sql)) {
        echo "✓ Created table candidate_documents\n";
    } else {
        echo "✗ Error creating candidate_documents: " . mysqli_error($con) . "\n";
    }
} else {
    echo "✓ Table candidate_documents already exists\n";
}

// ── 6. appointment_letter_versions table ──
if (!table_exists($con, 'appointment_letter_versions')) {
    $sql = "CREATE TABLE `appointment_letter_versions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `hiring_letter_id` INT NOT NULL,
        `application_id` INT NOT NULL,
        `version_number` INT NOT NULL DEFAULT 1,
        `subject` VARCHAR(255) NULL,
        `content` LONGTEXT NOT NULL,
        `generation_method` ENUM('AI_GENERATED', 'MANUAL_EDIT', 'TEMPLATE') NOT NULL DEFAULT 'AI_GENERATED',
        `status` ENUM('DRAFT', 'SENT', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_ver_hl` (`hiring_letter_id`),
        INDEX `idx_ver_app` (`application_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (mysqli_query($con, $sql)) {
        echo "✓ Created table appointment_letter_versions\n";
    } else {
        echo "✗ Error creating appointment_letter_versions: " . mysqli_error($con) . "\n";
    }
} else {
    echo "✓ Table appointment_letter_versions already exists\n";
}

// ── 7. Ensure private uploads directory exists ──
$upload_dir = dirname(__DIR__) . '/uploads/hiring_documents';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
    echo "✓ Created directory uploads/hiring_documents\n";
}
// Add .htaccess to prevent direct web execution
$htaccess = $upload_dir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
    echo "✓ Secured uploads/hiring_documents with .htaccess (Deny from all)\n";
}

echo "\nMigration completed successfully!\n";
