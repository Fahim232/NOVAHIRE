<?php
/**
 * NovaHire AI — Assessment Grader
 *
 * Evaluates short-answer responses using the configured LLM provider.
 * Falls back to a "pending_review" status when no LLM is available.
 *
 * Usage:
 *   require_once __DIR__ . '/assessment_grader.php';
 *   $result = ai_grade_short_answer($user_answer, $ideal_answer, $question_text);
 *   // $result = ['score' => 0-100, 'feedback' => '...', 'status' => 'graded'|'pending_review']
 */

if (defined('AI_ASSESSMENT_GRADER_LOADED')) return;
define('AI_ASSESSMENT_GRADER_LOADED', true);

require_once __DIR__ . '/engine.php';

/**
 * Grade a short-answer response against an ideal answer.
 *
 * @param string $user_answer    The candidate's submitted answer.
 * @param string $ideal_answer   The company-provided ideal/reference answer.
 * @param string $question_text  The question text (for context).
 * @return array{score:int, feedback:string, status:string}
 */
function ai_grade_short_answer($user_answer, $ideal_answer, $question_text) {
    $user_answer   = trim((string)$user_answer);
    $ideal_answer  = trim((string)$ideal_answer);
    $question_text = trim((string)$question_text);

    // Empty answer = automatic 0
    if ($user_answer === '') {
        return [
            'score'    => 0,
            'feedback' => 'No answer was provided.',
            'status'   => 'graded',
        ];
    }

    // If no ideal answer is set, we can't grade — mark for manual review
    if ($ideal_answer === '') {
        return [
            'score'    => null,
            'feedback' => 'No ideal answer configured. Awaiting manual review.',
            'status'   => 'pending_review',
        ];
    }

    // Try LLM grading
    if (ai_llm_available()) {
        return ai_grade_with_llm($user_answer, $ideal_answer, $question_text);
    }

    // Offline fallback: basic keyword matching
    return ai_grade_offline($user_answer, $ideal_answer);
}

/**
 * Grade using the configured LLM (OpenAI or Gemini).
 */
function ai_grade_with_llm($user_answer, $ideal_answer, $question_text) {
    $system = <<<PROMPT
You are an expert assessment grader for a job portal. Your task is to evaluate a candidate's short-answer response.

RULES:
- Score from 0 to 100 based on accuracy, completeness, and relevance.
- 90-100: Excellent — demonstrates deep understanding, covers all key points.
- 70-89: Good — mostly correct with minor gaps.
- 50-69: Fair — shows partial understanding but misses important aspects.
- 25-49: Weak — significant gaps or inaccuracies.
- 0-24: Poor — fundamentally incorrect or irrelevant.
- Provide brief, constructive feedback (2-3 sentences max).
- Be fair but rigorous. Do not give high scores for vague or generic answers.

RESPOND IN EXACTLY THIS JSON FORMAT (no markdown, no code blocks):
{"score": <number 0-100>, "feedback": "<brief explanation>"}
PROMPT;

    $prompt = <<<INPUT
QUESTION: {$question_text}

IDEAL ANSWER: {$ideal_answer}

CANDIDATE'S ANSWER: {$user_answer}

Grade the candidate's answer against the ideal answer.
INPUT;

    $result = ai_llm_chat($system, $prompt, 300);

    if ($result['ok']) {
        $text = $result['text'];
        // Try to extract JSON from the response
        $json = json_decode($text, true);
        if ($json === null) {
            // Try to find JSON in the response text
            if (preg_match('/\{[^}]*"score"\s*:\s*(\d+)[^}]*"feedback"\s*:\s*"([^"]*)"[^}]*\}/', $text, $m)) {
                $json = ['score' => intval($m[1]), 'feedback' => $m[2]];
            }
        }

        if ($json && isset($json['score'])) {
            $score = max(0, min(100, intval($json['score'])));
            $feedback = isset($json['feedback']) ? trim($json['feedback']) : 'Graded by AI.';
            return [
                'score'    => $score,
                'feedback' => $feedback,
                'status'   => 'graded',
            ];
        }

        // LLM returned something but not parseable — fallback
        return [
            'score'    => null,
            'feedback' => 'AI response could not be parsed. Awaiting manual review.',
            'status'   => 'pending_review',
        ];
    }

    // LLM call failed — fallback to offline
    return ai_grade_offline($user_answer, $ideal_answer);
}

/**
 * Offline keyword-based grading fallback.
 * Simple but deterministic: checks how many key tokens from the ideal answer
 * appear in the candidate's answer.
 */
function ai_grade_offline($user_answer, $ideal_answer) {
    $ideal_tokens = array_unique(array_filter(
        preg_split('/[\s,;.!?]+/', mb_strtolower($ideal_answer)),
        function ($w) { return mb_strlen($w) >= 3; }
    ));

    if (count($ideal_tokens) === 0) {
        return [
            'score'    => null,
            'feedback' => 'Unable to grade offline. Awaiting manual review.',
            'status'   => 'pending_review',
        ];
    }

    $user_lower = mb_strtolower($user_answer);
    $matches = 0;
    foreach ($ideal_tokens as $token) {
        if (mb_strpos($user_lower, $token) !== false) {
            $matches++;
        }
    }

    $ratio = $matches / count($ideal_tokens);
    $score = (int) round($ratio * 100);

    // Determine feedback
    if ($score >= 80) {
        $feedback = 'Strong keyword coverage matching the ideal answer (offline grading).';
    } elseif ($score >= 50) {
        $feedback = 'Partial keyword match. Some key concepts are missing (offline grading).';
    } elseif ($score >= 25) {
        $feedback = 'Limited keyword overlap with the expected answer (offline grading).';
    } else {
        $feedback = 'Very few matching keywords found (offline grading).';
    }

    return [
        'score'    => $score,
        'feedback' => $feedback,
        'status'   => 'graded',
    ];
}
