<?php
/**
 * NovaHire — Audit Logging functions
 */

if (!function_exists('log_hiring_audit')) {
    function log_hiring_audit($con, $company_id, $user_type, $user_id, $action, $entity_type, $entity_id, $details = '') {
        $details_str = is_array($details) ? json_encode($details) : (string)$details;
        $stmt = $con->prepare("INSERT INTO hiring_audit_logs (company_id, user_type, user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isisiss", $company_id, $user_type, $user_id, $action, $entity_type, $entity_id, $details_str);
            $stmt->execute();
            $stmt->close();
        }
    }
}
