<?php
/**
 * NovaHire — Payment Fail Handler
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/payment.php';

global $con;

$payment_id = $_GET['payment_id'] ?? $_POST['payment_id'] ?? '';
$redirect = BASE_URL . '/index.php?error=payment_failed';

if ($payment_id) {
    // Update payment status
    $stmt = @mysqli_prepare($con, "UPDATE payments SET status = 'failed' WHERE payment_id = ? AND status = 'pending'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $payment_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $payment = get_payment($con, $payment_id);
    if ($payment) {
        if ($payment['payer_type'] === 'user') {
            $redirect = BASE_URL . '/seeker/pro.php?error=payment_failed';
            if ($payment['purpose'] === 'certificate') $redirect = BASE_URL . '/seeker/certificates.php?error=payment_failed';
            if ($payment['purpose'] === 'session') $redirect = BASE_URL . '/seeker/mentors.php?error=payment_failed';
        } else {
            $redirect = BASE_URL . '/company/subscription.php?error=payment_failed';
            if ($payment['purpose'] === 'featured_job') $redirect = BASE_URL . '/company/my_jobs.php?error=payment_failed';
        }
    }
} else {
    $redirect = BASE_URL . '/company/subscription.php?error=payment_failed';
}

header('Location: ' . $redirect);
exit;
?>
