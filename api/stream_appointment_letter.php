<?php
/**
 * Real-Time AI Appointment Letter Generation via Server-Sent Events (SSE)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../ai/engine.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';
global $con;

// Clean all previous output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set SSE Headers
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

function sse_send($data) {
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

// Authentication Check
if (!isset($_SESSION['company_id'])) {
    sse_send(['error' => 'Unauthorized. Please log in as a company.']);
    exit;
}

$company_id = intval($_SESSION['company_id']);
$company_name = $_SESSION['company_name'] ?? 'Company';

// Read inputs
$app_id = intval($_REQUEST['application_id'] ?? 0);
if ($app_id <= 0) {
    sse_send(['error' => 'Missing or invalid application_id.']);
    exit;
}

// Fetch application and hiring decision details
$app_stmt = mysqli_prepare($con, "
    SELECT ja.*, cj.job_title, cj.job_category, ui.username, ui.email, ui.phone,
           hd.decision, hd.final_joining_date, hd.employment_status_noted
    FROM job_applications ja
    JOIN company_jobs cj ON ja.job_id = cj.id
    JOIN user_info ui ON ja.user_id = ui.id
    LEFT JOIN hiring_decisions hd ON ja.id = hd.application_id
    WHERE ja.id = ? AND ja.company_id = ?
");
mysqli_stmt_bind_param($app_stmt, "ii", $app_id, $company_id);
mysqli_stmt_execute($app_stmt);
$app = mysqli_fetch_assoc(mysqli_stmt_get_result($app_stmt));
mysqli_stmt_close($app_stmt);

if (!$app) {
    sse_send(['error' => 'Application not found or unauthorized.']);
    exit;
}

if ($app['decision'] !== 'selected') {
    sse_send(['error' => 'Candidate must be formally marked as Selected (Hire) before generating an appointment letter.']);
    exit;
}

// Candidate must have submitted a selection response confirming readiness
$cea = get_candidate_selection_response($con, $app_id);
if (!$cea || $cea['ready_to_join'] !== 'yes') {
    sse_send(['error' => 'Candidate must confirm readiness to join before generating an appointment letter.']);
    exit;
}

$candidate_id = intval($app['user_id']);
$job_id = intval($app['job_id']);

// Joining date: passed from form -> final_joining_date from DB -> candidate's approximate joining date
$joining_date = trim($_REQUEST['joining_date'] ?? ($app['final_joining_date'] ?? ($cea['approximate_joining_date'] ?? '')));

if (empty($joining_date)) {
    sse_send(['error' => 'A confirmed joining date is required to generate an appointment letter.']);
    exit;
}

$designation = trim($_REQUEST['designation'] ?? ($app['job_title'] ?? ''));
$salary = trim($_REQUEST['salary'] ?? '');
$work_location = trim($_REQUEST['work_location'] ?? '');
$reporting_manager = trim($_REQUEST['reporting_manager'] ?? '');
$probation_period = trim($_REQUEST['probation_period'] ?? '');
$working_hours = trim($_REQUEST['working_hours'] ?? '');

$subject = "Official Appointment Letter: {$designation} — {$company_name}";

sse_send([
    'status' => 'started',
    'message' => 'AI is drafting your official appointment letter...'
]);

// Build structured prompt strictly without fabricating facts
$system_instruction = "You are a senior HR Executive drafting a formal, professional, and warmly welcoming appointment letter.
CRITICAL RULES:
1. ONLY use the verified facts provided below.
2. DO NOT invent salaries, benefits, probation, working hours, or legal terms that are not specified.
3. If salary or location are not provided, mention they are as mutually agreed.
4. Output clean, formatted plain text with headings and paragraphs. Do not wrap in markdown code blocks (no ```).
5. Never output internal thought traces or private reasoning.";

$emp_details = ($cea['currently_working'] === 'yes') 
    ? "Employment Status: Previously/Currently at " . ($cea['current_company_name'] ?: 'previous employer') . " (Notice: " . intval($cea['notice_period_days']) . " days)\n"
    : "Employment Status: Immediately Available\n";

$user_prompt = "Generate an appointment letter with these details:
Company: {$company_name}
Candidate: {$app['username']}
Designation: {$designation}
Job Category: {$app['job_category']}
Confirmed Joining Date: {$joining_date}
Candidate Approximate Availability: {$cea['approximate_joining_date']}
{$emp_details}"
  . (!empty($salary) ? "Salary: {$salary}\n" : "Salary: As mutually agreed upon\n")
  . (!empty($work_location) ? "Work Location: {$work_location}\n" : "Work Location: Company Offices\n")
  . (!empty($reporting_manager) ? "Reporting Manager: {$reporting_manager}\n" : "")
  . (!empty($probation_period) ? "Probation Period: {$probation_period}\n" : "")
  . (!empty($working_hours) ? "Working Hours: {$working_hours}\n" : "");

$full_text = '';
$stream_succeeded = false;

// Attempt provider streaming if configured
if (AI_PROVIDER === 'openai' && AI_OPENAI_KEY !== '') {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . AI_OPENAI_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => AI_OPENAI_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system_instruction],
            ['role' => 'user', 'content' => $user_prompt]
        ],
        'stream' => true,
        'temperature' => 0.7
    ]));
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$full_text, &$stream_succeeded) {
        $lines = explode("\n", $chunk);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $payload = substr($line, 6);
                if ($payload === '[DONE]') {
                    $stream_succeeded = true;
                    continue;
                }
                $json = json_decode($payload, true);
                $delta = $json['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $full_text .= $delta;
                    sse_send(['token' => $delta]);
                }
            }
        }
        return strlen($chunk);
    });
    curl_exec($ch);
    curl_close($ch);
} elseif (AI_PROVIDER === 'gemini' && AI_GEMINI_KEY !== '') {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(AI_GEMINI_MODEL) . ':streamGenerateContent?alt=sse&key=' . AI_GEMINI_KEY;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'system_instruction' => ['parts' => [['text' => $system_instruction]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user_prompt]]]],
        'generationConfig' => ['temperature' => 0.7]
    ]));
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$full_text, &$stream_succeeded) {
        $lines = explode("\n", $chunk);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $payload = substr($line, 6);
                $json = json_decode($payload, true);
                $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text !== '') {
                    $full_text .= $text;
                    $stream_succeeded = true;
                    sse_send(['token' => $text]);
                }
            }
        }
        return strlen($chunk);
    });
    curl_exec($ch);
    curl_close($ch);
}

// Fallback: If streaming wasn't available or returned empty, generate using the high-standard HR generator
if (!$stream_succeeded || empty(trim($full_text))) {
    $full_letter = "APPOINTMENT LETTER\n\n"
        . "Date: " . date('F j, Y') . "\n\n"
        . "To,\n"
        . htmlspecialchars($app['username']) . "\n"
        . (!empty($app['email']) ? htmlspecialchars($app['email']) . "\n" : "")
        . (!empty($app['phone']) ? htmlspecialchars($app['phone']) . "\n" : "") . "\n"
        . "Dear " . htmlspecialchars($app['username']) . ",\n\n"
        . "On behalf of " . htmlspecialchars($company_name) . ", we are delighted to offer you the position of " . htmlspecialchars($designation) . " within our organization.\n\n"
        . "We were thoroughly impressed by your professional background, interview evaluation, and technical potential. We are confident that your expertise will make a significant contribution to our continued growth and team success.\n\n"
        . "1. COMMENCEMENT & REPORTING\n"
        . "Your employment will formally commence on " . date('l, F j, Y', strtotime($joining_date)) . ". You will report to " . (!empty($reporting_manager) ? htmlspecialchars($reporting_manager) : "the Department Head") . ".\n\n"
        . "2. REMUNERATION & BENEFITS\n"
        . (!empty($salary) ? "Your compensation for this position will be " . htmlspecialchars($salary) . ", payable in accordance with the standard company payroll schedule and subject to statutory deductions.\n\n" : "Your compensation package has been mutually agreed upon and will be administered according to company policies.\n\n")
        . "3. WORK LOCATION & WORKING HOURS\n"
        . "Your designated work location will be " . (!empty($work_location) ? htmlspecialchars($work_location) : "the Company Offices") . ". "
        . (!empty($working_hours) ? "Your standard working hours will be " . htmlspecialchars($working_hours) . ".\n\n" : "Standard operational business hours apply.\n\n")
        . (!empty($probation_period) ? "4. PROBATIONARY PERIOD\nYour appointment will include a probationary period of " . htmlspecialchars($probation_period) . ", during which your performance will be evaluated.\n\n" : "")
        . "5. ONBOARDING & DOCUMENT VERIFICATION\n"
        . "This appointment offer is contingent upon the successful completion of our onboarding process and satisfactory verification of your requested credentials, including your National ID, academic transcripts, and previous employment documentation.\n\n"
        . "Please signify your acceptance of this offer by signing and returning the duplicate copy or confirming acceptance via your NovaHire candidate portal prior to your joining date.\n\n"
        . "We extend a warm welcome to the " . htmlspecialchars($company_name) . " family and look forward to a mutually fulfilling association.\n\n"
        . "Warm regards,\n\n"
        . htmlspecialchars($company_name) . "\n"
        . "Human Resources & Talent Acquisition\n";

    $full_text = $full_letter;

    // Stream words progressively
    $words = explode(" ", $full_letter);
    $batch = '';
    foreach ($words as $idx => $word) {
        $batch .= $word . ' ';
        if ($idx % 3 === 0 || $idx === count($words) - 1) {
            sse_send(['token' => $batch]);
            $batch = '';
            usleep(25000); // 25ms natural stream pacing
        }
    }
}

// Persist the generated letter as a new version
$save_res = save_appointment_letter_version(
    $con,
    $company_id,
    $candidate_id,
    $app_id,
    $job_id,
    $full_text,
    $subject,
    'AI_GENERATED',
    $joining_date,
    $salary,
    $designation,
    $work_location,
    $company_id
);

if ($save_res['success']) {
    sse_send([
        'done' => true,
        'letter_id' => $save_res['letter_id'],
        'version_id' => $save_res['version_id'],
        'version_number' => $save_res['version_number'],
        'content' => $full_text
    ]);
} else {
    sse_send([
        'done' => true,
        'error' => 'Letter was generated, but failed to save version: ' . $save_res['error'],
        'content' => $full_text
    ]);
}
exit;
