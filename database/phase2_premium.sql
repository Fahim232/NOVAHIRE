-- ============================================================
-- NovaHire Phase 2 — Premium Features Migration
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================
-- 1. PROFILE STRENGTH SCORES (cached scoring)
-- ============================================================
CREATE TABLE IF NOT EXISTS `profile_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `overall_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `completeness_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `skills_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `experience_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `activity_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `recommendations` text DEFAULT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. CAREER ANALYTICS (per-user snapshot logs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `career_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `total_applications` int(11) NOT NULL DEFAULT 0,
  `applications_reviewed` int(11) NOT NULL DEFAULT 0,
  `applications_shortlisted` int(11) NOT NULL DEFAULT 0,
  `applications_rejected` int(11) NOT NULL DEFAULT 0,
  `interviews_scheduled` int(11) NOT NULL DEFAULT 0,
  `avg_quiz_score` decimal(5,2) DEFAULT NULL,
  `response_rate` decimal(5,2) DEFAULT NULL,
  `top_skills` text DEFAULT NULL,
  `skill_demand_data` text DEFAULT NULL,
  `category_breakdown` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `period` (`period_start`, `period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. JOB MATCH NOTIFICATION PREFERENCES
-- ============================================================
CREATE TABLE IF NOT EXISTS `job_match_prefs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `push_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `min_match_score` tinyint(3) unsigned NOT NULL DEFAULT 60,
  `categories` text DEFAULT NULL,
  `locations` text DEFAULT NULL,
  `employment_types` text DEFAULT NULL,
  `salary_min` int(11) DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. JOB MATCH NOTIFICATIONS (log of sent notifications)
-- ============================================================
CREATE TABLE IF NOT EXISTS `job_match_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `match_score` tinyint(3) unsigned NOT NULL,
  `matched_skills` text DEFAULT NULL,
  `sent_via` enum('email','push','in_app') NOT NULL DEFAULT 'in_app',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `job_id` (`job_id`),
  KEY `sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. APPLICATION PIPELINE CUSTOM STAGES
-- ============================================================
CREATE TABLE IF NOT EXISTS `pipeline_stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `stage_name` varchar(100) NOT NULL,
  `stage_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `color` varchar(7) NOT NULL DEFAULT '#3b82f6',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. KANBAN BOARD CARD POSITIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `kanban_positions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `stage` varchar(50) NOT NULL DEFAULT 'applied',
  `position` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_app` (`user_id`, `application_id`),
  KEY `application_id` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. USER ACTIVITY LOG (for analytics & score)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_activity_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `activity_data` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `activity_type` (`activity_type`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. PROFILE VIEW TRACKER (for analytics)
-- ============================================================
CREATE TABLE IF NOT EXISTS `profile_views` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `viewer_type` enum('company','admin','seeker') DEFAULT 'company',
  `viewer_id` int(11) DEFAULT NULL,
  `viewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `viewed_at` (`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Insert default pipeline stages for existing users
-- ============================================================
-- Add pipeline_stage column to existing job_applications table
ALTER TABLE `job_applications`
  ADD COLUMN `pipeline_stage` varchar(50) DEFAULT 'applied' AFTER `application_status`,
  ADD COLUMN `stage_updated_at` datetime DEFAULT NULL AFTER `pipeline_stage`;

-- Set default pipeline stage based on existing application_status
UPDATE `job_applications` SET `pipeline_stage` = CASE
  WHEN `application_status` = 'pending' THEN 'applied'
  WHEN `application_status` = 'reviewed' THEN 'reviewing'
  WHEN `application_status` = 'shortlisted' THEN 'shortlisted'
  WHEN `application_status` = 'rejected' THEN 'rejected'
  ELSE 'applied'
END WHERE `pipeline_stage` IS NULL OR `pipeline_stage` = '';

COMMIT;
