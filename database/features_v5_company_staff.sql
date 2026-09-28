-- ============================================================================
--  NovaHire — features_v5_company_staff.sql
--  Phase 1: Company Staff / Interviewer Management System
--
--  Adds:
--    - company_staff table (tracks company employees who can be interviewers)
--
--  Safe to run on top of database.sql + features_v3.sql + features_v4_assessment.sql.
--  Uses CREATE TABLE IF NOT EXISTS.
-- ============================================================================

USE projects;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+06:00";

-- ============================================================
-- 1. COMPANY STAFF TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_staff` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `company_id`      int(11) NOT NULL,
  `full_name`       varchar(150) NOT NULL,
  `email`           varchar(150) NOT NULL,
  `phone`           varchar(50) DEFAULT NULL,
  `designation`     varchar(100) NOT NULL,
  `department`      varchar(100) DEFAULT NULL,
  `status`          enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_staff_company` (`company_id`),
  KEY `idx_company_staff_status` (`status`),
  UNIQUE KEY `unique_company_staff_email` (`company_id`, `email`),
  CONSTRAINT `fk_company_staff_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
