<?php
/**
 * NovaHire AI — Job Matching Engine
 *
 * Computes a 0–100 match score between a candidate's profile and a job,
 * utilizing the AI Skills Taxonomy for precision matching and missing-skill penalties.
 */

if (defined('AI_MATCHING_LOADED')) return;
define('AI_MATCHING_LOADED', true);

require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/skills_taxonomy.php';

/**
 * @param array  $user  user_info row (user_skills, user_degree, experience, about_me)
 * @param array  $job   company_jobs row (job_category, skills_required, requirements,
 *                      experience_required, job_title, job_description, responsibilities)
 * @return array{score:int, matched_skills:array, missing_required:array, missing_preferred:array, 
 *               experience:array, category:array, education:array, breakdown:array, 
 *               label:string, label_color:string, explanation_points:array, llm_summary:string}
 */
function ai_match_profile_job($user, $job) {
    // 1. Normalize Skills
    $user_skills_canon = ai_skills_normalize_list($user['user_skills'] ?? '');
    $job_listed_skills = ai_skills_normalize_list($job['skills_required'] ?? '');

    // Attempt to extract additional required vs preferred skills from job requirements text
    $req_text = strtolower($job['requirements'] ?? '');
    
    // We treat the explicitly listed 'skills_required' as Required.
    $required_skills = $job_listed_skills;
    $preferred_skills = [];
    
    // Simple heuristic: if 'preferred', 'nice to have', 'bonus', 'plus' appears, we could parse skills after it,
    // but without an NLP engine, it's safer to just rely on the taxonomy.
    // Let's filter out soft skills from required skills if possible, or at least mark them.
    $core_technical_required = [];
    foreach ($required_skills as $s) {
        if (!ai_is_soft_skill($s)) {
            $core_technical_required[] = $s;
        }
    }

    // Match skills
    $matched_required = [];
    $matched_preferred = [];
    $missing_required = [];
    
    foreach ($required_skills as $req) {
        if (in_array($req, $user_skills_canon)) {
            $matched_required[] = $req;
        } else {
            $missing_required[] = $req;
        }
    }
    
    // Also, if candidate has other skills that appear in job text, maybe they are preferred?
    $job_text_full = strtolower(
        ($job['job_title'] ?? '') . ' ' .
        ($job['requirements'] ?? '') . ' ' .
        ($job['responsibilities'] ?? '') . ' ' .
        ($job['job_description'] ?? '')
    );
    
    foreach ($user_skills_canon as $us) {
        if (!in_array($us, $matched_required) && !in_array($us, $required_skills)) {
            // Check boundary match in text
            // E.g., \bjava\b
            $escaped = preg_quote(strtolower($us), '/');
            if (preg_match('/\b' . $escaped . '\b/', $job_text_full)) {
                $matched_preferred[] = $us;
            }
        }
    }

    // Skill Score Calculation
    $required_match_ratio = count($required_skills) > 0 ? (count($matched_required) / count($required_skills)) : 1.0;
    
    // Apply a square-root curve to the required match ratio.
    // In real-world hiring, fulfilling 50% of a job's requirements is often a solid match, 
    // rather than a failing grade. sqrt(0.5) gives ~0.70.
    $curved_ratio = sqrt($required_match_ratio);
    
    // Preferred matches act as a bonus (up to 15%)
    $bonus_ratio = count($matched_preferred) * 0.05;
    
    $skill_score_raw = ($curved_ratio + $bonus_ratio) * 100;
    
    $skill_score = min(100, round($skill_score_raw));
    
    // Critical Skill Penalty
    $penalty = 0;
    $core_missing = 0;
    $title_skill_missing = false;
    $job_title_lower = strtolower($job['job_title'] ?? '');

    foreach ($core_technical_required as $core) {
        if (!in_array($core, $matched_required)) {
            $core_missing++;
            if (strlen($core) > 2 && strpos($job_title_lower, strtolower($core)) !== false) {
                $title_skill_missing = true;
            }
        }
    }
    
    if (count($core_technical_required) > 0) {
        if ($core_missing == count($core_technical_required)) {
            // 100% missing core technical skills
            $penalty = 40; 
        } elseif ($core_missing > 0) {
            $penalty = round(20 * ($core_missing / count($core_technical_required)));
        }
    }

    // Heavy penalty for missing a skill that is literally in the job title (e.g. PHP for "PHP Developer")
    if ($title_skill_missing) {
        $penalty += 20;
    }

    // Category fit
    $cat_score = 0;
    $cat_note = 'Category not clearly related to your profile.';
    
    // Infer candidate's main category by extracting keywords from skills
    $user_cat_raw = implode(' ', array_merge($user_skills_canon, [$user['user_degree'] ?? '', $user['experience'] ?? '']));
    $user_cat = ai_detect_category($user_cat_raw);
    
    $job_cat = trim($job['job_category'] ?? '');
    $job_cat_inferred = ai_detect_category($job['skills_required'] ?? '');
    
    $similarity1 = ai_get_category_similarity($user_cat, $job_cat);
    $similarity2 = ai_get_category_similarity($user_cat, $job_cat_inferred);
    $similarity = max($similarity1, $similarity2);
    $cat_score = round($similarity * 100);
    
    if ($similarity >= 0.8) {
        $cat_note = "Strong category match ($job_cat).";
    } elseif ($similarity >= 0.5) {
        $cat_note = "Partial domain match (Job: $job_cat, You: $user_cat).";
    } else {
        $cat_note = "Job is in the $job_cat category but your profile is strongest in $user_cat.";
    }

    // Experience
    $exp_score = 50;
    $exp_note = 'Experience could not be precisely compared.';
    $exp_required = ai_parse_years($job['experience_required'] ?? '');
    $exp_user = ai_parse_years($user['experience'] ?? '');
    
    if ($exp_required !== null) {
        if ($exp_user === null) {
            $exp_score = 50;
            $exp_note = "Job requires $exp_required+ years; update your profile's experience to compare.";
        } elseif ($exp_user >= $exp_required) {
            $exp_score = 100;
            $exp_note = "You have $exp_user+ years of experience, meeting the $exp_required+ years requirement.";
        } elseif ($exp_user >= max(1, $exp_required - 1)) {
            $exp_score = 75;
            $exp_note = "You have $exp_user+ years; the role asks for $exp_required+ years — close enough to apply.";
        } else {
            $exp_score = 20;
            $exp_note = "The role requires $exp_required+ years but you list $exp_user+ years.";
        }
    }

    // Education
    $edu_score = 60;
    $edu_note = 'Education requirement not specified; your degree is a plus.';
    $edu_text = strtolower(trim($user['user_degree'] ?? ''));
    $req_text_edu = strtolower(trim($job['requirements'] ?? ''));
    if ($edu_text === '') {
        $edu_score = 40;
        $edu_note = 'Add your degree to your profile for a better education match.';
    } elseif (strpos($req_text_edu, 'bachelor') !== false || strpos($req_text_edu, 'degree') !== false) {
        if (strpos($edu_text, 'bachelor') !== false || strpos($edu_text, 'bsc') !== false || strpos($edu_text, 'bs ') !== false || strpos($edu_text, 'computer science') !== false || strpos($edu_text, 'b.sc') !== false) {
            $edu_score = 100;
            $edu_note = 'Your degree satisfies the education requirement.';
        } else {
            $edu_score = 60;
            $edu_note = 'A degree is preferred; yours is listed and may qualify.';
        }
    } elseif (strpos($req_text_edu, 'master') !== false) {
        if (strpos($edu_text, 'master') !== false || strpos($edu_text, 'msc') !== false || strpos($edu_text, 'm.sc') !== false) {
            $edu_score = 100;
            $edu_note = 'Your postgraduate degree matches the requirement.';
        } else {
            $edu_score = 50;
            $edu_note = 'A master\'s degree is preferred for this role.';
        }
    } elseif ($edu_text !== '') {
        $edu_score = 100;
        $edu_note = 'No strict degree requirement; your qualifications are sufficient.';
    }

    // Final weighted score
    // Updated weights based on plan: required/preferred calculation is already inside skill_score
    // Let's use 60% skills, 15% category, 15% experience, 10% education
    $raw_score = (
        $skill_score * 0.60 +
        $cat_score   * 0.15 +
        $exp_score   * 0.15 +
        $edu_score   * 0.10
    );
    
    $score = (int)round($raw_score - $penalty);
    
    // Hard cap if critical missing
    if ($penalty == 40 && $score > 35) {
        $score = 35;
    }
    
    $score = max(0, min(100, $score));

    $label = ai_readiness_label($score);

    // Explainable points
    $explanation_points = [];
    if (count($required_skills) > 0) {
        $explanation_points[] = "✓ " . count($matched_required) . "/" . count($required_skills) . " required skills matched (" . implode(', ', array_slice(array_merge($matched_required, ['none']), 0, 3)) . ")";
    }
    $explanation_points[] = $cat_score >= 80 ? "✓ " . $cat_note : "• " . $cat_note;
    $explanation_points[] = $exp_score >= 75 ? "✓ " . $exp_note : "• " . $exp_note;
    
    if (count($missing_required) > 0) {
        $explanation_points[] = "• Missing required skills: " . implode(', ', array_slice($missing_required, 0, 3));
    }
    if ($title_skill_missing) {
        $explanation_points[] = '⚠ Heavy penalty applied: You are missing a critical skill required in the job title.';
    } elseif ($penalty > 0) {
        $explanation_points[] = '⚠ Penalty applied for missing core technical skills.';
    }

    // Log to DB if ai_recommendations is available
    global $conn;
    if (isset($conn) && isset($user['id']) && isset($job['id'])) {
        $user_id = (int)$user['id'];
        $job_id = (int)$job['id'];
        // Quick insert/update to log the recommendation (if table exists)
        $log_sql = "INSERT INTO ai_recommendations (seeker_id, job_id, match_score, matching_skills, missing_skills, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE match_score = VALUES(match_score), matching_skills = VALUES(matching_skills), missing_skills = VALUES(missing_skills), created_at = NOW()";
        if ($stmt = $conn->prepare($log_sql)) {
            $ms_json = json_encode(array_merge($matched_required, $matched_preferred));
            $mis_json = json_encode($missing_required);
            $stmt->bind_param("iiiss", $user_id, $job_id, $score, $ms_json, $mis_json);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Optional LLM summary
    $llm_summary = '';
    if (ai_llm_available()) {
        $system = 'You are a concise career coach for a job portal. Reply in 2–3 short sentences maximum, plain text.';
        $prompt = "Candidate skills: " . implode(', ', $user_skills_canon) . "\n"
                . "Candidate degree: {$user['user_degree']}\n"
                . "Job title: {$job['job_title']}\n"
                . "Job category: {$job['job_category']}\n"
                . "Match score: {$score}%\n"
                . "Matched skills: " . implode(', ', array_merge($matched_required, $matched_preferred)) . "\n"
                . "Missing skills: " . implode(', ', $missing_required) . "\n"
                . "Give the candidate one actionable tip to improve their chance.";
        $res = ai_llm_chat($system, $prompt, 200);
        if ($res['ok']) $llm_summary = $res['text'];
    }

    $all_matched = array_merge($matched_required, $matched_preferred);

    return [
        'score'              => $score,
        'matched_skills'     => $all_matched,
        'missing_required'   => $missing_required,
        'missing_preferred'  => [], // We don't have explicit missing preferred right now
        'experience'         => ['score' => $exp_score, 'note' => $exp_note, 'required' => $exp_required, 'user' => $exp_user],
        'category'           => ['score' => $cat_score, 'note' => $cat_note],
        'education'          => ['score' => $edu_score, 'note' => $edu_note],
        'breakdown'          => [
                                  'required_skills' => $required_match_ratio * 100, 
                                  'preferred_skills' => $bonus_ratio * 100,
                                  'category' => $cat_score, 
                                  'experience' => $exp_score, 
                                  'education' => $edu_score,
                                  'penalty' => $penalty
                                ],
        'label'              => $label[0],
        'label_color'        => $label[1],
        'explanation_points' => $explanation_points,
        'llm_summary'        => $llm_summary,
    ];
}

/**
 * Rank a list of jobs against a user profile.
 * @param array $user
 * @param array $jobs list of company_jobs rows
 * @return array sorted list with 'ai' match data attached
 */
function ai_rank_jobs($user, $jobs) {
    $out = [];
    foreach ($jobs as $job) {
        $ai = ai_match_profile_job($user, $job);
        $job['ai'] = $ai;
        $out[] = $job;
    }
    usort($out, function ($a, $b) {
        return $b['ai']['score'] <=> $a['ai']['score'];
    });
    return $out;
}
