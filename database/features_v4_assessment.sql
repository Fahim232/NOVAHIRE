-- ============================================================================
--  NovaHire — features_v4_assessment.sql
--  Secure Assessment Engine + AI-Assessed Short Answers (additive migration)
--
--  Adds:
--    - question_type + ideal_answer columns on company_job_questions
--    - assessment_sessions   (server-side session tracking)
--    - assessment_responses  (per-question answer log)
--    - assessment_events     (anti-cheating event tracking)
--
--  Safe to run on top of the existing database.sql + features_v3.sql.
--  Uses CREATE TABLE IF NOT EXISTS and ADD COLUMN IF NOT EXISTS,
--  so re-running it does no harm.
--
--  Run:  mysql -u root projects < features_v4_assessment.sql
--        (or import via phpMyAdmin into the `projects` database)
-- ============================================================================
USE projects;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================
-- 1. EXTEND company_job_questions — support short-answer type
-- ============================================================
ALTER TABLE `company_job_questions`
  ADD COLUMN IF NOT EXISTS `question_type` ENUM('mcq','short_answer') NOT NULL DEFAULT 'mcq' AFTER `job_id`;

ALTER TABLE `company_job_questions`
  ADD COLUMN IF NOT EXISTS `ideal_answer` TEXT DEFAULT NULL AFTER `correct_answer`;

ALTER TABLE `company_job_questions`
  ADD COLUMN IF NOT EXISTS `time_limit` INT NOT NULL DEFAULT 60 AFTER `ideal_answer`;

ALTER TABLE `company_job_questions`
  ADD COLUMN IF NOT EXISTS `marks` INT NOT NULL DEFAULT 1 AFTER `time_limit`;

-- Allow option columns to be NULL (short-answer questions have no options)
ALTER TABLE `company_job_questions` MODIFY `option1` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `company_job_questions` MODIFY `option2` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `company_job_questions` MODIFY `option3` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `company_job_questions` MODIFY `option4` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `company_job_questions` MODIFY `correct_answer` VARCHAR(255) DEFAULT NULL;

-- ============================================================
-- 2. ASSESSMENT SESSIONS  (server-controlled quiz lifecycle)
-- ============================================================
CREATE TABLE IF NOT EXISTS `assessment_sessions` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `user_id`         int(11) NOT NULL,
  `job_id`          int(11) NOT NULL,
  `application_id`  int(11) DEFAULT NULL,
  `total_questions`  int(11) NOT NULL DEFAULT 0,
  `current_index`   int(11) NOT NULL DEFAULT 0 COMMENT 'zero-based index of the next unanswered question',
  `time_limit`      int(11) NOT NULL DEFAULT 60,
  `status`          ENUM('in_progress','completed','timed_out','terminated') NOT NULL DEFAULT 'in_progress',
  `score_mcq`       decimal(5,2) DEFAULT NULL COMMENT 'MCQ percentage after completion',
  `score_short`     decimal(5,2) DEFAULT NULL COMMENT 'Short-answer AI percentage after grading',
  `score_final`     decimal(5,2) DEFAULT NULL COMMENT 'Weighted composite score',
  `risk_score`      int(11) NOT NULL DEFAULT 0 COMMENT 'Cumulative anti-cheating risk score',
  `risk_level`      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `started_at`      timestamp NOT NULL DEFAULT current_timestamp(),
  `current_question_started_at` timestamp NULL DEFAULT NULL,
  `completed_at`    timestamp NULL DEFAULT NULL,
  `question_order`  TEXT DEFAULT NULL COMMENT 'JSON array of question IDs in shuffled order',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `job_id` (`job_id`),
  KEY `status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `user_info`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`job_id`) REFERENCES `company_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. ASSESSMENT RESPONSES  (per-question answers)
-- ============================================================
CREATE TABLE IF NOT EXISTS `assessment_responses` (
  `id`             int(11) NOT NULL AUTO_INCREMENT,
  `session_id`     int(11) NOT NULL,
  `question_id`    int(11) NOT NULL,
  `question_index` int(11) NOT NULL DEFAULT 0 COMMENT 'order in which this question was presented',
  `user_answer`    TEXT DEFAULT NULL,
  `is_correct`     tinyint(1) DEFAULT NULL COMMENT 'NULL = not yet graded, 0 = wrong, 1 = correct',
  `ai_score`       int(11) DEFAULT NULL COMMENT '0-100 AI score for short answers',
  `ai_feedback`    TEXT DEFAULT NULL COMMENT 'AI rationale for the score',
  `answered_at`    timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `question_id` (`question_id`),
  UNIQUE KEY `session_question` (`session_id`, `question_id`),
  FOREIGN KEY (`session_id`)  REFERENCES `assessment_sessions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `company_job_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. ASSESSMENT EVENTS  (anti-cheating tracking)
-- ============================================================
CREATE TABLE IF NOT EXISTS `assessment_events` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `session_id`  int(11) NOT NULL,
  `event_type`  VARCHAR(50) NOT NULL COMMENT 'TAB_SWITCH, FULLSCREEN_EXIT, COPY, PASTE, RIGHT_CLICK, BLUR, DEVTOOLS',
  `event_data`  TEXT DEFAULT NULL COMMENT 'Optional JSON payload with details',
  `risk_weight` int(11) NOT NULL DEFAULT 0 COMMENT 'Points added to risk score for this event',
  `event_time`  timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `event_type` (`event_type`),
  FOREIGN KEY (`session_id`) REFERENCES `assessment_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
