-- ═══════════════════════════════════════════════════════════════════════
-- NovaHire — Phase 2: Interview Scheduling & Interviewer Assignment
-- Migration v6: Extend `interviews` table
-- Safe to re-run (uses IF NOT EXISTS / column checks)
-- ═══════════════════════════════════════════════════════════════════════

-- 1. Add interviewer_id (FK → company_staff)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND COLUMN_NAME = 'interviewer_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `interviews` ADD COLUMN `interviewer_id` INT(11) DEFAULT NULL AFTER `job_id`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Add round_number
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND COLUMN_NAME = 'round_number');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `interviews` ADD COLUMN `round_number` INT(11) NOT NULL DEFAULT 1 AFTER `interviewer_id`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add title
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND COLUMN_NAME = 'title');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `interviews` ADD COLUMN `title` VARCHAR(150) NOT NULL DEFAULT \'Technical Interview\' AFTER `round_number`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Add duration_minutes
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND COLUMN_NAME = 'duration_minutes');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `interviews` ADD COLUMN `duration_minutes` INT(11) NOT NULL DEFAULT 30 AFTER `interview_time`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Add end_time
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND COLUMN_NAME = 'end_time');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `interviews` ADD COLUMN `end_time` TIME DEFAULT NULL AFTER `duration_minutes`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Add updated_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND COLUMN_NAME = 'updated_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `interviews` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add index on interviewer_id
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND INDEX_NAME = 'idx_interviews_interviewer');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `interviews` ADD INDEX `idx_interviews_interviewer` (`interviewer_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Add composite index for conflict detection queries
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND INDEX_NAME = 'idx_interviews_date_time');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `interviews` ADD INDEX `idx_interviews_date_time` (`interview_date`, `interview_time`, `end_time`, `status`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. Add foreign key constraint (interviewer_id → company_staff.id, SET NULL on delete)
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND CONSTRAINT_NAME = 'fk_interviews_interviewer');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `interviews` ADD CONSTRAINT `fk_interviews_interviewer` FOREIGN KEY (`interviewer_id`) REFERENCES `company_staff`(`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Done ✓
