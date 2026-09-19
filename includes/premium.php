<?php
/**
 * NovaHire — Feature Gating & Usage Tracking
 */

if (defined('NOVAHIRE_PREMIUM_LOADED')) return;
define('NOVAHIRE_PREMIUM_LOADED', true);

if (!isset($con)) {
    require_once __DIR__ . '/bootstrap.php';
}
if (!function_exists('is_user_pro')) {
    require_once __DIR__ . '/monetization.php';
}

/**
 * Check if a user has access to a specific premium or AI feature
 */
function nh_check_access($con, $user_id, $feature) {
    if (!$user_id) {
        return ['allowed' => false, 'reason' => 'login_required'];
    }
    
    // Pro members have unlimited access
    if (is_user_pro($con, $user_id)) {
        return ['allowed' => true, 'is_pro' => true, 'remaining' => 9999];
    }
    
    // Free tier daily limits
    $daily_limits = [
        'ai_resume_analyzer'         => 3,
        'ai_mock_interview'          => 2,
        'ai_grooming_coach'          => 5,
        'ai_assistant'               => 10,
        'ai_cover_letter_generator'  => 3,
        'resume_builder'             => 3,
        'skill_gap'                  => 5,
        'career_path'                => 5,
        'recommendations'            => 10,
        'company_job_quiz'           => 10,
        'company_job_application'    => 20,
        'grooming'                   => 10,
    ];
    
    $limit = $daily_limits[$feature] ?? 5;
    
    // Check usage today from user_activity_log or session
    $today = date('Y-m-d');
    if (!isset($_SESSION['feature_usage'])) $_SESSION['feature_usage'] = [];
    if (!isset($_SESSION['feature_usage'][$today])) $_SESSION['feature_usage'][$today] = [];
    
    $used = $_SESSION['feature_usage'][$today][$feature] ?? 0;
    
    if ($used >= $limit) {
        return [
            'allowed' => false,
            'reason' => 'limit_reached',
            'limit' => $limit,
            'used' => $used,
            'remaining' => 0
        ];
    }
    
    return [
        'allowed' => true,
        'is_pro' => false,
        'limit' => $limit,
        'used' => $used,
        'remaining' => max(0, $limit - $used)
    ];
}

/**
 * Track usage of a feature
 */
function nh_track_usage($con, $user_id, $feature) {
    $today = date('Y-m-d');
    if (!isset($_SESSION['feature_usage'])) $_SESSION['feature_usage'] = [];
    if (!isset($_SESSION['feature_usage'][$today])) $_SESSION['feature_usage'][$today] = [];
    
    if (!isset($_SESSION['feature_usage'][$today][$feature])) {
        $_SESSION['feature_usage'][$today][$feature] = 1;
    } else {
        $_SESSION['feature_usage'][$today][$feature]++;
    }
}

/**
 * Render a Pro gate / paywall modal or view
 */
function nh_render_pro_gate($feature) {
    $feature_name = ucwords(str_replace('_', ' ', $feature));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>NovaHire Pro Feature</title>
        <?php if (file_exists(__DIR__ . '/links.php')) include __DIR__ . '/links.php'; ?>
        <style>
            .gate-wrapper {
                min-height: 80vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 40px 20px;
            }
            .gate-card {
                background: var(--bg-card, #fff);
                border: 1px solid var(--border-light, #e2e8f0);
                border-radius: 24px;
                padding: 50px 40px;
                max-width: 540px;
                width: 100%;
                text-align: center;
                box-shadow: 0 20px 50px rgba(0,0,0,0.08);
            }
            .gate-badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: linear-gradient(135deg, #1a56db, #0ea5e9);
                color: #fff;
                padding: 6px 18px;
                border-radius: 50px;
                font-size: 0.85rem;
                font-weight: 700;
                margin-bottom: 20px;
            }
            .gate-title {
                font-size: 1.8rem;
                font-weight: 800;
                color: var(--text, #0f172a);
                margin-bottom: 12px;
            }
            .gate-desc {
                color: var(--text-muted, #64748b);
                font-size: 1rem;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .gate-btn {
                display: inline-block;
                width: 100%;
                background: linear-gradient(135deg, #1a56db, #0ea5e9);
                color: #fff;
                font-weight: 700;
                padding: 14px 28px;
                border-radius: 12px;
                text-decoration: none;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .gate-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 20px rgba(14, 165, 233, 0.3);
                color: #fff;
            }
            .gate-back {
                display: inline-block;
                margin-top: 15px;
                color: var(--text-muted, #64748b);
                text-decoration: none;
                font-size: 0.9rem;
            }
        </style>
    </head>
    <body class="dashboard-body">
        <div class="gate-wrapper">
            <div class="gate-card">
                <div class="gate-badge"><i class="fas fa-crown"></i> PRO FEATURE</div>
                <h1 class="gate-title">Upgrade to NovaHire Pro</h1>
                <p class="gate-desc">You have reached the daily free limit for <strong><?php echo htmlspecialchars($feature_name); ?></strong>. Upgrade to NovaHire Pro for unlimited AI evaluations, priority ranking, and free certificates.</p>
                <a href="<?php echo BASE_URL; ?>/seeker/pro.php" class="gate-btn">
                    <i class="fas fa-bolt mr-2"></i> Unlock with Pro
                </a>
                <div>
                    <a href="javascript:history.back()" class="gate-back">← Go Back</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

/**
 * Render the Pro upgrade banner on seeker dashboard
 */
function nh_render_upgrade_banner() {
    ?>
    <div class="container mb-4 reveal">
        <div style="background: linear-gradient(135deg, #1e3a8a, #0284c7); border-radius: var(--radius-lg, 16px); padding: 22px 28px; color: #fff; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 10px 25px rgba(2, 132, 199, 0.25);">
            <div style="display: flex; align-items: center; gap: 18px; max-width: 720px;">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: rgba(255,255,255,0.18); backdrop-filter: blur(10px); display: grid; place-items: center; font-size: 1.5rem; flex-shrink: 0;">
                    <i class="fas fa-crown" style="color: #fbbf24;"></i>
                </div>
                <div>
                    <h4 style="margin: 0 0 4px; font-weight: 800; font-size: 1.15rem; color: #fff;">Supercharge Your Career with NovaHire Pro</h4>
                    <p style="margin: 0; opacity: 0.92; font-size: 0.88rem; line-height: 1.45;">Unlimited AI tools, practice mock interviews, earn verified skill certificates, and get priority visibility to employers.</p>
                </div>
            </div>
            <a href="<?php echo BASE_URL; ?>/seeker/pro.php" style="background: #fff; color: #0369a1; font-weight: 700; font-size: 0.9rem; padding: 10px 22px; border-radius: 99px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: transform 0.2s, box-shadow 0.2s; white-space: nowrap;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
                <i class="fas fa-bolt" style="color: #f59e0b;"></i> Upgrade to Pro (৳499)
            </a>
        </div>
    </div>
    <?php
}

/**
 * Get monthly or daily feature usage count for a user
 */
function nh_get_usage_count($con, $user_id, $feature) {
    if (!$con || !$user_id) return 0;
    if ($feature === 'job_apply') {
        $start_of_month = date('Y-m-01 00:00:00');
        $stmt = mysqli_prepare($con, "SELECT COUNT(*) as c FROM job_applications WHERE user_id = ? AND applied_date >= ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "is", $user_id, $start_of_month);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            return (int)($row['c'] ?? 0);
        }
    }
    
    $today = date('Y-m-d');
    return (int)($_SESSION['feature_usage'][$today][$feature] ?? 0);
}

/**
 * Record usage of a feature
 */
function nh_record_usage($con, $user_id, $feature) {
    nh_track_usage($con, $user_id, $feature);
}

/**
 * Render visual usage bar for gated actions
 */
function nh_render_usage_bar($con, $user_id, $feature) {
    $is_pro = is_user_pro($con, $user_id);
    $used = nh_get_usage_count($con, $user_id, $feature);
    $max = 10;
    $pct = min(100, round(($used / $max) * 100));
    $bar_color = $pct >= 80 ? '#dc2626' : ($pct >= 50 ? '#d97706' : '#2563eb');
    ?>
    <div style="background:var(--bg-card, #fff);border:1px solid var(--border-light, #e2e8f0);border-radius:12px;padding:12px 16px;margin-bottom:18px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:0.85rem">
            <span style="font-weight:600;color:var(--text, #1e293b)">Monthly Applications:</span>
            <?php if ($is_pro): ?>
                <span style="font-weight:700;color:#059669"><i class="fas fa-crown" style="color:#d97706"></i> Unlimited (Pro)</span>
            <?php else: ?>
                <span style="font-weight:700;color:var(--text, #1e293b)"><?php echo $used; ?> / <?php echo $max; ?> used</span>
            <?php endif; ?>
        </div>
        <?php if (!$is_pro): ?>
        <div style="background:#e2e8f0;border-radius:99px;height:7px;overflow:hidden">
            <div style="background:<?php echo $bar_color; ?>;height:100%;width:<?php echo $pct; ?>%;transition:width 0.3s"></div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
