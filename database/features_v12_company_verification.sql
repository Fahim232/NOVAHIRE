-- ============================================================================
-- NovaHire — features_v12_company_verification.sql
-- Company Verification & Business Evidence Submission Architecture
-- ============================================================================

USE projects;

-- 1. Extend `companies` table with verification lifecycle columns
SET @col_vstat = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'verification_status');
SET @sql_vstat = IF(@col_vstat = 0, 'ALTER TABLE `companies` 
    ADD COLUMN `verification_status` ENUM(''pending'', ''under_review'', ''verified'', ''rejected'', ''resubmission_required'') NOT NULL DEFAULT ''pending'' AFTER `is_verified`,
    ADD COLUMN `verified_at` DATETIME DEFAULT NULL AFTER `verification_status`,
    ADD COLUMN `verified_by` INT(11) DEFAULT NULL AFTER `verified_at`,
    ADD COLUMN `verification_rejected_at` DATETIME DEFAULT NULL AFTER `verified_by`,
    ADD COLUMN `verification_rejection_reason` TEXT DEFAULT NULL AFTER `verification_rejected_at`
', 'SELECT 1');
PREPARE stmt_vstat FROM @sql_vstat; EXECUTE stmt_vstat; DEALLOCATE PREPARE stmt_vstat;

-- Safe legacy migration strategy:
-- If an existing company had is_verified = 1, align verification_status to 'verified'
UPDATE `companies` 
SET `verification_status` = 'verified', 
    `verified_at` = COALESCE(`registration_date`, NOW())
WHERE `is_verified` = 1 AND `verification_status` = 'pending';

-- 2. Create `company_verification_documents` table
CREATE TABLE IF NOT EXISTS `company_verification_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_type` VARCHAR(50) NOT NULL DEFAULT 'other',
  `document_title` VARCHAR(255) NOT NULL,
  `document_number` VARCHAR(100) DEFAULT NULL,
  `issuing_authority` VARCHAR(255) DEFAULT NULL,
  `issue_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT(11) NOT NULL,
  `verification_status` ENUM('pending', 'under_review', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `reviewed_by` INT(11) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_cvd_company` (`company_id`),
  INDEX `idx_cvd_status` (`verification_status`),
  CONSTRAINT `fk_cvd_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create `company_verification_reviews` (Audit Trail)
CREATE TABLE IF NOT EXISTS `company_verification_reviews` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `admin_id` INT(11) DEFAULT NULL,
  `action` ENUM('submitted', 'approved', 'rejected', 'resubmission_requested', 'document_uploaded', 'document_deleted') NOT NULL,
  `previous_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_cvr_company` (`company_id`),
  CONSTRAINT `fk_cvr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
