-- ============================================================================
-- NovaHire — features_v11_phase2.sql
-- Phase 2: Structured Feedback, Final Hiring Decision, and Hiring Letters
-- ============================================================================

USE projects;

-- 1. Modify interview_feedback to support multi-staff feedback
-- Drop old unique constraint if it exists (ignoring errors if it doesn't via PHP)
-- ALTER TABLE `interview_feedback` DROP INDEX `unique_feedback_interview`;

-- Add columns to interview_feedback if they don't exist
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interview_feedback' AND COLUMN_NAME = 'staff_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `interview_feedback` 
    ADD COLUMN `staff_id` INT(11) NOT NULL AFTER `interview_id`,
    ADD COLUMN `recommendation` ENUM(''STRONGLY_RECOMMEND'',''RECOMMEND'',''FURTHER_REVIEW'',''NOT_RECOMMENDED'') NOT NULL DEFAULT ''FURTHER_REVIEW'' AFTER `comments`,
    ADD COLUMN `status` ENUM(''DRAFT'',''SUBMITTED'') NOT NULL DEFAULT ''SUBMITTED'' AFTER `recommendation`
', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop old unique key if it hasn't been dropped
-- First drop the foreign key that might rely on it
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interview_feedback' AND CONSTRAINT_NAME = 'fk_feedback_interview');
SET @sql = IF(@fk_exists > 0, 'ALTER TABLE `interview_feedback` DROP FOREIGN KEY `fk_feedback_interview`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interview_feedback' AND INDEX_NAME = 'unique_feedback_interview');
SET @sql = IF(@index_exists > 0, 'ALTER TABLE `interview_feedback` DROP INDEX `unique_feedback_interview`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add new composite unique key
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interview_feedback' AND INDEX_NAME = 'unique_feedback_interview_staff');
SET @sql = IF(@index_exists = 0, 'ALTER TABLE `interview_feedback` ADD UNIQUE KEY `unique_feedback_interview_staff` (`interview_id`, `staff_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Re-add the foreign key
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interview_feedback' AND CONSTRAINT_NAME = 'fk_feedback_interview');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `interview_feedback` ADD CONSTRAINT `fk_feedback_interview` FOREIGN KEY (`interview_id`) REFERENCES `interviews` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Add new composite unique key
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interview_feedback' AND INDEX_NAME = 'unique_feedback_interview_staff');
SET @sql = IF(@index_exists = 0, 'ALTER TABLE `interview_feedback` ADD UNIQUE KEY `unique_feedback_interview_staff` (`interview_id`, `staff_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Create hiring_letters table
CREATE TABLE IF NOT EXISTS `hiring_letters` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `candidate_id` INT(11) NOT NULL,
  `application_id` INT(11) NOT NULL,
  `job_id` INT(11) NOT NULL,
  `status` ENUM('DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'SENT', 'REVOKED') NOT NULL DEFAULT 'DRAFT',
  `letter_type` VARCHAR(50) NOT NULL DEFAULT 'Hiring Letter',
  `subject` VARCHAR(255) DEFAULT NULL,
  `content` LONGTEXT DEFAULT NULL,
  `created_by` INT(11) NOT NULL,
  `approved_by` INT(11) DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_hl_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hl_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `user_info` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hl_app` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hl_job` FOREIGN KEY (`job_id`) REFERENCES `company_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create hiring_audit_logs table
CREATE TABLE IF NOT EXISTS `hiring_audit_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `user_type` ENUM('user', 'company', 'admin', 'staff') NOT NULL,
  `user_id` INT(11) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT(11) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_hal_company` (`company_id`),
  INDEX `idx_hal_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
