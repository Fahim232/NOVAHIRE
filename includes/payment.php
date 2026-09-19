<?php
/**
 * NovaHire — Payment & Subscription System
 */

if (defined('NOVAHIRE_PAYMENT_LOADED')) return;
define('NOVAHIRE_PAYMENT_LOADED', true);

if (!isset($con)) {
    require_once __DIR__ . '/../admin/dbcon.php';
}

/**
 * Format price in BDT currency
 */
function format_price($amount) {
    if ($amount <= 0) return 'Free';
    return '৳' . number_format($amount);
}

/**
 * Get available employer subscription plans
 */
function get_subscription_plans() {
    return [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'duration' => 365,
            'popular' => false,
            'job_posts_limit' => 3,
            'features' => [
                'Post up to 3 jobs',
                'Basic candidate screening',
                'Email notifications',
                'Standard support'
            ]
        ],
        'basic' => [
            'name' => 'Basic',
            'price' => 1499,
            'duration' => 30,
            'popular' => false,
            'job_posts_limit' => 10,
            'features' => [
                'Post up to 10 jobs',
                'Featured job listings (1 job)',
                'Candidate filtering & ranking',
                'Live chat messaging',
                'Priority email support'
            ]
        ],
        'pro' => [
            'name' => 'Professional',
            'price' => 3499,
            'duration' => 30,
            'popular' => true,
            'job_posts_limit' => 30,
            'features' => [
                'Post up to 30 jobs',
                'Featured job listings (5 jobs)',
                'AI resume matching & score',
                'Talent pool candidate search',
                'Video interview scheduling',
                '24/7 Priority support'
            ]
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price' => 7999,
            'duration' => 30,
            'popular' => false,
            'job_posts_limit' => 9999,
            'features' => [
                'Unlimited job postings',
                'Unlimited featured jobs',
                'Full AI candidate evaluation',
                'Direct mentor marketplace access',
                'Custom employer branding',
                'Dedicated account manager'
            ]
        ]
    ];
}

/**
 * Get active subscription for a company
 */
function get_company_subscription($con, $company_id) {
    if (!$con || !$company_id) return null;
    
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'company_subscriptions'");
    if (!$check || mysqli_num_rows($check) == 0) return null;
    
    $stmt = mysqli_prepare($con, "SELECT * FROM company_subscriptions WHERE company_id = ? AND status = 'active' AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $company_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        return $row;
    }
    return null;
}

/**
 * Get active plan slug for a company (default 'free')
 */
function get_company_plan($con, $company_id) {
    $sub = get_company_subscription($con, $company_id);
    return $sub['plan_type'] ?? 'free';
}

/**
 * Get payment history for a company
 */
function get_payment_history($con, $company_id, $limit = 10) {
    if (!$con || !$company_id) return [];
    
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'payments'");
    if (!$check || mysqli_num_rows($check) == 0) return [];
    
    $stmt = mysqli_prepare($con, "SELECT * FROM payments WHERE (company_id = ? OR (payer_type = 'company' AND payer_id = ?)) ORDER BY created_at DESC LIMIT ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iii", $company_id, $company_id, $limit);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $list = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $list[] = $row;
        }
        mysqli_stmt_close($stmt);
        return $list;
    }
    return [];
}

/**
 * Initialize SSLCOMMERZ payment record
 */
function init_sslcommerz_payment($con, $company_id, $plan_type, $amount) {
    $payment_id = 'PAY_' . strtoupper(bin2hex(random_bytes(8)));
    
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'payments'");
    if ($check && mysqli_num_rows($check) > 0) {
        $stmt = mysqli_prepare($con, "INSERT INTO payments (payment_id, company_id, plan_type, amount, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sisd", $payment_id, $company_id, $plan_type, $amount);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    return [
        'payment_id' => $payment_id,
        'amount' => $amount,
        'plan_type' => $plan_type
    ];
}

/**
 * Verify SSLCOMMERZ payment
 */
function verify_sslcommerz_payment($val_id, $store_id = '', $store_pass = '') {
    // In demo / development environment, treat payment as valid
    return [
        'status' => 'VALID',
        'val_id' => $val_id
    ];
}

/**
 * Activate company subscription after payment
 */
function activate_subscription($con, $company_id, $plan_type, $payment_id) {
    if (!$con || !$company_id) return false;
    
    $plans = get_subscription_plans();
    $duration = $plans[$plan_type]['duration'] ?? 30;
    
    // Update payment record
    $stmt = mysqli_prepare($con, "UPDATE payments SET status = 'completed', completed_at = NOW() WHERE payment_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $payment_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    
    // Check company_subscriptions table
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'company_subscriptions'");
    if ($check && mysqli_num_rows($check) > 0) {
        // Expire any existing active subscriptions
        $expire_stmt = mysqli_prepare($con, "UPDATE company_subscriptions SET status = 'expired' WHERE company_id = ? AND status = 'active'");
        if ($expire_stmt) {
            mysqli_stmt_bind_param($expire_stmt, "i", $company_id);
            mysqli_stmt_execute($expire_stmt);
            mysqli_stmt_close($expire_stmt);
        }
        
        // Insert new active subscription
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
        $ins = mysqli_prepare($con, "INSERT INTO company_subscriptions (company_id, plan_type, status, starts_at, expires_at, created_at) VALUES (?, ?, 'active', NOW(), ?, NOW())");
        if ($ins) {
            mysqli_stmt_bind_param($ins, "iss", $company_id, $plan_type, $expires_at);
            $res = mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            return $res;
        }
    }
    return true;
}

/**
 * Check if a company can post another job under their active plan
 */
function can_post_job($con, $company_id) {
    if (!$con || !$company_id) return true;
    $plan_slug = get_company_plan($con, $company_id);
    $plans = get_subscription_plans();
    $limit = $plans[$plan_slug]['job_posts_limit'] ?? 3;
    if ($limit >= 9999) return true;

    $stmt = @mysqli_prepare($con, "SELECT COUNT(*) FROM company_jobs WHERE company_id = ? AND status != 'archived'");
    if (!$stmt) {
        $stmt = @mysqli_prepare($con, "SELECT COUNT(*) FROM company_jobs WHERE company_id = ?");
    }
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $company_id);
        mysqli_stmt_execute($stmt);
        $count = 0;
        mysqli_stmt_bind_result($stmt, $count);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        return $count < $limit;
    }
    return true;
}

/**
 * Create a generalized payment record (for users or companies)
 *
 * @param mysqli $con
 * @param string $payer_type 'user' | 'company'
 * @param int $payer_id user_id or company_id
 * @param string $purpose 'subscription' | 'pro_subscription' | 'session' | 'certificate' | 'featured_job' | 'placement_fee' | 'other'
 * @param float $amount
 * @param array $meta Optional metadata: item_id, plan_type, method, currency
 * @return string|false Generated payment_id (e.g. PAY_XXXX) or false on failure
 */
function create_payment($con, $payer_type, $payer_id, $purpose, $amount, $meta = []) {
    if (!$con || !$payer_id || $amount <= 0) return false;

    $payment_id = 'PAY_' . strtoupper(bin2hex(random_bytes(8)));
    $item_id    = isset($meta['item_id']) && $meta['item_id'] !== null ? (int)$meta['item_id'] : null;
    $plan_type  = !empty($meta['plan_type']) ? (string)$meta['plan_type'] : null;
    $method     = !empty($meta['method']) ? (string)$meta['method'] : 'sslcommerz';
    $currency   = !empty($meta['currency']) ? (string)$meta['currency'] : 'BDT';
    $company_id = ($payer_type === 'company') ? (int)$payer_id : null;

    // Check if payments table exists
    $check = @mysqli_query($con, "SHOW TABLES LIKE 'payments'");
    if (!$check || mysqli_num_rows($check) == 0) {
        return false;
    }

    $stmt = @mysqli_prepare($con, "INSERT INTO payments 
        (payment_id, payer_type, payer_id, company_id, purpose, item_id, plan_type, amount, currency, payment_method, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ssiisssdss", 
            $payment_id, 
            $payer_type, 
            $payer_id, 
            $company_id, 
            $purpose, 
            $item_id, 
            $plan_type, 
            $amount, 
            $currency, 
            $method
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if ($ok) return $payment_id;
    }

    // Backward-compatibility fallback for older schema
    $stmt2 = @mysqli_prepare($con, "INSERT INTO payments (payment_id, company_id, plan_type, amount, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    if ($stmt2) {
        $cid = $company_id ?: 0;
        $ptype = $plan_type ?: $purpose;
        mysqli_stmt_bind_param($stmt2, "sisd", $payment_id, $cid, $ptype, $amount);
        $ok = mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);
        return $ok ? $payment_id : false;
    }

    return false;
}

/**
 * Retrieve a payment record by payment_id
 *
 * @param mysqli $con
 * @param string|int $payment_id
 * @return array|null
 */
function get_payment($con, $payment_id) {
    if (!$con || !$payment_id) return null;

    $stmt = @mysqli_prepare($con, "SELECT * FROM payments WHERE payment_id = ? LIMIT 1");
    if ($stmt) {
        $pid_str = (string)$payment_id;
        mysqli_stmt_bind_param($stmt, "s", $pid_str);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $payment = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if ($payment) return $payment;
    }

    if (is_numeric($payment_id)) {
        $stmt = @mysqli_prepare($con, "SELECT * FROM payments WHERE id = ? LIMIT 1");
        if ($stmt) {
            $id = (int)$payment_id;
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $payment = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if ($payment) return $payment;
        }
    }

    return null;
}

/**
 * Mark payment record as completed
 *
 * @param mysqli $con
 * @param string $payment_id
 * @return bool
 */
function mark_payment_completed($con, $payment_id) {
    if (!$con || !$payment_id) return false;

    $stmt = @mysqli_prepare($con, "UPDATE payments SET status = 'completed', completed_at = NOW() WHERE payment_id = ? OR id = ?");
    if ($stmt) {
        $pid_str = (string)$payment_id;
        $id = is_numeric($payment_id) ? (int)$payment_id : 0;
        mysqli_stmt_bind_param($stmt, "si", $pid_str, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
    return false;
}

/**
 * Fulfill the purchased product/service upon payment completion
 *
 * @param mysqli $con
 * @param array $payment Payment record from payments table
 * @return array ['success' => bool, 'redirect' => string, 'message' => string]
 */
function fulfill_payment($con, $payment) {
    $base = defined('BASE_URL') ? BASE_URL : '';

    if (!$con || empty($payment)) {
        return [
            'success' => false,
            'redirect' => $base . '/index.php',
            'message' => 'Invalid payment data.'
        ];
    }

    $purpose    = $payment['purpose'] ?? 'subscription';
    $payer_type = $payment['payer_type'] ?? 'company';
    $payer_id   = (int)($payment['payer_id'] ?? $payment['company_id'] ?? 0);
    $item_id    = !empty($payment['item_id']) ? (int)$payment['item_id'] : null;
    $plan_type  = $payment['plan_type'] ?? null;
    $payment_id = $payment['payment_id'] ?? '';
    $amount     = (float)($payment['amount'] ?? 0);

    switch ($purpose) {
        case 'pro_subscription':
            // 1. Expire existing active user subscriptions
            $exp = @mysqli_prepare($con, "UPDATE user_subscriptions SET status = 'expired' WHERE user_id = ? AND status = 'active'");
            if ($exp) {
                mysqli_stmt_bind_param($exp, "i", $payer_id);
                mysqli_stmt_execute($exp);
                mysqli_stmt_close($exp);
            }

            // 2. Insert new user subscription
            $duration = 30;
            if (function_exists('nh_pricing')) {
                $p = nh_pricing();
                $duration = (int)($p['pro_duration_days'] ?? 30);
            }
            $ins = @mysqli_prepare($con, "INSERT INTO user_subscriptions 
                (user_id, plan_type, payment_id, starts_at, expires_at, status, created_at) 
                VALUES (?, 'pro', ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), 'active', NOW())");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "isi", $payer_id, $payment_id, $duration);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }

            // 3. Update is_pro in user_info if column exists
            $check_col = @mysqli_query($con, "SHOW COLUMNS FROM user_info LIKE 'is_pro'");
            if ($check_col && mysqli_num_rows($check_col) > 0) {
                @mysqli_query($con, "UPDATE user_info SET is_pro = 1 WHERE id = " . (int)$payer_id);
            }

            // 4. Send notification
            if (function_exists('create_notification')) {
                create_notification($con, 'user', $payer_id, 'system', null,
                    'NovaHire Pro Activated 👑',
                    'Welcome to NovaHire Pro! You now enjoy unlimited AI tools, priority ranking, and free certificates for ' . $duration . ' days.',
                    'system', 'user_subscriptions', $payer_id);
            }

            if (function_exists('create_activity_log')) {
                create_activity_log('user', $payer_id, 'pro_subscription_activated', [
                    'payment_id' => $payment_id,
                    'amount' => $amount
                ]);
            }

            return [
                'success' => true,
                'redirect' => $base . '/seeker/pro.php?success=1',
                'message' => 'NovaHire Pro subscription activated successfully!'
            ];

        case 'certificate':
            if ($item_id) {
                $upd = @mysqli_prepare($con, "UPDATE certificates SET is_paid = 1, payment_id = ? WHERE id = ?");
                if ($upd) {
                    mysqli_stmt_bind_param($upd, "si", $payment_id, $item_id);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                }

                if (function_exists('create_notification')) {
                    create_notification($con, 'user', $payer_id, 'system', null,
                        'Certificate Verified & Unlocked 🎓',
                        'Your skill verification certificate has been unlocked and is ready to share!',
                        'system', 'certificates', $item_id);
                }

                if (function_exists('create_activity_log')) {
                    create_activity_log('user', $payer_id, 'certificate_paid', [
                        'certificate_id' => $item_id,
                        'payment_id' => $payment_id,
                        'amount' => $amount
                    ]);
                }
            }

            return [
                'success' => true,
                'redirect' => $base . '/seeker/certificates.php?issued=1',
                'message' => 'Certificate unlocked successfully!'
            ];

        case 'session':
            if ($item_id && function_exists('nh_confirm_session')) {
                nh_confirm_session($con, $item_id, $payment_id);
            } else if ($item_id) {
                $upd = @mysqli_prepare($con, "UPDATE grooming_sessions SET status = 'confirmed', payment_id = ? WHERE id = ?");
                if ($upd) {
                    mysqli_stmt_bind_param($upd, "si", $payment_id, $item_id);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                }
            }

            if (function_exists('create_activity_log')) {
                create_activity_log('user', $payer_id, 'session_booked', [
                    'session_id' => $item_id,
                    'payment_id' => $payment_id,
                    'amount' => $amount
                ]);
            }

            return [
                'success' => true,
                'redirect' => $base . '/seeker/my_sessions.php?booked=1',
                'message' => 'Session confirmed and booked successfully!'
            ];

        case 'featured_job':
            if ($item_id) {
                $featured_days = 14;
                if (function_exists('nh_pricing')) {
                    $p = nh_pricing();
                    $featured_days = (int)($p['featured_days'] ?? 14);
                }

                // Ensure columns exist on company_jobs
                $cols = [];
                $cres = @mysqli_query($con, "SHOW COLUMNS FROM company_jobs");
                if ($cres) {
                    while ($r = mysqli_fetch_assoc($cres)) $cols[] = $r['Field'];
                }
                if (!in_array('is_featured', $cols)) {
                    @mysqli_query($con, "ALTER TABLE company_jobs ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!in_array('featured_until', $cols)) {
                    @mysqli_query($con, "ALTER TABLE company_jobs ADD COLUMN featured_until DATETIME DEFAULT NULL");
                }

                $upd = @mysqli_prepare($con, "UPDATE company_jobs SET is_featured = 1, featured_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?");
                if ($upd) {
                    mysqli_stmt_bind_param($upd, "ii", $featured_days, $item_id);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                }

                if (function_exists('create_notification')) {
                    create_notification($con, 'company', $payer_id, 'system', null,
                        'Job Boosted to Featured 🚀',
                        'Your job listing has been boosted and will appear at the top of searches for ' . $featured_days . ' days.',
                        'system', 'company_jobs', $item_id);
                }

                if (function_exists('create_activity_log')) {
                    create_activity_log('company', $payer_id, 'job_boosted_featured', [
                        'job_id' => $item_id,
                        'payment_id' => $payment_id,
                        'amount' => $amount
                    ]);
                }
            }

            return [
                'success' => true,
                'redirect' => $base . '/company/my_jobs.php?featured=1',
                'message' => 'Job boosted to Featured successfully!'
            ];

        case 'subscription':
        default:
            $company_id = ($payer_type === 'company') ? $payer_id : (int)($payment['company_id'] ?? 0);
            $plan = $plan_type ?: 'basic';

            activate_subscription($con, $company_id, $plan, $payment_id);

            if (function_exists('create_notification')) {
                create_notification($con, 'company', $company_id, 'system', null,
                    'Subscription Activated ✅',
                    'Your company subscription (' . ucfirst($plan) . ' plan) has been activated.',
                    'system', 'company_subscriptions', $company_id);
            }

            if (function_exists('create_activity_log')) {
                create_activity_log('company', $company_id, 'subscription_purchased', [
                    'plan' => $plan,
                    'payment_id' => $payment_id,
                    'amount' => $amount
                ]);
            }

            return [
                'success' => true,
                'redirect' => $base . '/company/subscription.php?success=1&payment_id=' . urlencode($payment_id),
                'message' => 'Employer subscription activated successfully!'
            ];
    }
}

/**
 * Initialize bKash payment record
 */
function init_bkash_payment($con, $company_id, $plan_type, $amount) {
    return init_sslcommerz_payment($con, $company_id, $plan_type, $amount);
}

