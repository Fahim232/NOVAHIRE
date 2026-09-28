-- Phase 4: Final Hiring Decision

-- Extend the application_status ENUM safely (MySQL allows adding to ENUMs safely at the end)
ALTER TABLE `job_applications` 
MODIFY COLUMN `application_status` enum('pending','reviewed','shortlisted','rejected','under_final_review','selected') NOT NULL DEFAULT 'pending';

-- Create hiring_decisions table
CREATE TABLE IF NOT EXISTS `hiring_decisions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `application_id` int(11) NOT NULL,
    `company_id` int(11) NOT NULL,
    `candidate_id` int(11) NOT NULL,
    `decided_by` int(11) NOT NULL,
    `decision` enum('under_final_review','selected','rejected') NOT NULL,
    `final_notes` text DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `application_id` (`application_id`),
    KEY `company_id` (`company_id`),
    KEY `candidate_id` (`candidate_id`),
    KEY `decided_by` (`decided_by`),
    CONSTRAINT `fk_hd_application` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hd_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hd_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `user_info` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hd_decider` FOREIGN KEY (`decided_by`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
