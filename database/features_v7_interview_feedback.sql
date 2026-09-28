-- ============================================================================
--  NovaHire — features_v7_interview_feedback.sql
--  Phase 3: Interview Feedback & Scoring
-- ============================================================================

USE projects;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+06:00";

-- 1. ADD access_token to interviews (for passwordless interviewer access)
ALTER TABLE `interviews`
ADD COLUMN `access_token` VARCHAR(64) NULL DEFAULT NULL AFTER `status`,
ADD UNIQUE INDEX `idx_interviews_access_token` (`access_token`);

-- 2. CREATE interview_feedback TABLE
CREATE TABLE IF NOT EXISTS `interview_feedback` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `interview_id` INT(11) NOT NULL,
  `technical_score` TINYINT NOT NULL COMMENT '1-10',
  `communication_score` TINYINT NOT NULL COMMENT '1-10',
  `problem_solving_score` TINYINT NOT NULL COMMENT '1-10',
  `teamwork_score` TINYINT NOT NULL COMMENT '1-10',
  `professionalism_score` TINYINT NOT NULL COMMENT '1-10',
  `total_score` INT(11) NOT NULL,
  `comments` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_feedback_interview` (`interview_id`),
  CONSTRAINT `fk_feedback_interview` FOREIGN KEY (`interview_id`) REFERENCES `interviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
