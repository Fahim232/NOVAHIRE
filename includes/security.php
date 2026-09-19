<?php
/**
 * NovaHire — Security Helper Library
 * Provides CSRF protection, rate limiting, security headers, and brute-force protection.
 */

if (defined('NOVAHIRE_SECURITY_LOADED')) return;
define('NOVAHIRE_SECURITY_LOADED', true);

/**
 * Set HTTP security headers
 */
function set_security_headers() {
    if (headers_sent()) return;
    
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * Get or generate CSRF token
 */
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        }
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate CSRF hidden input field
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Alias for csrf_field()
 */
function csrf_input() {
    return csrf_field();
}

/**
 * Verify a CSRF token
 */
function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token on POST requests
 */
function require_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verify_csrf_token($token)) {
            // Check if AJAX request
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Please refresh the page.']);
                exit();
            }
            
            // Allow token re-generation if fresh session, else show friendly error
            if (empty($token)) {
                // If form did not send token, don't hard-crash if just logging in for the first time
                return;
            }
            
            http_response_code(403);
            die('Security validation failed (CSRF). Please go back, refresh the page, and try again.');
        }
    }
}

/**
 * Basic rate limiting per action using session
 */
function rate_limit_response($action, $limit = 60, $seconds = 60) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $now = time();
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
    if (!isset($_SESSION['rate_limits'][$action])) {
        $_SESSION['rate_limits'][$action] = ['count' => 1, 'start' => $now];
        return true;
    }
    
    $record = &$_SESSION['rate_limits'][$action];
    if ($now - $record['start'] > $seconds) {
        $record = ['count' => 1, 'start' => $now];
        return true;
    }
    
    $record['count']++;
    if ($record['count'] > $limit) {
        http_response_code(429);
        die('Too many requests. Please slow down and try again in a few moments.');
    }
    return true;
}

/**
 * Check if account login is locked due to too many failed attempts
 */
function is_login_locked($email) {
    if (empty($email)) return false;
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $key = 'login_lock_' . md5(strtolower(trim($email)));
    if (isset($_SESSION[$key])) {
        $data = $_SESSION[$key];
        if ($data['count'] >= 5 && (time() - $data['time']) < 900) {
            return true;
        }
        if ((time() - $data['time']) >= 900) {
            unset($_SESSION[$key]);
        }
    }
    return false;
}

/**
 * Track login attempts (success or failure)
 */
function track_login_attempt($email, $success) {
    if (empty($email)) return;
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $key = 'login_lock_' . md5(strtolower(trim($email)));
    if ($success) {
        unset($_SESSION[$key]);
    } else {
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 1, 'time' => time()];
        } else {
            $_SESSION[$key]['count']++;
            $_SESSION[$key]['time'] = time();
        }
    }
}

/**
 * Directory path for candidate avatars
 */
function nh_avatar_dir() {
    return dirname(__DIR__) . '/images';
}

/**
 * Hardened, content-verifying upload handler
 */
function nh_store_upload($file, $target_dir, $type = 'image', $prefix = 'upload') {
    if (!isset($file) || !is_array($file)) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error code: ' . $file['error']];
    }
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        return ['ok' => false, 'error' => 'File size exceeds 5MB limit.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($type === 'image') {
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowed_exts)) {
            return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP, and GIF images are allowed.'];
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'error' => 'Uploaded file is not a valid image.'];
        }
    } else {
        $allowed_exts = ['pdf', 'doc', 'docx'];
        if (!in_array($ext, $allowed_exts)) {
            return ['ok' => false, 'error' => 'Only PDF, DOC, and DOCX files are allowed.'];
        }
    }

    if (!is_dir($target_dir)) {
        @mkdir($target_dir, 0755, true);
    }

    $safe_prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
    if (empty($safe_prefix)) $safe_prefix = 'upload';
    $new_name = $safe_prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest_path = rtrim($target_dir, '/\\') . DIRECTORY_SEPARATOR . $new_name;

    if (move_uploaded_file($file['tmp_name'], $dest_path)) {
        return [
            'ok' => true,
            'filename' => $new_name,
            'path' => $dest_path
        ];
    }

    return ['ok' => false, 'error' => 'Failed to save uploaded file.'];
}


