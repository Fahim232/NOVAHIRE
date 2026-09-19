<?php
/**
 * NovaHire — Core Bootstrap
 * Centralised setup so pages stop repeating boilerplate:
 *   1. BASE_URL  — absolute URL path to the project (works from any folder depth)
 *   2. Session   — starts a PHP session once
 *   3. Database  — loads the global $con connection (admin/dbcon.php)
 *   4. Auth guard helpers — require_seeker_login(), require_admin_login()
 *
 * Usage (top of every page):
 *   require_once __DIR__ . '/../includes/bootstrap.php';
 *   require_seeker_login();
 */

if (defined('NOVAHIRE_BOOTSTRAP')) return;
define('NOVAHIRE_BOOTSTRAP', true);

/* ── 1. BASE_URL ────────────────────────────────────────────────────────────
 * Absolute URL path to the project root, e.g. "/Job-portal-and-grooming".
 * Works regardless of how deep the current page is (root, seeker/, auth/, ...)
 * so asset links and cross-folder links never break when pages are moved.
 */
function app_base_url() {
    static $base = null;
    if ($base === null) {
        $docroot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '.')), '/');
        $appdir  = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'); // this file lives in includes/ -> project root
        if ($docroot && $appdir !== $docroot && strpos($appdir, $docroot) === 0) {
            $base = substr($appdir, strlen($docroot));
        } else {
            $base = '';
        }
    }
    return $base;
}
if (!defined('BASE_URL')) define('BASE_URL', app_base_url());

// Ensure PHP timezone matches MySQL server (+06:00 Asia/Dhaka)
date_default_timezone_set('Asia/Dhaka');

/* ── 2. Session ───────────────────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', 1);
    @ini_set('session.use_strict_mode', 1);
    @ini_set('session.use_only_cookies', 1);
    @ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

/* ── 2b. Error display (local development friendly) ───────────────────────── */
if (!defined('NOVAHIRE_DEBUG')) {
    // Enable debug / error display on localhost / local IP
    $is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1']);
    define('NOVAHIRE_DEBUG', $is_local);
}

if (NOVAHIRE_DEBUG) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

/* ── 3. Database ──────────────────────────────────────────────────────────── */
if (!isset($con) && file_exists(__DIR__ . '/../admin/dbcon.php')) {
    require_once __DIR__ . '/../admin/dbcon.php';
}

/* ── 4. Security ──────────────────────────────────────────────────────────── */
if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';
    if (!headers_sent() && function_exists('set_security_headers')) {
        set_security_headers();
    }
}

/* ── 5. Shared helpers ────────────────────────────────────────────────────── */
if (!function_exists('create_notification') && file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

/* ── 6. Email System ──────────────────────────────────────────────────────── */
if (!function_exists('send_email') && file_exists(__DIR__ . '/mail.php')) {
    require_once __DIR__ . '/mail.php';
}

/* ── 7. Payment System ────────────────────────────────────────────────────── */
if (!function_exists('get_subscription_plans') && file_exists(__DIR__ . '/payment.php')) {
    require_once __DIR__ . '/payment.php';
}

/* ── 7b. Monetization & Feature Gating ─────────────────────────────────────── */
if (!function_exists('nh_pricing') && file_exists(__DIR__ . '/monetization.php')) {
    require_once __DIR__ . '/monetization.php';
}
if (!function_exists('nh_check_access') && file_exists(__DIR__ . '/premium.php')) {
    require_once __DIR__ . '/premium.php';
}

/* ── 7c. Placement engine (recommendations, pipeline, placements) ───────────── */
if (!function_exists('nh_get_recommendations') && file_exists(__DIR__ . '/placement.php')) {
    require_once __DIR__ . '/placement.php';
}
if (!function_exists('create_job_alert_notifications') && file_exists(__DIR__ . '/job_alerts.php')) {
    require_once __DIR__ . '/job_alerts.php';
}

/* ── 7d. Live grooming sessions (mentor marketplace) ────────────────────────── */
if (!function_exists('nh_confirm_session') && file_exists(__DIR__ . '/sessions.php')) {
    require_once __DIR__ . '/sessions.php';
}

/* ── 7e. Certificates (verifiable skill certificates) ───────────────────────── */
if (!function_exists('nh_issue_certificate') && file_exists(__DIR__ . '/certificates.php')) {
    require_once __DIR__ . '/certificates.php';
}

/* ── 8. Advanced Search ───────────────────────────────────────────────────── */
if (!function_exists('execute_job_search') && file_exists(__DIR__ . '/search.php')) {
    require_once __DIR__ . '/search.php';
}

/* ── 9. Resume Builder ───────────────────────────────────────────────────── */
if (!function_exists('get_resume_data') && file_exists(__DIR__ . '/resume_builder.php')) {
    require_once __DIR__ . '/resume_builder.php';
}

/* ── 5. Auth guards ───────────────────────────────────────────────────────── */
function require_seeker_login() {
    global $con;
    if (!isset($_SESSION['id'])) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
    if (isset($con) && $con) {
        $check = mysqli_query($con, "SELECT id FROM user_info WHERE id = " . (int)$_SESSION['id']);
        if (!$check || mysqli_num_rows($check) === 0) {
            unset($_SESSION['id'], $_SESSION['username'], $_SESSION['email']);
            header('Location: ' . BASE_URL . '/auth/login.php?error=' . urlencode('Session expired. Please log in again.'));
            exit;
        }
    }
}

function require_company_login() {
    global $con;
    if (!isset($_SESSION['company_id'])) {
        header('Location: ' . BASE_URL . '/auth/login.php?role=recruiter');
        exit;
    }
    if (isset($con) && $con) {
        $check = mysqli_query($con, "SELECT id FROM companies WHERE id = " . (int)$_SESSION['company_id']);
        if (!$check || mysqli_num_rows($check) === 0) {
            unset($_SESSION['company_id'], $_SESSION['company_name'], $_SESSION['company_email']);
            header('Location: ' . BASE_URL . '/auth/login.php?role=recruiter&error=' . urlencode('Session expired. Please log in again.'));
            exit;
        }
    }
}

function require_mentor_login() {
    if (!isset($_SESSION['mentor_id'])) {
        header('Location: ' . BASE_URL . '/mentor/login.php');
        exit;
    }
}

function require_admin_login() {
    if (!isset($_SESSION['admin_username'])) {
        header('Location: ' . BASE_URL . '/admin/admin_login.php');
        exit;
    }
}
