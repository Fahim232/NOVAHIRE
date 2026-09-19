<?php

/**
 * AI Skills Taxonomy
 * 
 * Centralized dictionary for normalizing skills, identifying technical vs soft skills,
 * and defining category relationships for the AI matching engine.
 */

// Global skill dictionary
global $ai_skills_dictionary;
$ai_skills_dictionary = [
    // --- Data Science & AI ---
    'Python' => ['type' => 'technical', 'aliases' => ['python3', 'py', 'python programming', 'pythons']],
    'Machine Learning' => ['type' => 'technical', 'aliases' => ['ml', 'machine-learning', 'machinelearning']],
    'Data Science' => ['type' => 'technical', 'aliases' => ['datascience', 'data-science']],
    'Data Analysis' => ['type' => 'technical', 'aliases' => ['data analytics', 'data-analysis', 'data analytic']],
    'Pandas' => ['type' => 'technical', 'aliases' => ['python pandas']],
    'NumPy' => ['type' => 'technical', 'aliases' => ['python numpy']],
    'Scikit-learn' => ['type' => 'technical', 'aliases' => ['scikit', 'sklearn', 'scikit learn']],
    'TensorFlow' => ['type' => 'technical', 'aliases' => ['tf']],
    'PyTorch' => ['type' => 'technical', 'aliases' => ['torch']],
    
    // --- Backend & Enterprise ---
    'Java' => ['type' => 'technical', 'aliases' => ['core java', 'j2ee']],
    'Spring Boot' => ['type' => 'technical', 'aliases' => ['spring', 'springboot', 'spring framework']],
    'Hibernate' => ['type' => 'technical', 'aliases' => []],
    'C#' => ['type' => 'technical', 'aliases' => ['c sharp', 'c-sharp', 'c#.net']],
    '.NET' => ['type' => 'technical', 'aliases' => ['dotnet', 'asp.net', 'dot net']],
    'C++' => ['type' => 'technical', 'aliases' => ['cpp', 'c plus plus']],
    'C' => ['type' => 'technical', 'aliases' => []],
    'PHP' => ['type' => 'technical', 'aliases' => ['core php']],
    'Laravel' => ['type' => 'technical', 'aliases' => []],
    'Node.js' => ['type' => 'technical', 'aliases' => ['nodejs', 'node js', 'node']],
    'Express' => ['type' => 'technical', 'aliases' => ['express.js', 'expressjs']],
    'REST API' => ['type' => 'technical', 'aliases' => ['rest', 'restful api', 'api', 'restful']],
    
    // --- Frontend & Web ---
    'JavaScript' => ['type' => 'technical', 'aliases' => ['js', 'ecmascript', 'es6']],
    'TypeScript' => ['type' => 'technical', 'aliases' => ['ts']],
    'React' => ['type' => 'technical', 'aliases' => ['reactjs', 'react.js']],
    'Angular' => ['type' => 'technical', 'aliases' => ['angularjs', 'angular.js', 'angular 2+']],
    'Vue.js' => ['type' => 'technical', 'aliases' => ['vue', 'vuejs']],
    'HTML' => ['type' => 'technical', 'aliases' => ['html5']],
    'CSS' => ['type' => 'technical', 'aliases' => ['css3']],
    'UI/UX' => ['type' => 'technical', 'aliases' => ['ui', 'ux', 'user interface', 'user experience', 'ui design', 'ux design']],
    
    // --- Mobile ---
    'Android' => ['type' => 'technical', 'aliases' => ['android development']],
    'iOS' => ['type' => 'technical', 'aliases' => ['ios development']],
    'Swift' => ['type' => 'technical', 'aliases' => []],
    'Kotlin' => ['type' => 'technical', 'aliases' => []],
    'Flutter' => ['type' => 'technical', 'aliases' => []],
    'React Native' => ['type' => 'technical', 'aliases' => ['react-native', 'rn']],
    
    // --- Databases ---
    'SQL' => ['type' => 'technical', 'aliases' => ['structured query language']],
    'MySQL' => ['type' => 'technical', 'aliases' => []],
    'PostgreSQL' => ['type' => 'technical', 'aliases' => ['postgres']],
    'MongoDB' => ['type' => 'technical', 'aliases' => ['mongo']],
    'NoSQL' => ['type' => 'technical', 'aliases' => []],
    'Oracle' => ['type' => 'technical', 'aliases' => ['oracle db']],
    
    // --- Cloud & DevOps ---
    'AWS' => ['type' => 'technical', 'aliases' => ['amazon web services']],
    'Azure' => ['type' => 'technical', 'aliases' => ['microsoft azure']],
    'GCP' => ['type' => 'technical', 'aliases' => ['google cloud', 'google cloud platform']],
    'Docker' => ['type' => 'technical', 'aliases' => []],
    'Kubernetes' => ['type' => 'technical', 'aliases' => ['k8s']],
    'Git' => ['type' => 'technical', 'aliases' => ['github', 'gitlab', 'bitbucket', 'version control']],
    'CI/CD' => ['type' => 'technical', 'aliases' => ['continuous integration', 'continuous deployment', 'jenkins']],
    'Agile' => ['type' => 'technical', 'aliases' => ['scrum', 'kanban']],

    // --- Soft / Generic Skills ---
    'Communication' => ['type' => 'soft', 'aliases' => ['communication skills', 'strong communication', 'excellent communication']],
    'Teamwork' => ['type' => 'soft', 'aliases' => ['team player', 'collaborative', 'collaboration']],
    'Problem Solving' => ['type' => 'soft', 'aliases' => ['problem-solving', 'analytical skills']],
    'Leadership' => ['type' => 'soft', 'aliases' => ['team leadership', 'management']],
    'Time Management' => ['type' => 'soft', 'aliases' => ['meeting deadlines', 'multitasking']],
    'Adaptability' => ['type' => 'soft', 'aliases' => ['flexible', 'fast learner']]
];

// Cache for quick lookups
global $ai_skills_lookup_cache;
$ai_skills_lookup_cache = null;

function _build_skills_lookup_cache() {
    global $ai_skills_dictionary, $ai_skills_lookup_cache;
    if ($ai_skills_lookup_cache !== null) return;
    
    $ai_skills_lookup_cache = [];
    foreach ($ai_skills_dictionary as $canonical => $data) {
        $ai_skills_lookup_cache[strtolower($canonical)] = $canonical;
        foreach ($data['aliases'] as $alias) {
            $ai_skills_lookup_cache[strtolower($alias)] = $canonical;
        }
    }
}

/**
 * Normalizes a raw skill string to its canonical form, if known.
 * @param string $skill
 * @return string Canonical skill or original string (trimmed/proper cased) if unknown.
 */
function ai_canonical_skill($skill) {
    global $ai_skills_lookup_cache;
    if ($ai_skills_lookup_cache === null) {
        _build_skills_lookup_cache();
    }
    
    $skill = trim($skill);
    $normalized = strtolower($skill);
    
    if (isset($ai_skills_lookup_cache[$normalized])) {
        return $ai_skills_lookup_cache[$normalized];
    }
    
    // Capitalize properly if unknown
    return ucwords(strtolower($skill));
}

/**
 * Checks if a skill is soft. Soft skills don't count towards core technical requirements.
 * @param string $canonical_skill
 * @return bool
 */
function ai_is_soft_skill($canonical_skill) {
    global $ai_skills_dictionary;
    if (isset($ai_skills_dictionary[$canonical_skill])) {
        return $ai_skills_dictionary[$canonical_skill]['type'] === 'soft';
    }
    // Assume unknown skills are technical/domain-specific by default
    return false;
}

/**
 * Parses a comma-separated string or array into an array of canonical skills.
 * @param string|array $raw_input
 * @return array Array of canonical skills
 */
function ai_skills_normalize_list($raw_input) {
    if (is_string($raw_input)) {
        // Handle common delimiters
        $raw_input = preg_split('/[,;|]+/', $raw_input);
    }
    
    if (!is_array($raw_input)) {
        return [];
    }
    
    $canonical_skills = [];
    foreach ($raw_input as $s) {
        $s = trim($s);
        if (!empty($s)) {
            $canon = ai_canonical_skill($s);
            if (!in_array($canon, $canonical_skills)) {
                $canonical_skills[] = $canon;
            }
        }
    }
    return $canonical_skills;
}

/**
 * Category hierarchy and relationships.
 * Defines how categories relate to each other with a similarity score (0.0 to 1.0).
 */
function ai_get_category_similarity($cat1, $cat2) {
    $cat1 = trim(strtolower((string)$cat1 ?? ''));
    $cat2 = trim(strtolower((string)$cat2 ?? ''));
    
    if ($cat1 === $cat2) return 1.0;
    
    // Simplify matching: ignore spaces and non-alphanumeric
    $norm1 = preg_replace('/[^a-z0-9]/', '', $cat1);
    $norm2 = preg_replace('/[^a-z0-9]/', '', $cat2);
    
    if ($norm1 === $norm2) return 1.0;
    
    $relationships = [
        ['ai machine learning', 'data science', 0.85],
        ['datascience', 'aimachinelearning', 0.85], // normalized versions just in case
        ['datascience', 'python', 0.85],
        ['datascienceai', 'python', 0.85],
        ['datascienceai', 'datascience', 0.95],
        ['javabackend', 'java', 0.95],
        ['frontend', 'full stack', 0.75],
        ['backend', 'full stack', 0.75],
        ['frontend', 'web development', 0.80],
        ['backend', 'web development', 0.80],
        ['web development', 'full stack', 0.90],
        ['java', 'enterprise backend', 0.80],
        ['java', 'backend', 0.80],
        ['uiux', 'frontend', 0.40],
        ['mobile app development', 'android', 0.90],
        ['mobile app development', 'ios', 0.90],
        ['cybersecurity', 'networking', 0.60]
    ];
    
    foreach ($relationships as $rel) {
        $r1 = preg_replace('/[^a-z0-9]/', '', $rel[0]);
        $r2 = preg_replace('/[^a-z0-9]/', '', $rel[1]);
        
        if (($norm1 === $r1 && $norm2 === $r2) || ($norm1 === $r2 && $norm2 === $r1)) {
            return $rel[2];
        }
    }
    
    return 0.1; // Default low similarity for unrelated categories
}
