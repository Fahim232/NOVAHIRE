<?php
/**
 * NovaHire — AI CV Generator API
 * Handles live AI text generation and re-writing for CV elements.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_seeker_login();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input.']);
    exit;
}

$action = $input['action'] ?? '';
$text = $input['text'] ?? '';
$context = $input['context'] ?? '';

if (empty($action) || empty($text)) {
    echo json_encode(['success' => false, 'error' => 'Action and text are required.']);
    exit;
}

// Load AI Engine
require_once __DIR__ . '/../ai/engine.php';

$system = "You are an expert Executive Resume Writer and Career Coach. Your goal is to improve the provided resume content. Return ONLY the improved text, without quotes, explanations, or introductory text like 'Here is the improved version'. Make it sound professional, action-oriented, and impactful.";
$prompt = "";

switch ($action) {
    case 'rewrite_summary':
        $prompt = "Rewrite the following professional summary to make it more engaging, concise, and impactful. Highlight key strengths. Current summary:\n\n" . $text;
        break;

    case 'polish_bullets':
        $prompt = "Rewrite the following work experience bullet points. Use strong action verbs and focus on achievements and metrics where possible. Keep it as a bulleted list or newline separated. Current text:\n\n" . $text;
        break;

    case 'suggest_skills':
        $prompt = "Given the following job title or brief description, suggest 10 relevant, high-value professional skills. Return them as a comma-separated list. Description:\n\n" . $text;
        break;

    case 'enhance_projects':
        $prompt = "Rewrite the following project description to sound more professional and highlight the technical or business value. Current text:\n\n" . $text;
        break;
        
    case 'rewrite_education':
        $prompt = "Format the following education details into a clean, professional standard format (Degree, Institution, Year). Current text:\n\n" . $text;
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
}

$result = ai_llm_chat($system, $prompt, 400);

if ($result['ok']) {
    echo json_encode(['success' => true, 'data' => $result['text']]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error']]);
}
