<?php
/**
 * NovaHire — Certificates Engine
 * Handles skill certificate issuance, verification, and revenue calculations.
 */

if (defined('NOVAHIRE_CERTIFICATES_LOADED')) return;
define('NOVAHIRE_CERTIFICATES_LOADED', true);

if (!isset($con)) {
    require_once __DIR__ . '/bootstrap.php';
}
if (!function_exists('is_user_pro')) {
    require_once __DIR__ . '/monetization.php';
}

/**
 * Return formatted display title for a skill certificate
 */
function nh_cert_title($category) {
    $category = trim($category);
    return 'Certified ' . $category . ' Professional';
}

/**
 * Generate a unique, readable certificate verification code
 */
function nh_generate_cert_code() {
    return 'NH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4));
}

/**
 * Issue or prepare a skill certificate for a user
 * Returns ['status' => 'issued'|'payment'|'error', 'id' => int]
 */
function nh_issue_certificate($con, $user_id, $category) {
    if (!$con || !$user_id || empty($category)) {
        return ['status' => 'invalid_params'];
    }

    // Check if certificate already issued for this user & category
    $stmt = mysqli_prepare($con, "SELECT id, is_paid FROM certificates WHERE user_id = ? AND category = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "is", $user_id, $category);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $existing = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if ($existing) {
            if ($existing['is_paid']) {
                return ['status' => 'issued', 'id' => (int)$existing['id']];
            } else {
                if (is_user_pro($con, $user_id)) {
                    // Automatically mark paid for Pro user
                    @mysqli_query($con, "UPDATE certificates SET is_paid = 1 WHERE id = " . (int)$existing['id']);
                    return ['status' => 'issued', 'id' => (int)$existing['id']];
                }
                return ['status' => 'payment', 'id' => (int)$existing['id']];
            }
        }
    }

    // Determine highest score achieved in this category
    $score = 85; // Default passing grade
    $score_q = mysqli_prepare($con, "SELECT score FROM quiz_results WHERE user_id = ? AND category = ? ORDER BY score DESC LIMIT 1");
    if ($score_q) {
        mysqli_stmt_bind_param($score_q, "is", $user_id, $category);
        mysqli_stmt_execute($score_q);
        $sres = mysqli_stmt_get_result($score_q);
        if ($srow = mysqli_fetch_assoc($sres)) {
            $score = max($score, (int)$srow['score']);
        }
        mysqli_stmt_close($score_q);
    }

    $title = nh_cert_title($category);
    $cert_code = nh_generate_cert_code();
    $is_pro = is_user_pro($con, $user_id);
    $is_paid = $is_pro ? 1 : 0;

    $ins = mysqli_prepare($con, "INSERT INTO certificates (user_id, category, title, cert_code, score, is_paid, issued_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    if ($ins) {
        mysqli_stmt_bind_param($ins, "isssii", $user_id, $category, $title, $cert_code, $score, $is_paid);
        if (mysqli_stmt_execute($ins)) {
            $cert_id = mysqli_insert_id($con);
            mysqli_stmt_close($ins);
            return [
                'status' => $is_paid ? 'issued' : 'payment',
                'id' => (int)$cert_id,
                'cert_code' => $cert_code
            ];
        }
        mysqli_stmt_close($ins);
    }

    return ['status' => 'db_error'];
}

/**
 * Calculate certificate revenue metrics for admin dashboard
 */
function nh_certificate_revenue($con) {
    if (!$con || !nh_table_exists($con, 'certificates')) {
        return ['issued' => 0, 'paid_count' => 0, 'revenue' => 0.0];
    }
    
    $r = @mysqli_query($con, "SELECT COUNT(*) as issued, SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count FROM certificates");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $issued = (int)$row['issued'];
        $paid_count = (int)$row['paid_count'];
        $price = nh_pricing()['certificate_price'];
        return [
            'issued' => $issued,
            'paid_count' => $paid_count,
            'revenue' => $paid_count * $price
        ];
    }

    return ['issued' => 0, 'paid_count' => 0, 'revenue' => 0.0];
}
