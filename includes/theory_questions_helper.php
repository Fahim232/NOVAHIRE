<?php
/**
 * NovaHire — Theory/Short-Answer Questions Helper
 *
 * Auto-provisions the two new tables on first use (same pattern as
 * ensure_notifications_schema in includes/functions.php) and provides
 * CRUD + scoring helpers consumed by company & seeker pages.
 *
 * Usage: require_once __DIR__ . '/../includes/theory_questions_helper.php';
 *        (assumes $con is already available)
 */

if (defined('THEORY_HELPER_LOADED')) return;
define('THEORY_HELPER_LOADED', true);

if (!isset($con)) {
    include __DIR__ . '/../admin/dbcon.php';
}

/* ════════════════════════════════════════════════════════════════
   AUTO-PROVISION TABLES
   ════════════════════════════════════════════════════════════════ */

function ensure_theory_tables($con) {
    if (!$con) return false;

    // ── company_job_theory_questions ──
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'company_job_theory_questions'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($con, "
            CREATE TABLE IF NOT EXISTS `company_job_theory_questions` (
                `id`               INT(11)    NOT NULL AUTO_INCREMENT,
                `job_id`           INT(11)    NOT NULL,
                `question`         TEXT       NOT NULL,
                `reference_answer` TEXT       DEFAULT NULL,
                `max_score`        INT(11)    NOT NULL DEFAULT 10,
                `required`         TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`       TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_job_id` (`job_id`),
                FOREIGN KEY (`job_id`) REFERENCES `company_jobs`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // ── job_theory_answers ──
    $check2 = @mysqli_query($con, "SHOW TABLES LIKE 'job_theory_answers'");
    if ($check2 && mysqli_num_rows($check2) === 0) {
        mysqli_query($con, "
            CREATE TABLE IF NOT EXISTS `job_theory_answers` (
                `id`              INT(11)       NOT NULL AUTO_INCREMENT,
                `application_id`  INT(11)       NOT NULL,
                `job_id`          INT(11)       NOT NULL,
                `user_id`         INT(11)       NOT NULL,
                `question_id`     INT(11)       NOT NULL,
                `question_text`   TEXT          NOT NULL,
                `answer`          TEXT          NOT NULL,
                `ai_score`        DECIMAL(5,2)  DEFAULT NULL,
                `ai_feedback`     TEXT          DEFAULT NULL,
                `grading_method`  ENUM('ai','rule_based','pending') NOT NULL DEFAULT 'pending',
                `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_app_id`  (`application_id`),
                KEY `idx_job_id`  (`job_id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_q_id`    (`question_id`),
                FOREIGN KEY (`application_id`) REFERENCES `job_applications`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`)        REFERENCES `user_info`(`id`)        ON DELETE CASCADE,
                FOREIGN KEY (`question_id`)    REFERENCES `company_job_theory_questions`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    return true;
}

// Run on include
ensure_theory_tables($con);

/* ════════════════════════════════════════════════════════════════
   CRUD — THEORY QUESTIONS
   ════════════════════════════════════════════════════════════════ */

/**
 * Get all theory questions for a job.
 * @return array
 */
function get_theory_questions($con, $job_id) {
    $job_id = intval($job_id);
    $result = mysqli_query($con, "SELECT * FROM company_job_theory_questions WHERE job_id = $job_id ORDER BY id ASC");
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Count theory questions for a job.
 */
function count_theory_questions($con, $job_id) {
    $job_id = intval($job_id);
    $result = mysqli_query($con, "SELECT COUNT(*) as cnt FROM company_job_theory_questions WHERE job_id = $job_id");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return intval($row['cnt']);
    }
    return 0;
}

/**
 * Add a theory question.
 */
function add_theory_question($con, $job_id, $question, $reference_answer, $max_score = 10, $required = 1) {
    $job_id   = intval($job_id);
    $question = mysqli_real_escape_string($con, trim($question));
    $ref_ans  = mysqli_real_escape_string($con, trim($reference_answer));
    $max_s    = intval($max_score);
    $req      = intval($required) ? 1 : 0;

    return mysqli_query($con, "INSERT INTO company_job_theory_questions
        (job_id, question, reference_answer, max_score, required)
        VALUES ($job_id, '$question', '$ref_ans', $max_s, $req)");
}

/**
 * Update a theory question (only if it belongs to the right job).
 */
function update_theory_question($con, $id, $job_id, $question, $reference_answer, $max_score = 10, $required = 1) {
    $id       = intval($id);
    $job_id   = intval($job_id);
    $question = mysqli_real_escape_string($con, trim($question));
    $ref_ans  = mysqli_real_escape_string($con, trim($reference_answer));
    $max_s    = intval($max_score);
    $req      = intval($required) ? 1 : 0;

    return mysqli_query($con, "UPDATE company_job_theory_questions
        SET question = '$question', reference_answer = '$ref_ans', max_score = $max_s, required = $req
        WHERE id = $id AND job_id = $job_id");
}

/**
 * Delete a theory question (only if it belongs to the right job).
 */
function delete_theory_question($con, $id, $job_id) {
    $id     = intval($id);
    $job_id = intval($job_id);
    return mysqli_query($con, "DELETE FROM company_job_theory_questions WHERE id = $id AND job_id = $job_id");
}

/* ════════════════════════════════════════════════════════════════
   CRUD — THEORY ANSWERS
   ════════════════════════════════════════════════════════════════ */

/**
 * Get all theory answers for an application.
 */
function get_theory_answers($con, $application_id) {
    $app_id = intval($application_id);
    $result = mysqli_query($con, "SELECT * FROM job_theory_answers WHERE application_id = $app_id ORDER BY id ASC");
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Get theory answers for a user+job combo (when application_id not yet known).
 */
function get_theory_answers_by_user_job($con, $user_id, $job_id) {
    $user_id = intval($user_id);
    $job_id  = intval($job_id);
    $result  = mysqli_query($con, "SELECT * FROM job_theory_answers WHERE user_id = $user_id AND job_id = $job_id ORDER BY id ASC");
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Save a single theory answer.
 */
function save_theory_answer($con, $application_id, $job_id, $user_id, $question_id, $question_text, $answer) {
    $app_id  = intval($application_id);
    $job_id  = intval($job_id);
    $user_id = intval($user_id);
    $q_id    = intval($question_id);
    $q_text  = mysqli_real_escape_string($con, $question_text);
    // Sanitize answer: strip tags, htmlspecialchars, trim, truncate
    $answer  = trim(strip_tags($answer));
    $answer  = mb_substr($answer, 0, 1000);
    $answer  = mysqli_real_escape_string($con, $answer);

    return mysqli_query($con, "INSERT INTO job_theory_answers
        (application_id, job_id, user_id, question_id, question_text, answer, grading_method)
        VALUES ($app_id, $job_id, $user_id, $q_id, '$q_text', '$answer', 'pending')");
}

/**
 * Update the grading result for a theory answer.
 */
function update_theory_grading($con, $answer_id, $score, $feedback, $method) {
    $id       = intval($answer_id);
    $score    = floatval($score);
    $feedback = mysqli_real_escape_string($con, $feedback);
    $method   = in_array($method, ['ai', 'rule_based']) ? $method : 'rule_based';

    return mysqli_query($con, "UPDATE job_theory_answers
        SET ai_score = $score, ai_feedback = '$feedback', grading_method = '$method'
        WHERE id = $id");
}

/* ════════════════════════════════════════════════════════════════
   SCORING
   ════════════════════════════════════════════════════════════════ */

/**
 * Calculate theory score for an application.
 *
 * @return array ['earned' => float, 'total' => int, 'percentage' => float, 'count' => int]
 */
function calculate_theory_score($con, $application_id) {
    $app_id = intval($application_id);
    $result = mysqli_query($con, "
        SELECT jta.ai_score, cjtq.max_score
        FROM job_theory_answers jta
        JOIN company_job_theory_questions cjtq ON jta.question_id = cjtq.id
        WHERE jta.application_id = $app_id AND jta.grading_method != 'pending'
    ");

    $earned = 0;
    $total  = 0;
    $count  = 0;

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $earned += floatval($row['ai_score']);
            $total  += intval($row['max_score']);
            $count++;
        }
    }

    $pct = ($total > 0) ? round(($earned / $total) * 100, 1) : 0;

    return [
        'earned'     => round($earned, 1),
        'total'      => $total,
        'percentage' => $pct,
        'count'      => $count,
    ];
}

/**
 * Calculate the combined final score from MCQ and Theory components.
 *
 * Weights: 50% MCQ + 50% Theory (when both present).
 * Falls back to whichever is available when only one type exists.
 *
 * @param float|null $mcq_percentage    MCQ score (0-100) or null if no MCQs
 * @param float|null $theory_percentage Theory score (0-100) or null if no theory Qs
 * @return array ['final' => float, 'mcq' => float|null, 'theory' => float|null, 'has_mcq' => bool, 'has_theory' => bool, 'label' => string, 'color' => string]
 */
function calculate_final_score($mcq_percentage, $theory_percentage) {
    $has_mcq    = ($mcq_percentage !== null && $mcq_percentage !== false);
    $has_theory = ($theory_percentage !== null && $theory_percentage !== false);

    $mcq_val    = $has_mcq    ? floatval($mcq_percentage)    : 0;
    $theory_val = $has_theory ? floatval($theory_percentage) : 0;

    if ($has_mcq && $has_theory) {
        $final = ($mcq_val * 0.5) + ($theory_val * 0.5);
    } elseif ($has_mcq) {
        $final = $mcq_val;
    } elseif ($has_theory) {
        $final = $theory_val;
    } else {
        $final = 0;
    }

    $final = round($final, 1);

    // Grade label + color
    if ($final >= 85) {
        $label = 'Excellent';
        $color = '#059669';
    } elseif ($final >= 70) {
        $label = 'Good';
        $color = '#2563eb';
    } elseif ($final >= 50) {
        $label = 'Fair';
        $color = '#d97706';
    } else {
        $label = 'Needs Improvement';
        $color = '#dc2626';
    }

    return [
        'final'      => $final,
        'mcq'        => $has_mcq    ? round($mcq_val, 1)    : null,
        'theory'     => $has_theory ? round($theory_val, 1) : null,
        'has_mcq'    => $has_mcq,
        'has_theory' => $has_theory,
        'label'      => $label,
        'color'      => $color,
    ];
}

/**
 * Get a quick label + color for a theory percentage.
 */
function theory_score_badge($percentage) {
    $pct = floatval($percentage);
    if ($pct >= 70) return ['color' => '#059669', 'bg' => 'rgba(5,150,105,0.12)'];
    if ($pct >= 40) return ['color' => '#d97706', 'bg' => 'rgba(217,119,6,0.12)'];
    return ['color' => '#dc2626', 'bg' => 'rgba(220,38,38,0.12)'];
}
