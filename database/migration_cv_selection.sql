-- Migration: Add CV selection columns to job_applications
-- Date: 2026-09-22
-- Purpose: Support per-application CV type selection (auto-generated, AI-customized, uploaded)

ALTER TABLE `job_applications`
  ADD COLUMN `cv_type` ENUM('auto_generated','ai_customized','uploaded') NOT NULL DEFAULT 'auto_generated' AFTER `cover_letter`,
  ADD COLUMN `cv_file` VARCHAR(255) DEFAULT NULL AFTER `cv_type`,
  ADD COLUMN `ai_cv_id` INT(11) DEFAULT NULL AFTER `cv_file`;
