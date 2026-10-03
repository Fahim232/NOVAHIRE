-- ============================================================
-- NovaHire v14
-- Candidate Application Tracker
-- ============================================================

CREATE TABLE IF NOT EXISTS candidate_application_tracker (
    id INT AUTO_INCREMENT PRIMARY KEY,

    application_id INT NOT NULL,
    candidate_id INT NOT NULL,

    personal_status ENUM(
        'Interested',
        'Applied',
        'Follow Up',
        'Interview',
        'Offer',
        'Closed'
    ) NOT NULL DEFAULT 'Applied',

    notes TEXT NULL,

    follow_up_date DATE NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_candidate_application (
        application_id,
        candidate_id
    ),

    INDEX idx_tracker_candidate (
        candidate_id
    ),

    INDEX idx_tracker_follow_up (
        follow_up_date
    ),

    CONSTRAINT fk_tracker_application
        FOREIGN KEY (application_id)
        REFERENCES job_applications(id)
        ON DELETE CASCADE
);