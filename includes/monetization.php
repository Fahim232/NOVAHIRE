<?php
/**
 * NovaHire — Monetization & Pro Subscriptions
 */

if (defined('NOVAHIRE_MONETIZATION_LOADED')) return;
define('NOVAHIRE_MONETIZATION_LOADED', true);

if (!isset($con)) {
    require_once __DIR__ . '/../admin/dbcon.php';
}

/**
 * Table existence helper
 */
function nh_table_exists($con, $table) {
    if (!$con) return false;
    $res = @mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $table) . "'");
    return ($res && mysqli_num_rows($res) > 0);
}

/**
 * Platform pricing configuration
 */
function nh_pricing() {
    return [
        'pro_price' => 499.00,
        'pro_duration_days' => 30,
        'certificate_price' => 299.00,
        'featured_price' => 999.00,
        'featured_days' => 14,
        'session_commission_pct' => 15,
        'currency' => 'BDT',
    ];
}

/**
 * Pro subscription plan configuration
 */
function nh_pro_plan() {
    $p = nh_pricing();
    return [
        'name' => 'NovaHire Pro',
        'price' => $p['pro_price'],
        'duration_days' => $p['pro_duration_days'],
        'currency' => $p['currency'],
        'features' => [
            'Unlimited AI Resume Analysis',
            'Unlimited AI Mock Interviews',
            'AI Career Path & Skill Gap Coach',
            'Free Skill Verification Certificates',
            'Priority Application Ranking',
            'Verified Pro Badge on Profile'
        ]
    ];
}

/**
 * Format price with BDT currency symbol
 */
function nh_price($amount) {
    if ($amount <= 0) return 'Free';
    return '৳' . number_format($amount, 0);
}

/**
 * Calculate session fee split between platform and mentor
 */
function nh_session_split($price) {
    $commission_rate = 0.15; // 15% platform commission
    $commission = round($price * $commission_rate, 2);
    $mentor_earning = round($price - $commission, 2);
    return [
        'price' => $price,
        'commission' => $commission,
        'mentor_earning' => $mentor_earning,
        'rate' => $commission_rate
    ];
}

/**
 * Get active user subscription
 */
function get_user_subscription($con, $user_id) {
    if (!$con || !$user_id) return null;
    if (!nh_table_exists($con, 'user_subscriptions')) return null;
    $stmt = mysqli_prepare($con, "SELECT * FROM user_subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }
    return null;
}

/**
 * Check if a job seeker has active NovaHire Pro status
 */
function is_user_pro($con, $user_id) {
    if (!$con || !$user_id) return false;
    
    // 1. Check active user_subscriptions table
    if (get_user_subscription($con, $user_id) !== null) {
        return true;
    }
    
    // 2. Check user_info flag if column exists
    $check = @mysqli_query($con, "SHOW COLUMNS FROM user_info LIKE 'is_pro'");
    if ($check && mysqli_num_rows($check) > 0) {
        $stmt = mysqli_prepare($con, "SELECT is_pro FROM user_info WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if (!empty($row['is_pro'])) return true;
        }
    }
    
    // 3. Check payments table for active pro subscription
    if (nh_table_exists($con, 'payments')) {
        $stmt = mysqli_prepare($con, "SELECT id FROM payments WHERE payer_type = 'user' AND payer_id = ? AND purpose = 'pro_subscription' AND status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $has_pro = mysqli_num_rows($res) > 0;
            mysqli_stmt_close($stmt);
            return $has_pro;
        }
    }
    
    return false;
}

/**
 * Check if live payment gateway is active (demo mode otherwise)
 */
function nh_gateway_live() {
    return (!empty($_ENV['SSLCOMMERZ_STORE_ID']) && !empty($_ENV['SSLCOMMERZ_STORE_PASS']));
}


/**
 * Get single certificate details
 */
function nh_get_certificate($con, $cert_id) {
    if (!$con || !$cert_id) return null;
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'certificates'");
    if ($check && mysqli_num_rows($check) > 0) {
        $stmt = mysqli_prepare($con, "SELECT * FROM certificates WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $cert_id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            return $row;
        }
    }
    return null;
}

/**
 * Get all certificates for a user
 */
function nh_get_user_certificates($con, $user_id) {
    if (!$con || !$user_id) return [];
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'certificates'");
    if ($check && mysqli_num_rows($check) > 0) {
        $stmt = mysqli_prepare($con, "SELECT * FROM certificates WHERE user_id = ? ORDER BY id DESC");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $list = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $list[] = $row;
            }
            mysqli_stmt_close($stmt);
            return $list;
        }
    }
    return [];
}

/**
 * Get categories where user passed quiz and is eligible for certificate
 */
function nh_user_eligible_categories($con, $user_id) {
    if (!$con || !$user_id) return [];
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'quiz_results'");
    if ($check && mysqli_num_rows($check) > 0) {
        $stmt = mysqli_prepare($con, "SELECT DISTINCT category FROM quiz_results WHERE user_id = ? AND score >= 70");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $cats = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $cats[] = $row['category'];
            }
            mysqli_stmt_close($stmt);
            return $cats;
        }
    }
    return [];
}
