<?php
/**
 * NovaHire AI — CV Screening & Requirement Match Engine
 *
 * Provides:
 * - Application CV data extraction across all 3 CV types:
 *     1. auto_generated (user_info + verified_skills)
 *     2. ai_customized (ai_generated_cvs snapshot)
 *     3. uploaded (PDF / DOCX / TXT in uploads/cv_files/)
 * - Job requirements extraction (Required vs Preferred separation)
 * - Version hashing for both CV content and Job requirements
 * - Structured screening evaluation (hybrid LLM + intelligent deterministic fallback)
 * - 0–100 match scoring (Overall, Skills, Experience, Education, Project Relevance)
 * - Requirement breakdown: MATCHED, NOT_FOUND, UNCLEAR with evidence
 * - Strict schema validation
 */

if (defined('AI_CV_SCREENER_LOADED')) return;
define('AI_CV_SCREENER_LOADED', true);

require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/skills_taxonomy.php';

/**
 * 1. Extract CV Data and Content Hash from an Application
 *
 * @param mysqli $con
 * @param int $application_id
 * @return array{
 *     text: string,
 *     name: string,
 *     email: string,
 *     phone: string,
 *     skills: array,
 *     experience: string,
 *     education: string,
 *     projects: array,
 *     cv_type: string,
 *     cv_reference: string,
 *     hash: string
 * }
 */
function nh_extract_application_cv_data($con, $application_id) {
    $application_id = intval($application_id);

    $sql = "SELECT ja.id, ja.user_id, ja.job_id, ja.company_id, ja.cv_type, ja.cv_file, ja.ai_cv_id,
                   ui.username, ui.email, ui.phone, ui.address, ui.user_degree, ui.user_skills,
                   ui.experience, ui.about_me, ui.profile
            FROM job_applications ja
            JOIN user_info ui ON ja.user_id = ui.id
            WHERE ja.id = $application_id";
    $res = mysqli_query($con, $sql);
    if (!$res || mysqli_num_rows($res) === 0) {
        return [
            'text' => '', 'name' => '', 'email' => '', 'phone' => '', 'skills' => [],
            'experience' => '', 'education' => '', 'projects' => [],
            'cv_type' => 'auto_generated', 'cv_reference' => '', 'hash' => md5('')
        ];
    }

    $app = mysqli_fetch_assoc($res);
    $user_id = intval($app['user_id']);
    $cv_type = !empty($app['cv_type']) ? $app['cv_type'] : 'auto_generated';

    $name = trim((string)($app['username'] ?? ''));
    $email = trim((string)($app['email'] ?? ''));
    $phone = trim((string)($app['phone'] ?? ''));
    $address = trim((string)($app['address'] ?? ''));
    $education = trim((string)($app['user_degree'] ?? ''));
    $experience = trim((string)($app['experience'] ?? ''));
    $about = trim((string)($app['about_me'] ?? ''));
    $projects = [];
    $skills = [];
    $cv_reference = '';
    $extracted_file_text = '';

    // Fetch verified skills for seeker
    $ver_skills = [];
    $vs_query = mysqli_query($con, "SELECT s.name, vs.skill_level FROM verified_skills vs JOIN skills s ON vs.skill_id = s.id WHERE vs.user_id = $user_id");
    if ($vs_query) {
        while ($vs = mysqli_fetch_assoc($vs_query)) {
            $ver_skills[] = $vs['name'] . ' (' . $vs['skill_level'] . ')';
        }
    }

    if ($cv_type === 'ai_customized' && !empty($app['ai_cv_id'])) {
        // Source 1: AI Generated CV Snapshot
        $ai_cv_id = intval($app['ai_cv_id']);
        $cv_reference = 'ai_cv_' . $ai_cv_id;
        $cv_res = mysqli_query($con, "SELECT * FROM ai_generated_cvs WHERE id = $ai_cv_id");
        if ($cv_res && mysqli_num_rows($cv_res) > 0) {
            $cv_row = mysqli_fetch_assoc($cv_res);
            if (!empty($cv_row['full_name'])) $name = $cv_row['full_name'];
            if (!empty($cv_row['headline'])) $about = $cv_row['headline'] . '. ' . $about;
            if (!empty($cv_row['summary'])) $about = $cv_row['summary'] . "\n" . $about;
            
            $skills_decoded = json_decode($cv_row['skills_json'] ?? '[]', true) ?: [];
            $skills = array_filter(array_map('trim', $skills_decoded));
            
            $exp_decoded = json_decode($cv_row['experience_json'] ?? '[]', true) ?: [];
            if (!empty($exp_decoded)) {
                $exp_parts = [];
                foreach ($exp_decoded as $ex) {
                    $t = trim(($ex['title'] ?? '') . ' at ' . ($ex['company'] ?? '') . ' (' . ($ex['duration'] ?? '') . '): ' . ($ex['description'] ?? ''));
                    if ($t !== '') $exp_parts[] = $t;
                }
                $experience = implode("\n", $exp_parts);
            }

            $edu_decoded = json_decode($cv_row['education_json'] ?? '[]', true) ?: [];
            if (!empty($edu_decoded)) {
                $edu_parts = [];
                foreach ($edu_decoded as $ed) {
                    $edu_parts[] = trim(($ed['degree'] ?? '') . ' - ' . ($ed['institution'] ?? '') . ' (' . ($ed['year'] ?? '') . ')');
                }
                $education = implode("\n", $edu_parts);
            }

            $projects_decoded = json_decode($cv_row['projects_json'] ?? '[]', true) ?: [];
            if (!empty($projects_decoded)) {
                $projects = $projects_decoded;
            }

            if (!empty($cv_row['full_content'])) {
                $extracted_file_text = trim($cv_row['full_content']);
            }

            if (empty($skills)) {
                $prof_skills = array_filter(array_map('trim', explode(',', $app['user_skills'] ?? '')));
                $skills = $prof_skills;
            }
        }
    } elseif ($cv_type === 'uploaded' && !empty($app['cv_file'])) {
        // Source 2: Uploaded CV file
        $cv_reference = $app['cv_file'];
        $file_path = __DIR__ . '/../uploads/cv_files/' . basename($app['cv_file']);
        if (!file_exists($file_path) && !empty($app['profile'])) {
            $file_path = __DIR__ . '/../files/' . basename($app['profile']);
            $cv_reference = $app['profile'];
        }

        if (file_exists($file_path)) {
            $extracted_file_text = nh_extract_text_from_file($file_path);
        }
        
        // Combine with user profile skills as baseline
        $prof_skills = array_filter(array_map('trim', explode(',', $app['user_skills'] ?? '')));
        $skills = $prof_skills;
    } else {
        // Source 3: Auto-generated from user profile
        $cv_reference = 'user_profile_' . $user_id;
        $prof_skills = array_filter(array_map('trim', explode(',', $app['user_skills'] ?? '')));
        $skills = $prof_skills;
    }

    // Merge verified skills into skills list
    foreach ($ver_skills as $vs_item) {
        if (!in_array($vs_item, $skills)) {
            $skills[] = $vs_item;
        }
    }

    // Build consolidated searchable text representation
    $text_blocks = [];
    $text_blocks[] = "Candidate: $name";
    if ($email) $text_blocks[] = "Email: $email";
    if ($phone) $text_blocks[] = "Phone: $phone";
    if ($address) $text_blocks[] = "Location: $address";
    if ($education) $text_blocks[] = "Education: $education";
    if (!empty($skills)) $text_blocks[] = "Technical & Professional Skills: " . implode(', ', $skills);
    if ($experience) $text_blocks[] = "Work Experience: $experience";
    if ($about) $text_blocks[] = "Summary / Background: $about";

    if (!empty($projects)) {
        $proj_texts = [];
        foreach ($projects as $pj) {
            $proj_texts[] = trim(($pj['title'] ?? '') . ': ' . ($pj['tech'] ?? '') . ' - ' . ($pj['description'] ?? ''));
        }
        $text_blocks[] = "Projects: " . implode("\n", $proj_texts);
    }

    if (!empty($extracted_file_text)) {
        $text_blocks[] = "Uploaded Document Content:\n" . $extracted_file_text;
    }

    $full_text = implode("\n\n", $text_blocks);

    // Compute version hash based on the content fields and file timestamp if uploaded
    $hash_material = $name . '|' . $education . '|' . implode(',', $skills) . '|' . $experience . '|' . $about . '|' . serialize($projects) . '|' . $extracted_file_text;
    if ($cv_type === 'uploaded' && !empty($app['cv_file'])) {
        $f_path = __DIR__ . '/../uploads/cv_files/' . basename($app['cv_file']);
        if (file_exists($f_path)) {
            $hash_material .= '|' . filemtime($f_path) . '|' . filesize($f_path);
        }
    }
    $cv_hash = hash('sha256', $hash_material);

    return [
        'text'         => $full_text,
        'name'         => $name,
        'email'        => $email,
        'phone'        => $phone,
        'skills'       => array_values(array_unique($skills)),
        'experience'   => $experience,
        'education'    => $education,
        'projects'     => $projects,
        'cv_type'      => $cv_type,
        'cv_reference' => $cv_reference,
        'hash'         => $cv_hash
    ];
}

/**
 * Controlled document text extractor (TXT, DOCX, PDF, etc.)
 *
 * @param string $path Absolute file path
 * @return string Extracted plain text
 */
function nh_extract_text_from_file($path) {
    if (!file_exists($path) || !is_readable($path)) {
        return '';
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // Plain text formats
    if (in_array($ext, ['txt', 'csv', 'md', 'json'])) {
        $content = @file_get_contents($path);
        return is_string($content) ? trim($content) : '';
    }

    // DOCX format (ZIP archive containing word/document.xml)
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml) {
                // Replace XML tags and paragraph tags with newlines
                $xml = preg_replace('/<\/w:p>/', "\n", $xml);
                $text = strip_tags($xml);
                return trim(preg_replace('/[ \t]+/', ' ', $text));
            }
        }
    }

    // PDF format (native stream parsing without external dependencies)
    if ($ext === 'pdf') {
        $raw = @file_get_contents($path);
        if ($raw) {
            $text = '';
            // 1. Extract text from text blocks BT ... ET
            if (preg_match_all('/BT[\s\S]*?ET/', $raw, $matches)) {
                foreach ($matches[0] as $block) {
                    if (preg_match_all('/\((.*?)\)\s*T[jJ]/s', $block, $t_matches)) {
                        $text .= implode(' ', $t_matches[1]) . "\n";
                    } elseif (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $arr_matches)) {
                        foreach ($arr_matches[1] as $arr_item) {
                            if (preg_match_all('/\((.*?)\)/s', $arr_item, $str_matches)) {
                                $text .= implode('', $str_matches[1]) . ' ';
                            }
                        }
                        $text .= "\n";
                    }
                }
            }
            
            // 2. Fallback: extract streams with FlateDecode if zlib available
            if (strlen(trim($text)) < 50 && function_exists('gzuncompress')) {
                if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $raw, $stream_matches)) {
                    foreach ($stream_matches[1] as $stream) {
                        $uncompressed = @gzuncompress($stream);
                        if ($uncompressed) {
                            if (preg_match_all('/\((.*?)\)\s*T[jJ]/s', $uncompressed, $sub_m)) {
                                $text .= implode(' ', $sub_m[1]) . "\n";
                            }
                        }
                    }
                }
            }

            if (strlen(trim($text)) > 20) {
                // Unescape PDF octal and backslashes
                $text = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $text);
                return trim($text);
            }
        }
    }

    return '';
}

/**
 * 2. Extract Job Requirements, distinguishing Required vs Preferred
 *
 * @param mysqli $con
 * @param int $job_id
 * @return array{
 *     job_title: string,
 *     job_category: string,
 *     experience_required: string,
 *     education_required: string,
 *     required_skills: array,
 *     preferred_skills: array,
 *     requirements_text: string,
 *     responsibilities_text: string,
 *     hash: string
 * }
 */
function nh_extract_job_screening_requirements($con, $job_id) {
    $job_id = intval($job_id);
    $res = mysqli_query($con, "SELECT * FROM company_jobs WHERE id = $job_id");
    if (!$res || mysqli_num_rows($res) === 0) {
        return [
            'job_title' => '', 'job_category' => '', 'experience_required' => '',
            'education_required' => '', 'required_skills' => [], 'preferred_skills' => [],
            'requirements_text' => '', 'responsibilities_text' => '', 'hash' => md5('')
        ];
    }

    $job = mysqli_fetch_assoc($res);

    $job_title = trim((string)($job['job_title'] ?? ''));
    $job_category = trim((string)($job['job_category'] ?? ''));
    $exp_required = trim((string)($job['experience_required'] ?? ''));
    $skills_required_raw = trim((string)($job['skills_required'] ?? ''));
    $requirements_raw = trim((string)($job['requirements'] ?? ''));
    $responsibilities_raw = trim((string)($job['responsibilities'] ?? ''));

    // Check job_required_skills table for additional structured skills
    $structured_req_skills = [];
    $jrs_res = mysqli_query($con, "SELECT s.name FROM job_required_skills jrs JOIN skills s ON jrs.skill_id = s.id WHERE jrs.job_id = $job_id");
    if ($jrs_res) {
        while ($j = mysqli_fetch_assoc($jrs_res)) {
            $structured_req_skills[] = trim($j['name']);
        }
    }

    // Split skills_required field into tokens
    $skills_tokens = array_filter(array_map('trim', preg_split('/[,\n;]/', $skills_required_raw)));
    foreach ($structured_req_skills as $st) {
        if (!in_array($st, $skills_tokens)) {
            $skills_tokens[] = $st;
        }
    }

    // Parse requirements text for "Preferred", "Nice to have", "Bonus" section vs Required
    $required_skills = [];
    $preferred_skills = [];
    $education_req = '';

    // Check if requirements text has explicit Preferred section
    $lines = preg_split('/\r\n|\r|\n/', $requirements_raw);
    $in_preferred_section = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;

        $lower = strtolower($trimmed);

        // Detect education requirements in lines
        if (preg_match('/(bachelor|master|degree|bsc|msc|phd|diploma|computer science|cse|software engineering)/i', $trimmed) && empty($education_req)) {
            $education_req = preg_replace('/^[-*•\s]+/', '', $trimmed);
        }

        // Section toggle
        if (preg_match('/^(preferred|nice to have|bonus|plus|desirable)/i', $lower)) {
            $in_preferred_section = true;
            continue;
        } elseif (preg_match('/^(required|mandatory|minimum qualifications|qualifications|requirements)/i', $lower)) {
            $in_preferred_section = false;
            continue;
        }

        // Clean bullet points
        $clean_item = trim(preg_replace('/^[-*•\d\.\)]+\s*/', '', $trimmed));
        if ($clean_item === '') continue;

        if ($in_preferred_section) {
            // Extract individual skills or phrases
            $sub_items = preg_split('/[,;]/', $clean_item);
            foreach ($sub_items as $si) {
                $si = trim($si);
                // Strip common filler descriptions to isolate the core skill (e.g. "Docker containerization" -> "Docker")
                $cleaned_skill = trim(preg_replace('/\b(containerization|cloud experience|cloud|version control|experience|proficiency|skills|knowledge|expertise|familiarity)\b/i', '', $si));
                $cleaned_skill = trim(preg_replace('/[-–:]+$/', '', $cleaned_skill));
                $target = (!empty($cleaned_skill) && strlen($cleaned_skill) >= 2) ? $cleaned_skill : $si;
                if (strlen($target) > 1 && !in_array($target, $preferred_skills)) {
                    $preferred_skills[] = $target;
                }
            }
        }
    }

    // Categorize skills listed in skills_required
    // Known secondary/preferred tools if placed in job text with "preferred"
    $preferred_lowers = array_map('strtolower', $preferred_skills);

    foreach ($skills_tokens as $sk) {
        $sk_lower = strtolower($sk);
        if (in_array($sk_lower, $preferred_lowers)) {
            if (!in_array($sk, $preferred_skills)) $preferred_skills[] = $sk;
        } else {
            if (!in_array($sk, $required_skills)) $required_skills[] = $sk;
        }
    }

    // Default education requirement if none found in text
    if (empty($education_req)) {
        $education_req = "Bachelor's degree in Computer Science, Software Engineering, or related field";
    }

    // Hash for detecting job requirement changes
    $job_hash_material = $job_title . '|' . $exp_required . '|' . $education_req . '|' . implode(',', $required_skills) . '|' . implode(',', $preferred_skills) . '|' . $requirements_raw . '|' . $skills_required_raw;
    $job_req_hash = hash('sha256', $job_hash_material);

    return [
        'job_title'             => $job_title,
        'job_category'          => $job_category,
        'experience_required'   => $exp_required,
        'education_required'    => $education_req,
        'required_skills'       => array_values(array_unique($required_skills)),
        'preferred_skills'      => array_values(array_unique($preferred_skills)),
        'requirements_text'     => $requirements_raw,
        'responsibilities_text' => $responsibilities_raw,
        'hash'                  => $job_req_hash
    ];
}

/**
 * 3. Validate AI CV Screening JSON Payload
 *
 * @param array $payload
 * @return array{valid: bool, error: ?string}
 */
function nh_validate_ai_cv_analysis_payload($payload) {
    if (!is_array($payload)) {
        return ['valid' => false, 'error' => 'Payload must be an array.'];
    }

    $required_keys = [
        'overall_match_score',
        'skills_match_score',
        'experience_match_score',
        'education_match_score',
        'project_relevance_score',
        'required_requirements',
        'preferred_requirements',
        'strengths',
        'gaps',
        'evidence',
        'summary'
    ];

    foreach ($required_keys as $k) {
        if (!array_key_exists($k, $payload)) {
            return ['valid' => false, 'error' => "Missing required field: $k"];
        }
    }

    // Validate scores are numeric and within 0–100
    $score_keys = [
        'overall_match_score',
        'skills_match_score',
        'experience_match_score',
        'education_match_score',
        'project_relevance_score'
    ];

    foreach ($score_keys as $sk) {
        if (!is_numeric($payload[$sk])) {
            return ['valid' => false, 'error' => "$sk must be numeric."];
        }
        $val = floatval($payload[$sk]);
        if ($val < 0 || $val > 100) {
            return ['valid' => false, 'error' => "$sk ($val) must be between 0 and 100."];
        }
    }

    // Validate requirements structure
    foreach (['required_requirements', 'preferred_requirements'] as $rq) {
        if (!is_array($payload[$rq])) {
            return ['valid' => false, 'error' => "$rq must be an associative array."];
        }
        foreach (['matched', 'missing', 'unclear'] as $sub) {
            if (!isset($payload[$rq][$sub]) || !is_array($payload[$rq][$sub])) {
                return ['valid' => false, 'error' => "$rq.$sub must be an array."];
            }
            foreach ($payload[$rq][$sub] as $item) {
                if (!isset($item['name']) || !isset($item['status']) || !isset($item['evidence'])) {
                    return ['valid' => false, 'error' => "Each item in $rq.$sub must contain 'name', 'status', and 'evidence'."];
                }
                if (!in_array($item['status'], ['MATCHED', 'NOT_FOUND', 'UNCLEAR'], true)) {
                    return ['valid' => false, 'error' => "Invalid status '{$item['status']}' in $rq.$sub. Must be MATCHED, NOT_FOUND, or UNCLEAR."];
                }
            }
        }
    }

    // Validate arrays
    if (!is_array($payload['strengths']) || !is_array($payload['gaps']) || !is_array($payload['evidence'])) {
        return ['valid' => false, 'error' => 'strengths, gaps, and evidence must be arrays.'];
    }

    // Validate summary is text
    if (!is_string($payload['summary']) || trim($payload['summary']) === '') {
        return ['valid' => false, 'error' => 'summary must be a non-empty string.'];
    }

    return ['valid' => true, 'error' => null];
}

/**
 * 4. Core AI CV Screening Engine (Hybrid: LLM with Fallback to Deterministic Matcher)
 *
 * @param array $cv_data Extracted CV data
 * @param array $job_reqs Extracted Job requirements
 * @return array Structured screening result
 */
function nh_evaluate_cv_screening($cv_data, $job_reqs) {
    // ── ATTEMPT LLM GENERATION IF AVAILABLE ──
    if (ai_llm_available()) {
        $llm_result = nh_evaluate_cv_screening_llm($cv_data, $job_reqs);
        if ($llm_result && ($val = nh_validate_ai_cv_analysis_payload($llm_result))['valid']) {
            $llm_result['model'] = ai_provider_label();
            return $llm_result;
        }
    }

    // ── DETERMINISTIC RULE-BASED SCREENING EVALUATOR ──
    return nh_evaluate_cv_screening_rule_based($cv_data, $job_reqs);
}

/**
 * LLM Screening Prompt Executor
 */
function nh_evaluate_cv_screening_llm($cv_data, $job_reqs) {
    $system = "You are an elite candidate screening AI for NovaHire. You evaluate a candidate's CV against specific job requirements.\n"
            . "CRITICAL INSTRUCTIONS:\n"
            . "1. Analyze ONLY the candidate CV and Job requirements provided. NEVER invent skills, years, degrees, or experience.\n"
            . "2. If a requirement is not mentioned, mark it NOT_FOUND with evidence 'Not mentioned in provided CV'.\n"
            . "3. If a requirement is partially or vaguely mentioned, mark it UNCLEAR with evidence.\n"
            . "4. If verified evidence exists in the CV, mark it MATCHED with clear evidence quoting or summarizing the CV.\n"
            . "5. You MUST return ONLY valid JSON matching this exact structure without markdown code blocks, preamble, or comments:\n"
            . "{\n"
            . '  "overall_match_score": 85,' . "\n"
            . '  "skills_match_score": 90,' . "\n"
            . '  "experience_match_score": 80,' . "\n"
            . '  "education_match_score": 90,' . "\n"
            . '  "project_relevance_score": 85,' . "\n"
            . '  "required_requirements": {' . "\n"
            . '    "matched": [{"name": "SkillName", "status": "MATCHED", "evidence": "Evidence text"}],' . "\n"
            . '    "missing": [{"name": "SkillName", "status": "NOT_FOUND", "evidence": "Evidence text"}],' . "\n"
            . '    "unclear": [{"name": "SkillName", "status": "UNCLEAR", "evidence": "Evidence text"}]' . "\n"
            . '  },' . "\n"
            . '  "preferred_requirements": {' . "\n"
            . '    "matched": [], "missing": [], "unclear": []' . "\n"
            . '  },' . "\n"
            . '  "strengths": ["point 1", "point 2"],' . "\n"
            . '  "gaps": ["gap 1"],' . "\n"
            . '  "evidence": ["evidence point 1"],' . "\n"
            . '  "summary": "Objective 2-4 sentence screening summary"' . "\n"
            . "}";

    $prompt = "TARGET JOB:\n"
            . "Title: " . $job_reqs['job_title'] . "\n"
            . "Required Experience: " . $job_reqs['experience_required'] . "\n"
            . "Required Education: " . $job_reqs['education_required'] . "\n"
            . "Required Skills: " . implode(', ', $job_reqs['required_skills']) . "\n"
            . "Preferred Skills: " . implode(', ', $job_reqs['preferred_skills']) . "\n"
            . "Full Requirements:\n" . $job_reqs['requirements_text'] . "\n\n"
            . "CANDIDATE CV:\n"
            . "Name: " . $cv_data['name'] . "\n"
            . "Education: " . $cv_data['education'] . "\n"
            . "Skills: " . implode(', ', $cv_data['skills']) . "\n"
            . "Experience: " . $cv_data['experience'] . "\n"
            . "Full CV Content:\n" . ai_clip($cv_data['text'], 3500);

    $res = ai_llm_chat($system, $prompt, 1200);
    if ($res['ok'] && !empty($res['text'])) {
        $cleaned = trim($res['text']);
        // Strip markdown backticks if any
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $cleaned, $m)) {
            $cleaned = trim($m[1]);
        }
        $json = json_decode($cleaned, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return null;
}

/**
 * Deterministic, Evidence-Based Rule Evaluator (100% offline & reproducible)
 */
function nh_evaluate_cv_screening_rule_based($cv_data, $job_reqs) {
    $cv_text_lower = mb_strtolower($cv_data['text']);
    $cv_skills_canon = array_map('strtolower', $cv_data['skills']);
    $cv_exp_text = mb_strtolower($cv_data['experience']);

    // 1. Evaluate Required Requirements
    $req_matched = [];
    $req_missing = [];
    $req_unclear = [];

    foreach ($job_reqs['required_skills'] as $skill) {
        $res = nh_evaluate_single_requirement($skill, $cv_text_lower, $cv_skills_canon, $cv_exp_text);
        if ($res['status'] === 'MATCHED') {
            $req_matched[] = $res;
        } elseif ($res['status'] === 'UNCLEAR') {
            $req_unclear[] = $res;
        } else {
            $req_missing[] = $res;
        }
    }

    // 2. Evaluate Preferred Requirements
    $pref_matched = [];
    $pref_missing = [];
    $pref_unclear = [];

    foreach ($job_reqs['preferred_skills'] as $skill) {
        $res = nh_evaluate_single_requirement($skill, $cv_text_lower, $cv_skills_canon, $cv_exp_text);
        if ($res['status'] === 'MATCHED') {
            $pref_matched[] = $res;
        } elseif ($res['status'] === 'UNCLEAR') {
            $pref_unclear[] = $res;
        } else {
            $pref_missing[] = $res;
        }
    }

    // 3. Compute Skills Match Score (0–100)
    $total_req = count($job_reqs['required_skills']);
    $total_pref = count($job_reqs['preferred_skills']);

    if ($total_req > 0) {
        $req_score = (count($req_matched) + (count($req_unclear) * 0.5)) / $total_req;
        $pref_bonus = $total_pref > 0 ? (count($pref_matched) / $total_pref) * 0.15 : 0;
        $skills_score = min(100, round(($req_score * 0.85 + $pref_bonus) * 100));
    } elseif ($total_pref > 0) {
        $skills_score = min(100, round((count($pref_matched) / $total_pref) * 100));
    } else {
        $skills_score = 75; // Default baseline if no explicit skills required
    }

    // 4. Compute Experience Match Score (0–100)
    $req_years = ai_parse_years($job_reqs['experience_required']);
    $cand_years = ai_parse_years($cv_data['experience']);
    if ($cand_years === null) {
        $cand_years = ai_parse_years($cv_data['text']);
    }

    if ($req_years === null || $req_years <= 0) {
        $exp_score = 85;
        $exp_evidence = "Position does not require extensive prior experience. Candidate meets baseline requirements.";
    } elseif ($cand_years !== null) {
        if ($cand_years >= $req_years) {
            $exp_score = min(100, 85 + min(15, ($cand_years - $req_years) * 5));
            $exp_evidence = "Candidate demonstrates {$cand_years}+ years of experience, meeting the required {$req_years}+ years.";
        } else {
            $ratio = $cand_years / max(1, $req_years);
            $exp_score = max(30, round($ratio * 75));
            $exp_evidence = "Candidate has approximately {$cand_years} year(s) of experience vs. {$req_years}+ years requested.";
        }
    } else {
        $exp_score = 60;
        $exp_evidence = "Specific years of experience could not be definitively extracted from CV text.";
    }

    // 5. Compute Education Match Score (0–100)
    $edu_text = strtolower($cv_data['education']);
    $req_edu_text = strtolower($job_reqs['education_required']);
    $edu_score = 50;
    $edu_evidence = "Candidate educational background recorded: " . ($cv_data['education'] ?: 'Not explicitly stated');

    if (!empty($edu_text)) {
        if (strpos($edu_text, 'phd') !== false || strpos($edu_text, 'doctor') !== false) {
            $edu_score = 95;
            $edu_evidence = "Holds Doctoral / PhD degree: {$cv_data['education']}";
        } elseif (strpos($edu_text, 'master') !== false || strpos($edu_text, 'msc') !== false || strpos($edu_text, 'mba') !== false) {
            $edu_score = 92;
            $edu_evidence = "Holds Master's degree: {$cv_data['education']}";
        } elseif (strpos($edu_text, 'bachelor') !== false || strpos($edu_text, 'bsc') !== false || strpos($edu_text, 'b.') !== false) {
            $edu_score = 88;
            $edu_evidence = "Holds Bachelor's degree: {$cv_data['education']}";
        } elseif (strpos($edu_text, 'diploma') !== false || strpos($edu_text, 'associate') !== false) {
            $edu_score = 75;
            $edu_evidence = "Holds Diploma / Associate degree: {$cv_data['education']}";
        } else {
            $edu_score = 70;
        }

        // Relevant major bonus (CSE, CS, Software Engineering, AI, etc.)
        if (preg_match('/(computer|software|informatics|data|artificial|electronics|electrical)/i', $edu_text)) {
            $edu_score = min(100, $edu_score + 5);
        }
    }

    // 6. Compute Project / Role Relevance Score (0–100)
    $role_keywords = array_filter(explode(' ', strtolower($job_reqs['job_title'] . ' ' . $job_reqs['job_category'])));
    $match_hits = 0;
    foreach ($role_keywords as $kw) {
        if (strlen($kw) >= 3 && strpos($cv_text_lower, $kw) !== false) {
            $match_hits++;
        }
    }
    $project_relevance_score = min(95, max(45, 60 + ($match_hits * 10)));
    if (!empty($cv_data['projects'])) {
        $project_relevance_score = min(100, $project_relevance_score + 10);
    }

    // 7. Overall Match Score (0–100)
    // Formula: 45% Skills + 25% Experience + 15% Education + 15% Project Relevance
    $overall_match = round(
        ($skills_score * 0.45) +
        ($exp_score * 0.25) +
        ($edu_score * 0.15) +
        ($project_relevance_score * 0.15)
    );
    $overall_match = max(0, min(100, $overall_match));

    // 8. Generate Strengths, Gaps, Evidence & Summary
    $strengths = [];
    foreach ($req_matched as $rm) {
        $strengths[] = "Strong verified requirement: {$rm['name']}";
        if (count($strengths) >= 3) break;
    }
    if ($exp_score >= 80) $strengths[] = $exp_evidence;
    if ($edu_score >= 85) $strengths[] = "Meets education criteria ({$cv_data['education']})";

    $gaps = [];
    foreach ($req_missing as $rm) {
        $gaps[] = "Missing required requirement: {$rm['name']}";
    }
    foreach ($pref_missing as $pm) {
        $gaps[] = "Preferred skill not explicitly found: {$pm['name']}";
    }
    if (empty($gaps)) {
        $gaps[] = "No major qualification gaps identified against stated job requirements.";
    }

    $all_evidence = [];
    foreach (array_merge($req_matched, $pref_matched) as $m) {
        $all_evidence[] = "✓ {$m['name']}: {$m['evidence']}";
    }
    foreach (array_merge($req_unclear, $pref_unclear) as $u) {
        $all_evidence[] = "⚠ {$u['name']}: {$u['evidence']}";
    }
    foreach (array_merge($req_missing, $pref_missing) as $mis) {
        $all_evidence[] = "✗ {$mis['name']}: {$mis['evidence']}";
    }

    $summary = "Candidate demonstrates an overall AI CV Match of {$overall_match}% for the {$job_reqs['job_title']} position. "
             . "Matched " . count($req_matched) . " of " . max(1, count($job_reqs['required_skills'])) . " required qualifications "
             . "with " . count($pref_matched) . " preferred skills identified. "
             . ($exp_score >= 75 ? "Experience level aligns well with expectations." : "Experience requirements require targeted interview validation.");

    return [
        'overall_match_score'     => $overall_match,
        'skills_match_score'      => $skills_score,
        'experience_match_score'  => $exp_score,
        'education_match_score'   => $edu_score,
        'project_relevance_score' => $project_relevance_score,
        'required_requirements'   => [
            'matched' => $req_matched,
            'missing' => $req_missing,
            'unclear' => $req_unclear
        ],
        'preferred_requirements'  => [
            'matched' => $pref_matched,
            'missing' => $pref_missing,
            'unclear' => $pref_unclear
        ],
        'strengths'               => $strengths,
        'gaps'                    => array_slice($gaps, 0, 5),
        'evidence'                => array_slice($all_evidence, 0, 10),
        'summary'                 => $summary,
        'model'                   => 'NovaHire Deterministic Screener'
    ];
}

/**
 * Evaluate single requirement against CV text and skill lists
 */
function nh_evaluate_single_requirement($req_name, $cv_text_lower, $cv_skills_canon, $cv_exp_text) {
    $norm_name = trim(mb_strtolower($req_name));
    $escaped = preg_quote($norm_name, '/');

    // 1. Direct match in skills array
    if (in_array($norm_name, $cv_skills_canon)) {
        // Check if also described in experience or projects
        if (preg_match('/\b' . $escaped . '\b/i', $cv_exp_text)) {
            return [
                'name'     => $req_name,
                'status'   => 'MATCHED',
                'evidence' => "Listed in candidate technical skills and demonstrated in professional work experience."
            ];
        }
        return [
            'name'     => $req_name,
            'status'   => 'MATCHED',
            'evidence' => "Explicitly listed in candidate's verified technical skills profile."
        ];
    }

    // 2. Direct whole-word match in full CV text
    if (preg_match('/\b' . $escaped . '\b/i', $cv_text_lower)) {
        return [
            'name'     => $req_name,
            'status'   => 'MATCHED',
            'evidence' => "Found within candidate's CV text and project descriptions."
        ];
    }

    // 3. Check for partial or unclear keyword mentions
    // e.g. "cloud" for "AWS", "database" for "SQL", "containers" for "Docker"
    $unclear_aliases = [
        'aws'        => ['cloud', 'ec2', 's3', 'serverless'],
        'docker'     => ['container', 'containerization', 'k8s', 'kubernetes'],
        'kubernetes' => ['k8s', 'container orchestration', 'docker'],
        'sql'        => ['database', 'rdbms', 'db', 'queries'],
        'git'        => ['version control', 'github', 'gitlab', 'bitbucket'],
        'pytorch'    => ['torch', 'deep learning', 'neural networks'],
        'tensorflow' => ['tf', 'deep learning', 'keras'],
        'react'      => ['frontend', 'react.js', 'jsx']
    ];

    if (isset($unclear_aliases[$norm_name])) {
        foreach ($unclear_aliases[$norm_name] as $alias) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $cv_text_lower)) {
                return [
                    'name'     => $req_name,
                    'status'   => 'UNCLEAR',
                    'evidence' => "Related domain experience ('$alias') is mentioned, but '$req_name' is not explicitly identified."
                ];
            }
        }
    }

    // 4. Missing / Not Found
    return [
        'name'     => $req_name,
        'status'   => 'NOT_FOUND',
        'evidence' => "No explicit mention or evidence found in the provided CV."
    ];
}
