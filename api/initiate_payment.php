<?php
/**
 * NovaHire — Initiate Payment
 * Starts payment process with selected gateway
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/payment.php';

global $con;

$payment_id = $_GET['payment_id'] ?? '';
$payment_data = null;
$amount = 0;
$plan_type = '';

if (!empty($payment_id)) {
    $payment = get_payment($con, $payment_id);
    if (!$payment) {
        header('Location: ' . BASE_URL . '/index.php?error=invalid_payment');
        exit;
    }
    // Auth guard based on payer_type
    if ($payment['payer_type'] === 'user') {
        if (!isset($_SESSION['id']) || (int)$_SESSION['id'] !== (int)$payment['payer_id']) {
            header('Location: ' . BASE_URL . '/auth/login.php');
            exit;
        }
    } else {
        if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== (int)($payment['payer_id'] ?? $payment['company_id'])) {
            header('Location: ' . BASE_URL . '/auth/login.php');
            exit;
        }
    }

    $amount = (float)$payment['amount'];
    $plan_type = $payment['plan_type'] ?: $payment['purpose'];
    $payment_method = $payment['payment_method'] ?: 'sslcommerz';
    $payment_data = ['payment_id' => $payment['payment_id']];
} else {
    // Legacy employer subscription flow
    if (!isset($_SESSION['company_id'])) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }

    $company_id = $_SESSION['company_id'];
    $plan_type = $_GET['plan'] ?? '';
    $payment_method = $_GET['method'] ?? 'sslcommerz';

    $plans = get_subscription_plans();
    if (!isset($plans[$plan_type]) || $plans[$plan_type]['price'] == 0) {
        header('Location: ' . BASE_URL . '/company/subscription.php?error=invalid_plan');
        exit;
    }

    $amount = $plans[$plan_type]['price'];

    if ($payment_method === 'sslcommerz') {
        $payment_data = init_sslcommerz_payment($con, $company_id, $plan_type, $amount);
    } elseif ($payment_method === 'bkash') {
        $payment_data = init_bkash_payment($con, $company_id, $plan_type, $amount);
        if ($payment_data) {
            header('Location: ' . BASE_URL . '/company/subscription.php?success=1');
            exit;
        }
    }
}

if ($payment_data) {
    // In production, redirect to SSLCOMMERZ gateway.
    // For local / test mode, simulate successful payment.
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Processing Payment · NovaHire</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f1f5f9; margin: 0; }
            .card { background: white; padding: 40px; border-radius: 20px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.08); max-width: 380px; width: 90%; }
            .spinner { width: 48px; height: 48px; border: 4px solid #e2e8f0; border-top: 4px solid #1a56db; border-radius: 50%; animation: spin 0.9s linear infinite; margin: 20px auto; }
            @keyframes spin { to { transform: rotate(360deg); } }
            h2 { margin: 0 0 10px; color: #0f172a; font-size: 1.3rem; }
            p { color: #64748b; font-size: 0.92rem; margin: 6px 0; }
            .amt { font-size: 1.5rem; font-weight: 800; color: #1a56db; margin: 12px 0; }
            small { color: #94a3b8; font-size: 0.75rem; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="spinner"></div>
            <h2>Processing Payment...</h2>
            <div class="amt">৳' . number_format($amount) . '</div>
            <p>Item: ' . htmlspecialchars(ucfirst($plan_type)) . '</p>
            <p>Please wait while we secure your transaction.</p>
            <p><small>ID: ' . htmlspecialchars($payment_data['payment_id']) . '</small></p>
        </div>
        <script>
            setTimeout(function() {
                window.location.href = "' . BASE_URL . '/api/payment_success.php?val_id=demo_' . time() . '&amount=' . $amount . '&currency=BDT&tran_id=TXN_' . time() . '&payment_id=' . urlencode($payment_data['payment_id']) . '";
            }, 1500);
        </script>
    </body>
    </html>';
    exit;
}

header('Location: ' . BASE_URL . '/index.php?error=payment_init_failed');
exit;
?>
