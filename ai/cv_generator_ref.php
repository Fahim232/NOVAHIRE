<?php
/**
 * NovaHire AI - CV Generator Engine
 * 
 * Generates comprehensive, ATS-optimized, structured CV data from candidate
 * profile + target job vacancy.
 * 
 * Supports:
 * 1. Hybrid LLM (OpenAI / Google Gemini) when configured with API keys.
 * 2. Intelligent Offline Heuristic Synthesizer (rule-based domain modeling,
 *    quantified action-verbs, smart skill clustering) - 100% offline.
 * 3. AI Section Enhancer (summary re-writing, bullet point polishing, headline elevation).
 */

if (defined('AI_CV_GENERATOR_LOADED')) return;
define('AI_CV_GENERATOR_LOADED', true);

require_once __DIR__ . '/engine.php';

/**
 * Ensure the ai_generated_cvs table exists in the database.
 */
function ai_ensure_cv_table($con) {
    if (!$con) return false;
    $sql = "CREATE TABLE IF NOT EXISTS `ai_generated_cvs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `job_id` int(11) DEFAULT NULL,
      `template_name` varchar(50) NOT NULL DEFAULT 'modern',
      `full_name` varchar(255) NOT NULL,
      `headline` varchar(255) DEFAULT NULL,
      `email` varchar(255) DEFAULT NULL,
      `phone` varchar(50) DEFAULT NULL,
      `location` varchar(255) DEFAULT NULL,
      `summary` text DEFAULT NULL,
      `skills_json` text DEFAULT NULL,
      `experience_json` longtext DEFAULT NULL,
      `education_json` text DEFAULT NULL,
      `projects_json` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `job_id` (`job_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    return @mysqli_query($con, $sql);
}

/**
 * Generate full structured CV content.
 *
 * @param array $user   User profile data row from `user_info`
 * @param array|null $job Target job data row from `company_jobs` (optional)
 * @param array $options Additional generator options
 * @return array Structured CV data with mode indicator
 */
function ai_generate_cv_content($user, $job = null, $options = []) {
    $username   = trim((string)($user['username'] ?? 'Professional Candidate'));
    $email      = trim((string)($user['email'] ?? ''));
    $phone      = trim((string)($user['phone'] ?? ''));
    $address    = trim((string)($user['address'] ?? 'Dhaka, Bangladesh'));
    if ($address === '') $address = 'Dhaka, Bangladesh';
    
    $degree     = trim((string)($user['user_degree'] ?? 'Bachelor of Science in Computer Science'));
    if ($degree === '') $degree = 'Bachelor of Science in Computer Science';
    
    $skills_raw = trim((string)($user['user_skills'] ?? ''));
    $skills_arr = ai_skills_to_array($skills_raw);
    if (empty($skills_arr)) {
        $skills_arr = ['PHP', 'MySQL', 'JavaScript', 'HTML5', 'CSS3', 'Git', 'Bootstrap'];
    }

    $exp_raw    = trim((string)($user['experience'] ?? ''));
    $about_raw  = trim((string)($user['about_me'] ?? ''));

    // Job context if provided
    $job_title     = $job ? trim((string)($job['job_title'] ?? '')) : '';
    $company_name  = $job ? trim((string)($job['company_name'] ?? '')) : '';
    $job_cat       = $job ? trim((string)($job['job_category'] ?? '')) : '';
    $job_skills    = ($job && !empty($job['skills_required'])) ? ai_skills_to_array($job['skills_required']) : [];
    $job_desc      = $job ? trim((string)($job['job_description'] ?? '')) : '';

    // ── 1. LLM Generation Path (if configured) ──
    if (ai_llm_available()) {
        $system = "You are an elite executive resume writer and ATS optimization specialist.\n"
                . "Based on the provided candidate profile and target job vacancy (if any), generate a high-impact, ATS-optimized CV in STRICT valid JSON format.\n"
                . "IMPORTANT INSTRUCTIONS:\n"
                . "1. Output MUST be strictly valid JSON without any markdown code fences, comments, or extra text.\n"
                . "2. Use action-verb driven, quantified bullet points for experience and projects.\n"
                . "3. Tailor the headline, summary, and skills specifically to the target job if specified.\n"
                . "4. Keep the output grounded in the candidate's actual qualifications without fabricating unrelated degrees.";

        $prompt = "Candidate Name: $username\n"
                . "Email: $email\n"
                . "Phone: $phone\n"
                . "Location: $address\n"
                . "Degree / Education: $degree\n"
                . "Profile Skills: " . implode(', ', $skills_arr) . "\n"
                . "Experience Notes: $exp_raw\n"
                . "About Candidate: $about_raw\n\n";

        if ($job) {
            $prompt .= "Target Job Position: $job_title\n"
                     . "Target Company: $company_name\n"
                     . "Job Category: $job_cat\n"
                     . "Required Skills: " . implode(', ', $job_skills) . "\n"
                     . "Job Description Summary: " . ai_clip($job_desc, 600) . "\n\n";
        }

        $prompt .= "Return JSON strictly adhering to this structure:\n"
                 . "{\n"
                 . "  \"full_name\": \"...\",\n"
                 . "  \"headline\": \"...\",\n"
                 . "  \"email\": \"...\",\n"
                 . "  \"phone\": \"...\",\n"
                 . "  \"location\": \"...\",\n"
                 . "  \"summary\": \"3-4 sentence powerful career summary\",\n"
                 . "  \"skills\": {\n"
                 . "    \"technical\": [\"...\", \"...\"],\n"
                 . "    \"soft\": [\"...\", \"...\"],\n"
                 . "    \"tools\": [\"...\", \"...\"]\n"
                 . "  },\n"
                 . "  \"experience\": [\n"
                 . "    {\n"
                 . "      \"role\": \"...\",\n"
                 . "      \"company\": \"...\",\n"
                 . "      \"location\": \"...\",\n"
                 . "      \"period\": \"2022 - Present\",\n"
                 . "      \"bullets\": [\"Action verb...\", \"Quantified outcome...\"]\n"
                 . "    }\n"
                 . "  ],\n"
                 . "  \"education\": [\n"
                 . "    {\n"
                 . "      \"degree\": \"...\",\n"
                 . "      \"institution\": \"...\",\n"
                 . "      \"year\": \"...\",\n"
                 . "      \"details\": \"...\"\n"
                 . "    }\n"
                 . "  ],\n"
                 . "  \"projects\": [\n"
                 . "    {\n"
                 . "      \"title\": \"...\",\n"
                 . "      \"tech_stack\": \"...\",\n"
                 . "      \"bullets\": [\"...\"]\n"
                 . "    }\n"
                 . "  ]\n"
                 . "}";

        $res = ai_llm_chat($system, $prompt, 1800);
        if ($res['ok'] && !empty($res['text'])) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/is', '', trim($res['text']));
            $decoded = json_decode($cleaned, true);
            if (is_array($decoded) && !empty($decoded['full_name'])) {
                // Ensure required keys exist
                $decoded['mode'] = 'llm';
                $decoded['provider'] = ai_provider_label();
                $decoded['target_job'] = $job ? $job_title . ' at ' . $company_name : null;
                return _ai_normalize_cv_payload($decoded, $user);
            }
        }
    }

    // ── 2. Offline Smart Heuristic Generator (Zero-Config Fallback) ──
    return _ai_generate_offline_smart_cv($user, $job, $skills_arr, $degree, $exp_raw, $about_raw, $address);
}

/**
 * Intelligent Rule-Based Offline CV Synthesizer.
 */
function _ai_generate_offline_smart_cv($user, $job, $skills_arr, $degree, $exp_raw, $about_raw, $address) {
    $username = trim((string)($user['username'] ?? 'Professional Candidate'));
    $email    = trim((string)($user['email'] ?? 'candidate@novahire.com'));
    $phone    = trim((string)($user['phone'] ?? '+880 1700-000000'));
    
    // Determine target headline
    $category = $job ? ($job['job_category'] ?? '') : ai_detect_category(implode(' ', $skills_arr) . ' ' . $degree);
    if (!$category) $category = 'Software';

    $headline = '';
    if ($job && !empty($job['job_title'])) {
        $headline = $job['job_title'] . ' | ' . ucfirst($category) . ' Specialist';
    } elseif (!empty($skills_arr)) {
        $headline = ucfirst($skills_arr[0]) . ' Developer & ' . ucfirst($category) . ' Specialist';
    } else {
        $headline = $degree . ' Professional';
    }

    // Categorize skills into technical, soft, and tools
    $tech_skills = [];
    $soft_skills = ['Problem Solving', 'Agile Collaboration', 'Cross-functional Communication', 'Code Review & Mentorship', 'Critical Thinking'];
    $tools       = ['Git & GitHub', 'VS Code', 'Docker', 'Postman', 'Jira / Trello', 'Linux CLI'];

    foreach ($skills_arr as $s) {
        $s_clean = ucwords(trim($s));
        if ($s_clean !== '' && !in_array($s_clean, $tech_skills)) {
            $tech_skills[] = $s_clean;
        }
    }

    // Merge job skills if target job is set
    if ($job && !empty($job['skills_required'])) {
        foreach (ai_skills_to_array($job['skills_required']) as $js) {
            $js_clean = ucwords(trim($js));
            if (!in_array($js_clean, $tech_skills)) {
                $tech_skills[] = $js_clean;
            }
        }
    }

    if (empty($tech_skills)) {
        $tech_skills = ['PHP', 'MySQL', 'JavaScript', 'HTML5 & CSS3', 'REST APIs', 'Bootstrap'];
    }

    // Work Experience Synthesis
    $years = ai_parse_years($exp_raw);
    $exp_items = [];

    $primary_skill = $tech_skills[0] ?? 'Web';
    $secondary_skill = $tech_skills[1] ?? 'Database';

    if ($years !== null && $years >= 3) {
        $exp_items[] = [
            'role'      => 'Senior ' . ($job ? $job['job_title'] : $category . ' Developer'),
            'company'   => 'Apex Technologies Ltd.',
            'location'  => 'Dhaka, Bangladesh',
            'period'    => '2022 - Present',
            'bullets'   => [
                'Architected and delivered high-concurrency ' . $primary_skill . ' applications, improving server throughput by 35% and reducing response latency.',
                'Spearheaded the integration of secure RESTful APIs and ' . $secondary_skill . ' optimizations, supporting over 50,000 active daily user requests.',
                'Championed automated CI/CD workflows and code review standards across a cross-functional team of 6 engineers.'
            ]
        ];
        $exp_items[] = [
            'role'      => 'Software Engineer',
            'company'   => 'NextGen Solutions Inc.',
            'location'  => 'Dhaka, Bangladesh',
            'period'    => '2020 - 2022',
            'bullets'   => [
                'Engineered modular backend services and interactive responsive frontend interfaces utilizing ' . implode(', ', array_slice($tech_skills, 0, 3)) . '.',
                'Refactored legacy database queries, cutting average query execution time by 42% and preventing deadlocks.',
                'Collaborated closely with product designers and QA engineers to ensure pixel-perfect delivery adhering to strict sprint deadlines.'
            ]
        ];
    } else {
        // Entry / Mid-level Experience
        $exp_items[] = [
            'role'      => ($job ? $job['job_title'] : $category . ' Engineer'),
            'company'   => 'Innovate Softworks',
            'location'  => 'Dhaka, Bangladesh',
            'period'    => '2023 - Present',
            'bullets'   => [
                'Developed and maintained key application modules using ' . $primary_skill . ', ' . $secondary_skill . ', and modern design frameworks.',
                'Integrated authentication mechanisms and asynchronous data pipelines, ensuring seamless user experience and strict data validation.',
                'Participated in sprint planning, agile standups, and unit testing routines, consistently delivering features ahead of target release cycles.'
            ]
        ];
        $exp_items[] = [
            'role'      => 'Junior Associate / Developer Intern',
            'company'   => 'TechnoVibe Labs',
            'location'  => 'Dhaka, Bangladesh',
            'period'    => '2022 - 2023',
            'bullets'   => [
                'Contributed to customer-facing web portals and bug fixing across frontend layouts and backend database queries.',
                'Authored comprehensive technical documentation, onboarding guides, and API integration specifications.'
            ]
        ];
    }

    // Education Synthesis
    $edu_items = [];
    $edu_items[] = [
        'degree'      => $degree,
        'institution' => 'United International University',
        'year'        => '2019 - 2023',
        'details'     => 'Completed with Academic Distinction. Coursework focused on Software Architecture, Advanced Database Management, and Algorithm Design.'
    ];
    $edu_items[] = [
        'degree'      => 'Higher Secondary Certificate (HSC) - Science',
        'institution' => 'APBN School & College',
        'year'        => '2017 - 2019',
        'details'     => 'Graduated with GPA 5.00 (First Class Honors) with focus on Mathematics and Information Technology.'
    ];

    // Projects Synthesis
    $project_items = [];
    $project_items[] = [
        'title'      => 'Enterprise ' . ($category ? $category : 'Cloud') . ' Management Portal',
        'tech_stack' => implode(', ', array_slice($tech_skills, 0, 4)),
        'bullets'    => [
            'Designed a full-stack platform featuring real-time analytics, role-based access control, and automated report generation.',
            'Implemented asynchronous queue processing and database indexing to handle concurrent data imports seamlessly.'
        ]
    ];
    $project_items[] = [
        'title'      => 'Smart ' . $primary_skill . ' Job & Skill Matcher',
        'tech_stack' => $primary_skill . ', ' . $secondary_skill . ', JavaScript, Bootstrap 4',
        'bullets'    => [
            'Built an automated recommendation pipeline calculating candidate-job compatibility score with keyword ranking algorithms.',
            'Delivered an interactive, responsive user dashboard with instant search, bookmarking, and live notification alerts.'
        ]
    ];

    // Summary Synthesis
    $target_str = $job ? ' for the ' . $job['job_title'] . ' position at ' . $job['company_name'] : '';
    $summary = "Results-driven $headline with a solid foundation in " . implode(', ', array_slice($tech_skills, 0, 4)) . " and modern software development practices. "
             . "Proven track record in developing robust, scalable applications, optimizing database performance, and collaborating effectively in cross-functional agile teams. "
             . ($about_raw !== '' ? $about_raw . ' ' : '')
             . "Eager to leverage strong analytical abilities and problem-solving skills to drive engineering excellence and business impact$target_str.";

    return [
        'mode'          => 'offline_smart',
        'provider'      => 'NovaHire Hybrid Engine',
        'target_job'    => $job ? $job['job_title'] . ' at ' . $job['company_name'] : null,
        'full_name'     => $username,
        'headline'      => $headline,
        'email'         => $email,
        'phone'         => $phone,
        'location'      => $address,
        'summary'       => $summary,
        'skills'        => [
            'technical' => array_values(array_unique($tech_skills)),
            'soft'      => $soft_skills,
            'tools'     => $tools,
        ],
        'experience'    => $exp_items,
        'education'     => $edu_items,
        'projects'      => $project_items,
    ];
}

/**
 * Normalizes and validates incoming CV payload structures.
 */
function _ai_normalize_cv_payload($data, $user) {
    if (empty($data['full_name'])) $data['full_name'] = $user['username'] ?? 'Professional Candidate';
    if (empty($data['email']))     $data['email']     = $user['email'] ?? '';
    if (empty($data['phone']))     $data['phone']     = $user['phone'] ?? '';
    if (empty($data['location']))  $data['location']  = $user['address'] ?? 'Dhaka, Bangladesh';
    if (empty($data['headline']))  $data['headline']  = ($user['user_degree'] ?? 'Professional') . ' Specialist';
    if (empty($data['summary']))   $data['summary']   = 'Dedicated professional with strong technical expertise and proven track record of delivering impactful results.';
    
    // Ensure skills is structured
    if (!isset($data['skills']) || !is_array($data['skills'])) {
        $data['skills'] = [
            'technical' => ['PHP', 'MySQL', 'JavaScript', 'HTML5', 'CSS3'],
            'soft'      => ['Communication', 'Teamwork', 'Problem Solving'],
            'tools'     => ['Git', 'VS Code', 'Postman']
        ];
    } elseif (isset($data['skills'][0])) {
        // Flat array given, convert to categorized
        $data['skills'] = [
            'technical' => $data['skills'],
            'soft'      => ['Problem Solving', 'Teamwork', 'Agile Collaboration'],
            'tools'     => ['Git & GitHub', 'VS Code', 'Postman']
        ];
    }

    if (empty($data['experience']) || !is_array($data['experience'])) {
        $data['experience'] = [];
    }
    if (empty($data['education']) || !is_array($data['education'])) {
        $data['education'] = [];
    }
    if (empty($data['projects']) || !is_array($data['projects'])) {
        $data['projects'] = [];
    }

    return $data;
}

/**
 * AI Enhance a specific section of the CV.
 *
 * @param string $section_type 'summary' | 'bullets' | 'headline' | 'experience' | 'project'
 * @param string $original_text
 * @param array  $context Additional context (e.g. target role, company)
 * @return string Enhanced text
 */
function ai_enhance_section($section_type, $original_text, $context = []) {
    $original_text = trim((string)$original_text);
    if ($original_text === '') return $original_text;

    $role_ctx = isset($context['role']) ? $context['role'] : 'Software Engineering Professional';

    // ── LLM Enhancement ──
    if (ai_llm_available()) {
        $system = "You are an elite executive resume consultant and ATS copywriter.\n"
                . "Your goal is to rewrite and elevate the provided resume content to be impactful, active-voice driven, metric-oriented, and ATS-optimized.\n"
                . "Output ONLY the rewritten text without markdown fences, explanation, or conversational greetings.";

        $prompt = "Section Type: $section_type\n"
                . "Candidate Target Context: $role_ctx\n"
                . "Original Content:\n$original_text\n\n"
                . "Rewrite this content with strong power verbs, quantifiable clarity, and professional polish.";

        $res = ai_llm_chat($system, $prompt, 400);
        if ($res['ok'] && !empty($res['text'])) {
            return trim($res['text']);
        }
    }

    // ── Rule-Based Enhancement Fallback ──
    switch ($section_type) {
        case 'summary':
            $verbs = ['Results-oriented and high-performing', 'Demonstrated track record of success as a', 'Versatile and innovative'];
            $lead = $verbs[array_rand($verbs)];
            // Strip any passive beginnings
            $clean = preg_replace('/^(I am a|Motivated and detail-oriented|Looking for a job as|Seeking a position as)/i', '', $original_text);
            $clean = trim($clean, " .,;\n\r");
            return $lead . ' ' . $clean . '. Committed to driving technical excellence, optimizing operational workflows, and delivering high-value solutions.';

        case 'headline':
            $clean = trim($original_text, " |•-");
            return $clean . ' | Technical Innovation & Scalable Architecture';

        case 'bullets':
        case 'bullet':
            $lines = preg_split('/\r\n|\r|\n/', $original_text);
            $enhanced = [];
            $power_verbs = ['Architected and delivered', 'Spearheaded the development of', 'Engineered robust and scalable', 'Streamlined end-to-end', 'Optimized system workflows for'];
            foreach ($lines as $idx => $line) {
                $line = trim($line, " \t\n\r\0\x0B-•*");
                if ($line === '') continue;
                // Replace passive opening verbs
                $line = preg_replace('/^(responsible for|worked on|helped with|did|created|made|managed|handled)\s+/i', '', $line);
                $v = $power_verbs[$idx % count($power_verbs)];
                $enhanced[] = $v . ' ' . lcfirst($line) . ', enhancing system reliability and execution efficiency.';
            }
            return implode("\n", $enhanced);

        default:
            return $original_text;
    }
}
