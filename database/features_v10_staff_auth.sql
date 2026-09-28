-- ============================================================================
-- NovaHire — features_v10_staff_auth.sql
-- Phase 1: Staff Authentication and Interview Assignments
-- ============================================================================

USE projects;

-- 1. Extend company_staff table
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_staff' AND COLUMN_NAME = 'password_hash');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `company_staff`
    ADD COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `email`,
    ADD COLUMN `activation_token` VARCHAR(64) NULL DEFAULT NULL AFTER `password_hash`,
    ADD COLUMN `activation_expires_at` DATETIME NULL DEFAULT NULL AFTER `activation_token`,
    ADD COLUMN `is_activated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `activation_expires_at`,
    ADD COLUMN `activated_at` DATETIME NULL DEFAULT NULL AFTER `is_activated`,
    ADD COLUMN `last_login` DATETIME NULL DEFAULT NULL AFTER `activated_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Modify notifications enum
ALTER TABLE `notifications`
MODIFY COLUMN `recipient_type` enum('user','company','admin','staff') NOT NULL COMMENT 'Who receives this notification';

-- 3. Create interview_staff_assignments
CREATE TABLE IF NOT EXISTS `interview_staff_assignments` (
  `interview_id` INT(11) NOT NULL,
  `staff_id` INT(11) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`interview_id`, `staff_id`),
  CONSTRAINT `fk_isa_interview` FOREIGN KEY (`interview_id`) REFERENCES `interviews`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_isa_staff` FOREIGN KEY (`staff_id`) REFERENCES `company_staff`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Modify interviews status if needed
ALTER TABLE `interviews`
MODIFY COLUMN `status` enum('scheduled','confirmed','in_progress','completed','cancelled','rescheduled','no_show') NOT NULL DEFAULT 'scheduled';
