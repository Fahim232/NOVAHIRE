<?php
/**
 * NovaHire — Company AI CV Screening API Endpoint
 *
 * Handles AJAX requests from company dashboard for:
 * - Getting CV analysis status & cached results
 * - Triggering AI CV analysis
 * - Triggering AI CV re-analysis (when outdated or requested)
 * - Updating screening status (Review, Shortlist, Reject)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
global $con;

header('Content-Type: application/json');

if (!isset($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$company_id = (int)$_SESSION['company_id'];

// Backend Company Verification Check
require_once __DIR__ . '/../includes/company_verification.php';
if (!is_company_verified($con, $company_id)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Company verification required to access AI candidate screening.',
        'verification_required' => true,
        'verification_url' => BASE_URL . '/company/verification.php'
    ]);
    exit;
}

$action = $_REQUEST['action'] ?? 'get_status';
$app_id = intval($_REQUEST['application_id'] ?? ($_REQUEST['app_id'] ?? 0));

if ($app_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid application ID required.']);
    exit;
}

// Anti-IDOR Authorization Check
$auth = nh_verify_company_application_access($con, $company_id, $app_id);
if (!$auth['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $auth['error'] ?? 'Unauthorized access to application.']);
    exit;
}

switch ($action) {
    case 'get_status':
        $status_data = nh_get_application_analysis_status($con, $company_id, $app_id);
        echo json_encode([
            'success'         => true,
            'status'          => $status_data['status'],
            'is_outdated'     => $status_data['is_outdated'],
            'outdated_reason' => $status_data['outdated_reason'],
            'analysis'        => $status_data['analysis']
        ]);
        break;

    case 'analyze':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST method required.']);
            exit;
        }
        $res = nh_analyze_application_cv($con, $company_id, $app_id, false);
        if ($res['success']) {
            echo json_encode([
                'success'  => true,
                'status'   => 'COMPLETED',
                'analysis' => $res['analysis'],
                'message'  => 'AI CV analysis completed successfully.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'status'  => 'FAILED',
                'error'   => $res['error'] ?? 'AI CV analysis could not be completed.'
            ]);
        }
        break;

    case 'reanalyze':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST method required.']);
            exit;
        }
        $res = nh_reanalyze_application_cv($con, $company_id, $app_id);
        if ($res['success']) {
            echo json_encode([
                'success'  => true,
                'status'   => 'COMPLETED',
                'analysis' => $res['analysis'],
                'message'  => 'AI CV re-analysis completed successfully.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'status'  => 'FAILED',
                'error'   => $res['error'] ?? 'AI CV re-analysis could not be completed.'
            ]);
        }
        break;

    case 'update_status':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST method required.']);
            exit;
        }
        $new_status = trim($_POST['status'] ?? '');
        $res = nh_update_screening_status($con, $company_id, $app_id, $new_status);
        if ($res['success']) {
            echo json_encode([
                'success'    => true,
                'new_status' => $new_status,
                'message'    => 'Application status updated to ' . ucfirst($new_status),
                'schedule_interview_url' => ($new_status === 'shortlisted') ? BASE_URL . "/company/schedule_interview.php?application_id=$app_id" : null
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => $res['error'] ?? 'Failed to update application status.'
            ]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        break;
}
