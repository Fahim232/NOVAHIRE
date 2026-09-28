-- ============================================================================
--  NovaHire — features_v9_verified_skills.sql
--  Verified Skills & Platform Skill Assessments
--
--  Adds:
--    - skill_categories
--    - skills
--    - skill_assessments
--    - skill_assessment_questions
--    - skill_assessment_attempts
--    - verified_skills
--    - skill_progress
--    - job_required_skills
--
--  Run:  mysql -u root projects < features_v9_verified_skills.sql
-- ============================================================================
USE projects;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- 1. SKILL CATEGORIES
CREATE TABLE IF NOT EXISTS `skill_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. SKILLS
CREATE TABLE IF NOT EXISTS `skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `category_id` (`category_id`),
  FOREIGN KEY (`category_id`) REFERENCES `skill_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. SKILL ASSESSMENTS
CREATE TABLE IF NOT EXISTS `skill_assessments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `skill_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `time_limit` int(11) NOT NULL DEFAULT 1200 COMMENT 'Duration in seconds',
  `passing_score` int(11) NOT NULL DEFAULT 40 COMMENT 'Percentage 0-100',
  `is_premium` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive','draft') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `skill_id` (`skill_id`),
  FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. SKILL ASSESSMENT QUESTIONS
CREATE TABLE IF NOT EXISTS `skill_assessment_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `option1` varchar(255) NOT NULL,
  `option2` varchar(255) NOT NULL,
  `option3` varchar(255) NOT NULL,
  `option4` varchar(255) NOT NULL,
  `correct_answer` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `assessment_id` (`assessment_id`),
  FOREIGN KEY (`assessment_id`) REFERENCES `skill_assessments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. SKILL ASSESSMENT ATTEMPTS (Server-side session tracking)
CREATE TABLE IF NOT EXISTS `skill_assessment_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `status` enum('in_progress','completed','passed','failed') NOT NULL DEFAULT 'in_progress',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `assessment_id` (`assessment_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user_info`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assessment_id`) REFERENCES `skill_assessments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. VERIFIED SKILLS (Badges)
CREATE TABLE IF NOT EXISTS `verified_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `skill_level` enum('Beginner','Intermediate','Advanced','Expert') NOT NULL,
  `score` decimal(5,2) NOT NULL,
  `verified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_skill` (`user_id`, `skill_id`),
  KEY `skill_id` (`skill_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user_info`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assessment_id`) REFERENCES `skill_assessments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. SKILL PROGRESS
CREATE TABLE IF NOT EXISTS `skill_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `score` decimal(5,2) NOT NULL,
  `skill_level` enum('Beginner','Intermediate','Advanced','Expert','Needs Improvement') NOT NULL,
  `attempt_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `skill_id` (`skill_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user_info`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. JOB REQUIRED SKILLS (For shortlisting priority)
CREATE TABLE IF NOT EXISTS `job_required_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `minimum_level` enum('Any','Beginner','Intermediate','Advanced','Expert') NOT NULL DEFAULT 'Any',
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_skill` (`job_id`, `skill_id`),
  KEY `skill_id` (`skill_id`),
  FOREIGN KEY (`job_id`) REFERENCES `company_jobs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- INSERT SOME SAMPLE DATA
INSERT IGNORE INTO `skill_categories` (`id`, `name`, `icon`) VALUES
(1, 'Programming', 'fa-code'),
(2, 'Database', 'fa-database'),
(3, 'Web Development', 'fa-globe');

INSERT IGNORE INTO `skills` (`id`, `category_id`, `name`, `description`) VALUES
(1, 1, 'Python', 'Python programming language'),
(2, 2, 'SQL', 'Structured Query Language'),
(3, 3, 'React', 'React.js Frontend Framework');

INSERT IGNORE INTO `skill_assessments` (`id`, `skill_id`, `title`, `description`, `time_limit`, `passing_score`, `is_premium`) VALUES
(1, 1, 'Python Standard Assessment', 'Test your Python skills to earn a verified badge.', 1200, 40, 0),
(2, 1, 'Advanced Python PRO', 'In-depth Python testing for PRO users.', 1800, 70, 1),
(3, 2, 'SQL Fundamentals', 'Core SQL querying assessment.', 900, 40, 0),
(4, 3, 'React Developer Assessment', 'Comprehensive React.js test.', 1500, 40, 0);

INSERT IGNORE INTO `skill_assessment_questions` (`assessment_id`, `question`, `option1`, `option2`, `option3`, `option4`, `correct_answer`) VALUES
(1, 'Which data type is immutable in Python?', 'List', 'Set', 'Tuple', 'Dictionary', 'Tuple'),
(1, 'How do you print a string in Python?', 'echo "hello"', 'print("hello")', 'printf("hello")', 'System.out.println("hello")', 'print("hello")'),
(3, 'Which clause is used to filter records in SQL?', 'WHERE', 'FILTER', 'HAVING', 'SELECT', 'WHERE'),
(4, 'What is the virtual DOM in React?', 'A real DOM copy', 'An in-memory representation of real DOM', 'A database', 'A server', 'An in-memory representation of real DOM');
