-- ============================================================================
--  NovaHire — features_v13_ai_cv_screening.sql
--  AI CV Analyzer & Company Candidate Screening Decision Support System
-- ============================================================================

USE `projects`;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `ai_cv_analyses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `seeker_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `cv_type` enum('auto_generated','ai_customized','uploaded') NOT NULL DEFAULT 'auto_generated',
  `cv_reference` varchar(255) DEFAULT NULL,
  `cv_hash` varchar(64) NOT NULL,
  `job_req_hash` varchar(64) NOT NULL,
  `overall_match_score` int(11) NOT NULL DEFAULT 0,
  `skills_match_score` int(11) NOT NULL DEFAULT 0,
  `experience_match_score` int(11) NOT NULL DEFAULT 0,
  `education_match_score` int(11) NOT NULL DEFAULT 0,
  `project_relevance_score` int(11) NOT NULL DEFAULT 0,
  `required_matches` longtext DEFAULT NULL,
  `required_missing` longtext DEFAULT NULL,
  `required_unclear` longtext DEFAULT NULL,
  `preferred_matches` longtext DEFAULT NULL,
  `preferred_missing` longtext DEFAULT NULL,
  `preferred_unclear` longtext DEFAULT NULL,
  `strengths` longtext DEFAULT NULL,
  `gaps` longtext DEFAULT NULL,
  `evidence` longtext DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `status` enum('NOT_ANALYZED','ANALYZING','COMPLETED','FAILED','OUTDATED') NOT NULL DEFAULT 'NOT_ANALYZED',
  `model` varchar(100) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `analyzed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_app_analysis` (`application_id`),
  KEY `idx_job` (`job_id`),
  KEY `idx_seeker` (`seeker_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`),
  KEY `idx_app_cv_hash` (`application_id`, `cv_hash`),
  CONSTRAINT `fk_aca_application` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
