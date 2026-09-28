<?php
/**
 * NovaHire — Company Staff / Interviewer Management
 * Phase 1: Staff Creation, Listing, Editing, Status Toggle, and Safe Deletion.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_company_login();

$company_id   = (int)$_SESSION['company_id'];
$company_name = $_SESSION['company_name'] ?? 'Company';

// ── Handle Actions (POST) ──────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Verify CSRF
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid or expired security token. Please try again.';
        header('Location: manage_staff.php');
        exit;
    }

    // ── 1. ADD STAFF ──
    if ($action === 'add_staff') {
        $full_name   = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department  = trim($_POST['department'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        // Validation
        if ($full_name === '') {
            $_SESSION['flash_error'] = 'Full name is required.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
        } elseif ($designation === '') {
            $_SESSION['flash_error'] = 'Designation is required.';
        } else {
            // Check for duplicate email within the same company
            $chk = mysqli_prepare($con, "SELECT id FROM company_staff WHERE company_id = ? AND email = ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "is", $company_id, $email);
            mysqli_stmt_execute($chk);
            $res = mysqli_stmt_get_result($chk);
            if (mysqli_fetch_assoc($res)) {
                $_SESSION['flash_error'] = 'A staff member with this email already exists in your company.';
            } else {
                $activation_token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $stmt = mysqli_prepare($con, "INSERT INTO company_staff (company_id, full_name, email, phone, designation, department, status, activation_token, activation_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "issssssss", $company_id, $full_name, $email, $phone, $designation, $department, $status, $activation_token, $expires_at);
                if (mysqli_stmt_execute($stmt)) {
                    // Send Email
                    require_once __DIR__ . '/../includes/mail.php';
                    $activation_link = BASE_URL . "/auth/staff_activation.php?token=" . $activation_token;
                    send_staff_invitation_email($email, $company_name, $full_name, $designation, $activation_link, $expires_at);
                    
                    $_SESSION['flash_success'] = 'Staff member added successfully. An invitation email has been sent.';
                    $_SESSION['flash_link'] = $activation_link;
                } else {
                    $_SESSION['flash_error'] = 'Unable to add staff member.';
                }
                mysqli_stmt_close($stmt);
            }
            mysqli_stmt_close($chk);
        }
        header('Location: manage_staff.php');
        exit;
    }

    // ── 2. EDIT STAFF ──
    if ($action === 'edit_staff') {
        $staff_id    = (int)($_POST['staff_id'] ?? 0);
        $full_name   = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department  = trim($_POST['department'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        // Verify company ownership (IDOR prevention)
        $own_chk = mysqli_prepare($con, "SELECT id FROM company_staff WHERE id = ? AND company_id = ? LIMIT 1");
        mysqli_stmt_bind_param($own_chk, "ii", $staff_id, $company_id);
        mysqli_stmt_execute($own_chk);
        $owned = mysqli_fetch_assoc(mysqli_stmt_get_result($own_chk));
        mysqli_stmt_close($own_chk);

        if (!$owned) {
            $_SESSION['flash_error'] = 'You are not authorized to manage this staff member.';
            header('Location: manage_staff.php');
            exit;
        }

        // Validation
        if ($full_name === '') {
            $_SESSION['flash_error'] = 'Full name is required.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
        } elseif ($designation === '') {
            $_SESSION['flash_error'] = 'Designation is required.';
        } else {
            // Check for duplicate email within the same company for another staff member
            $chk = mysqli_prepare($con, "SELECT id FROM company_staff WHERE company_id = ? AND email = ? AND id != ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "isi", $company_id, $email, $staff_id);
            mysqli_stmt_execute($chk);
            $res = mysqli_stmt_get_result($chk);
            if (mysqli_fetch_assoc($res)) {
                $_SESSION['flash_error'] = 'A staff member with this email already exists in your company.';
            } else {
                $stmt = mysqli_prepare($con, "UPDATE company_staff SET full_name = ?, email = ?, phone = ?, designation = ?, department = ?, status = ? WHERE id = ? AND company_id = ?");
                mysqli_stmt_bind_param($stmt, "ssssssii", $full_name, $email, $phone, $designation, $department, $status, $staff_id, $company_id);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['flash_success'] = 'Staff member updated successfully.';
                } else {
                    $_SESSION['flash_error'] = 'Unable to update staff member.';
                }
                mysqli_stmt_close($stmt);
            }
            mysqli_stmt_close($chk);
        }
        header('Location: manage_staff.php');
        exit;
    }

    // ── 3. TOGGLE STATUS ──
    if ($action === 'toggle_status') {
        $staff_id   = (int)($_POST['staff_id'] ?? 0);
        $new_status = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';

        // Verify company ownership and update
        $stmt = mysqli_prepare($con, "UPDATE company_staff SET status = ? WHERE id = ? AND company_id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $new_status, $staff_id, $company_id);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected > 0) {
            $_SESSION['flash_success'] = 'Staff status updated successfully.';
        } else {
            // Verify if record exists to provide accurate unauthorized or no-change message
            $chk = mysqli_prepare($con, "SELECT id, status FROM company_staff WHERE id = ? AND company_id = ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "ii", $staff_id, $company_id);
            mysqli_stmt_execute($chk);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);

            if (!$row) {
                $_SESSION['flash_error'] = 'You are not authorized to manage this staff member.';
            } else {
                $_SESSION['flash_success'] = 'Staff status updated successfully.';
            }
        }
        header('Location: manage_staff.php');
        exit;
    }

    // ── 4. DELETE STAFF (Safe delete with ownership verification) ──
    if ($action === 'delete_staff') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);

        // Verify ownership
        $chk = mysqli_prepare($con, "SELECT id, full_name FROM company_staff WHERE id = ? AND company_id = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "ii", $staff_id, $company_id);
        mysqli_stmt_execute($chk);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);

        if (!$row) {
            $_SESSION['flash_error'] = 'You are not authorized to manage this staff member.';
            header('Location: manage_staff.php');
            exit;
        }

        // Delete with strict ownership boundary
        $del = mysqli_prepare($con, "DELETE FROM company_staff WHERE id = ? AND company_id = ?");
        mysqli_stmt_bind_param($del, "ii", $staff_id, $company_id);
        if (mysqli_stmt_execute($del)) {
            $_SESSION['flash_success'] = 'Staff member removed successfully.';
        } else {
            $_SESSION['flash_error'] = 'Unable to delete staff member.';
        }
        mysqli_stmt_close($del);

        header('Location: manage_staff.php');
        exit;
    }
    
    // ── 5. RESEND INVITATION ──
    if ($action === 'resend_invitation') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        
        $chk = mysqli_prepare($con, "SELECT id, full_name, email, designation, is_activated FROM company_staff WHERE id = ? AND company_id = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "ii", $staff_id, $company_id);
        mysqli_stmt_execute($chk);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);

        if (!$row) {
            $_SESSION['flash_error'] = 'You are not authorized to manage this staff member.';
        } elseif ($row['is_activated']) {
            $_SESSION['flash_error'] = 'This staff member is already activated.';
        } else {
            $activation_token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $stmt = mysqli_prepare($con, "UPDATE company_staff SET activation_token = ?, activation_expires_at = ? WHERE id = ? AND company_id = ?");
            mysqli_stmt_bind_param($stmt, "ssii", $activation_token, $expires_at, $staff_id, $company_id);
            if (mysqli_stmt_execute($stmt)) {
                require_once __DIR__ . '/../includes/mail.php';
                $activation_link = BASE_URL . "/auth/staff_activation.php?token=" . $activation_token;
                send_staff_invitation_email($row['email'], $company_name, $row['full_name'], $row['designation'], $activation_link, $expires_at);
                $_SESSION['flash_success'] = 'Invitation resent successfully.';
                $_SESSION['flash_link'] = $activation_link;
            } else {
                $_SESSION['flash_error'] = 'Unable to resend invitation.';
            }
            mysqli_stmt_close($stmt);
        }
        header('Location: manage_staff.php');
        exit;
    }

    // ── 6. REVOKE INVITATION ──
    if ($action === 'revoke_invitation') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        
        $chk = mysqli_prepare($con, "SELECT id, is_activated FROM company_staff WHERE id = ? AND company_id = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "ii", $staff_id, $company_id);
        mysqli_stmt_execute($chk);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);

        if (!$row) {
            $_SESSION['flash_error'] = 'You are not authorized to manage this staff member.';
        } elseif ($row['is_activated']) {
            $_SESSION['flash_error'] = 'This staff member is already activated.';
        } else {
            $stmt = mysqli_prepare($con, "UPDATE company_staff SET activation_token = NULL, activation_expires_at = NULL WHERE id = ? AND company_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $staff_id, $company_id);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['flash_success'] = 'Invitation revoked successfully.';
            } else {
                $_SESSION['flash_error'] = 'Unable to revoke invitation.';
            }
            mysqli_stmt_close($stmt);
        }
        header('Location: manage_staff.php');
        exit;
    }
}

// ── Read Flash Messages ────────────────────────────────────────────────────
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_link    = $_SESSION['flash_link'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;

unset($_SESSION['flash_success'], $_SESSION['flash_link'], $_SESSION['flash_error']);

// ── Filter & Search Parameters ─────────────────────────────────────────────
$search_q       = trim($_GET['q'] ?? '');
$status_filter  = trim($_GET['status'] ?? '');
$dept_filter    = trim($_GET['department'] ?? '');

// ── Aggregate Stats ────────────────────────────────────────────────────────
$stats = [
    'total'       => 0,
    'active'      => 0,
    'inactive'    => 0,
    'departments' => 0,
];

$st_res = mysqli_query($con, "SELECT status, department, COUNT(*) as cnt FROM company_staff WHERE company_id = $company_id GROUP BY status, department");
$all_depts = [];
if ($st_res) {
    while ($r = mysqli_fetch_assoc($st_res)) {
        $stats['total'] += (int)$r['cnt'];
        if ($r['status'] === 'active') {
            $stats['active'] += (int)$r['cnt'];
        } elseif ($r['status'] === 'inactive') {
            $stats['inactive'] += (int)$r['cnt'];
        }
        if (!empty($r['department'])) {
            $all_depts[$r['department']] = true;
        }
    }
}
$stats['departments'] = count($all_depts);

// Fetch all distinct departments for filter dropdown
$dept_list_res = mysqli_query($con, "SELECT DISTINCT department FROM company_staff WHERE company_id = $company_id AND department IS NOT NULL AND department != '' ORDER BY department ASC");
$departments = [];
if ($dept_list_res) {
    while ($drow = mysqli_fetch_assoc($dept_list_res)) {
        $departments[] = $drow['department'];
    }
}

// ── Query Staff List ───────────────────────────────────────────────────────
$query_sql = "SELECT * FROM company_staff WHERE company_id = ?";
$params = [$company_id];
$types  = "i";

if ($status_filter !== '' && in_array($status_filter, ['active', 'inactive'])) {
    $query_sql .= " AND status = ?";
    $params[] = $status_filter;
    $types   .= "s";
}

if ($dept_filter !== '') {
    $query_sql .= " AND department = ?";
    $params[] = $dept_filter;
    $types   .= "s";
}

if ($search_q !== '') {
    $query_sql .= " AND (full_name LIKE ? OR email LIKE ? OR designation LIKE ? OR department LIKE ?)";
    $like_term = '%' . $search_q . '%';
    $params[]  = $like_term;
    $params[]  = $like_term;
    $params[]  = $like_term;
    $params[]  = $like_term;
    $types    .= "ssss";
}

$query_sql .= " ORDER BY created_at DESC";

$stmt = mysqli_prepare($con, $query_sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$staff_members = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Company Staff / Interviewers | NovaHire</title>
    <?php include __DIR__ . '/../includes/links.php'; ?>
    <style>
        :root {
            --st-bg: #f8fafc;
            --st-card: #ffffff;
            --st-border: #e2e8f0;
            --st-text: #0f172a;
            --st-muted: #64748b;
            --st-primary: #1a56db;
            --st-primary-2: #0ea5e9;
            --st-success: #059669;
            --st-success-bg: #ecfdf5;
            --st-danger: #e11d48;
            --st-danger-bg: #fff1f2;
            --st-warning: #d97706;
            --st-warning-bg: #fffbeb;
            --st-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);
            --st-radius: 16px;
        }

        [data-theme="dark"] {
            --st-bg: #0b0f19;
            --st-card: #131b2e;
            --st-border: #1e293b;
            --st-text: #f8fafc;
            --st-muted: #94a3b8;
            --st-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.35);
        }

        body {
            background-color: var(--st-bg);
            color: var(--st-text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
        }

        .st-wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 20px 70px;
        }

        /* ── Hero Banner ── */
        .st-hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #1e40af 0%, #1a56db 50%, #0284c7 100%);
            border-radius: 22px;
            padding: 32px 36px;
            color: #ffffff;
            box-shadow: 0 16px 36px -4px rgba(26, 86, 219, 0.32);
            margin-bottom: 24px;
        }
        .st-hero::after {
            content: '';
            position: absolute;
            right: -60px;
            top: -60px;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }
        .st-hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .st-hero h1 {
            font-size: 1.85rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 6px;
            letter-spacing: -0.5px;
        }
        .st-hero p {
            font-size: 0.96rem;
            color: rgba(255, 255, 255, 0.88);
            margin: 0;
            max-width: 580px;
        }
        .st-hero-btn {
            background: #ffffff;
            color: #1a56db;
            font-weight: 700;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.16);
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
        }
        .st-hero-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.22);
            color: #1e40af;
            text-decoration: none;
        }

        /* ── Stats Grid ── */
        .st-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 900px) {
            .st-stats { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .st-stats { grid-template-columns: 1fr; }
        }
        .st-stat {
            background: var(--st-card);
            border: 1px solid var(--st-border);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--st-shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .st-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -3px rgba(15, 23, 42, 0.1);
        }
        .st-stat-ico {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .st-stat-ico.blue { background: rgba(26, 86, 219, 0.1); color: #1a56db; }
        .st-stat-ico.green { background: rgba(5, 150, 105, 0.1); color: #059669; }
        .st-stat-ico.gray { background: rgba(100, 116, 139, 0.1); color: #64748b; }
        .st-stat-ico.purple { background: rgba(124, 58, 237, 0.1); color: #7c3aed; }
        .st-stat b {
            display: block;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--st-text);
            line-height: 1.1;
        }
        .st-stat span {
            font-size: 0.78rem;
            color: var(--st-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ── Filter Toolbar ── */
        .st-toolbar {
            background: var(--st-card);
            border: 1px solid var(--st-border);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--st-shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }
        .st-search-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-grow: 1;
            flex-wrap: wrap;
        }
        .st-input-wrap {
            position: relative;
            flex-grow: 1;
            min-width: 220px;
        }
        .st-input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--st-muted);
            font-size: 0.9rem;
        }
        .st-input-wrap input {
            width: 100%;
            background: var(--st-bg);
            border: 1px solid var(--st-border);
            color: var(--st-text);
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            font-size: 0.92rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .st-input-wrap input:focus {
            border-color: var(--st-primary);
            box-shadow: 0 0 0 3px rgba(26, 86, 219, 0.15);
        }
        .st-select {
            background: var(--st-bg);
            border: 1px solid var(--st-border);
            color: var(--st-text);
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 0.92rem;
            outline: none;
            min-width: 150px;
        }
        .st-btn-filter {
            background: var(--st-primary);
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.92rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .st-btn-filter:hover {
            background: #1e40af;
        }
        .st-btn-reset {
            background: transparent;
            color: var(--st-muted);
            border: 1px solid var(--st-border);
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.92rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .st-btn-reset:hover {
            background: var(--st-border);
            color: var(--st-text);
            text-decoration: none;
        }

        /* ── Staff Table Card ── */
        .st-table-card {
            background: var(--st-card);
            border: 1px solid var(--st-border);
            border-radius: 16px;
            box-shadow: var(--st-shadow);
            overflow: hidden;
        }
        .st-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .st-table th {
            background: rgba(0, 0, 0, 0.02);
            padding: 14px 20px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--st-muted);
            border-bottom: 1px solid var(--st-border);
            white-space: nowrap;
        }
        .st-table td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid var(--st-border);
            font-size: 0.92rem;
        }
        .st-table tbody tr:last-child td {
            border-bottom: none;
        }
        .st-table tbody tr:hover {
            background-color: rgba(26, 86, 219, 0.02);
        }

        /* ── Member Info Item ── */
        .st-member-cell {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .st-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #1a56db, #0ea5e9);
            color: #ffffff;
            font-weight: 700;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(26, 86, 219, 0.2);
        }
        .st-member-name {
            font-weight: 700;
            color: var(--st-text);
            margin-bottom: 2px;
        }
        .st-member-contact {
            font-size: 0.82rem;
            color: var(--st-muted);
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .st-member-contact a {
            color: var(--st-muted);
            text-decoration: none;
        }
        .st-member-contact a:hover {
            color: var(--st-primary);
            text-decoration: underline;
        }

        /* ── Badges ── */
        .st-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .st-badge.active {
            background: var(--st-success-bg);
            color: var(--st-success);
            border: 1px solid rgba(5, 150, 105, 0.25);
        }
        .st-badge.active::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--st-success);
        }
        .st-badge.inactive {
            background: var(--st-warning-bg);
            color: var(--st-warning);
            border: 1px solid rgba(217, 119, 6, 0.25);
        }
        .st-badge.inactive::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--st-warning);
        }

        .st-dept-pill {
            display: inline-block;
            background: rgba(100, 116, 139, 0.08);
            border: 1px solid var(--st-border);
            color: var(--st-text);
            padding: 3px 10px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
        }

        /* ── Action Buttons ── */
        .st-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .st-act-btn {
            background: var(--st-card);
            border: 1px solid var(--st-border);
            color: var(--st-muted);
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.88rem;
            transition: all 0.15s ease;
            cursor: pointer;
            padding: 0;
        }
        .st-act-btn:hover {
            color: var(--st-primary);
            border-color: var(--st-primary);
            background: rgba(26, 86, 219, 0.05);
        }
        .st-act-btn.toggle:hover {
            color: var(--st-warning);
            border-color: var(--st-warning);
            background: rgba(217, 119, 6, 0.05);
        }
        .st-act-btn.danger:hover {
            color: var(--st-danger);
            border-color: var(--st-danger);
            background: rgba(225, 29, 72, 0.05);
        }

        /* ── Empty State ── */
        .st-empty {
            text-align: center;
            padding: 56px 20px;
            color: var(--st-muted);
        }
        .st-empty-ico {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: rgba(26, 86, 219, 0.08);
            color: var(--st-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 18px;
        }
        .st-empty h4 {
            font-weight: 700;
            color: var(--st-text);
            margin-bottom: 6px;
        }
        .st-empty p {
            font-size: 0.92rem;
            max-width: 440px;
            margin: 0 auto 20px;
        }

        /* ── Modal Enhancements ── */
        .modal-content {
            background-color: var(--st-card);
            color: var(--st-text);
            border: 1px solid var(--st-border);
            border-radius: 18px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            border-bottom: 1px solid var(--st-border);
            padding: 20px 24px;
        }
        .modal-title {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--st-text);
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            border-top: 1px solid var(--st-border);
            padding: 16px 24px;
        }
        .form-group label {
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--st-text);
            margin-bottom: 6px;
        }
        .form-control {
            background-color: var(--st-bg);
            border: 1px solid var(--st-border);
            color: var(--st-text);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.92rem;
        }
        .form-control:focus {
            background-color: var(--st-bg);
            color: var(--st-text);
            border-color: var(--st-primary);
            box-shadow: 0 0 0 3px rgba(26, 86, 219, 0.15);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/company_header.php'; ?>

    <div class="st-wrap">
        <!-- Flash Alerts -->
        <?php if ($flash_success): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert" style="border-radius: 12px; font-weight: 600;">
                <i class="fas fa-circle-check mr-2"></i><?php echo htmlspecialchars($flash_success); ?>
                <?php if ($flash_link): ?>
                    <div style="margin-top: 10px; padding: 10px; background: rgba(255,255,255,0.2); border-radius: 8px;">
                        <small style="display:block; margin-bottom: 4px;">Since you are running locally without SMTP, you can copy the activation link below:</small>
                        <a href="<?php echo htmlspecialchars($flash_link); ?>" target="_blank" style="color: #064e3b; text-decoration: underline; word-break: break-all; font-family: monospace;">
                            <?php echo htmlspecialchars($flash_link); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($flash_error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert" style="border-radius: 12px; font-weight: 600;">
                <i class="fas fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($flash_error); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Hero Header -->
        <div class="st-hero">
            <div class="st-hero-content">
                <div>
                    <h1>Company Staff / Interviewers</h1>
                    <p>Manage employees who can be assigned to candidate interviews.</p>
                </div>
                <div>
                    <button type="button" class="st-hero-btn" data-toggle="modal" data-target="#addStaffModal">
                        <i class="fas fa-plus"></i> Add Staff Member
                    </button>
                </div>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="st-stats">
            <div class="st-stat">
                <div class="st-stat-ico blue"><i class="fas fa-users"></i></div>
                <div>
                    <b><?php echo $stats['total']; ?></b>
                    <span>Total Staff</span>
                </div>
            </div>
            <div class="st-stat">
                <div class="st-stat-ico green"><i class="fas fa-user-check"></i></div>
                <div>
                    <b><?php echo $stats['active']; ?></b>
                    <span>Active Interviewers</span>
                </div>
            </div>
            <div class="st-stat">
                <div class="st-stat-ico gray"><i class="fas fa-user-clock"></i></div>
                <div>
                    <b><?php echo $stats['inactive']; ?></b>
                    <span>Inactive Staff</span>
                </div>
            </div>
            <div class="st-stat">
                <div class="st-stat-ico purple"><i class="fas fa-building"></i></div>
                <div>
                    <b><?php echo $stats['departments']; ?></b>
                    <span>Departments</span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="st-toolbar">
            <form method="GET" action="manage_staff.php" class="st-search-form">
                <div class="st-input-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search by name, email, designation..." value="<?php echo htmlspecialchars($search_q); ?>">
                </div>

                <select name="status" class="st-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>

                <select name="department" class="st-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $dept_filter === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="st-btn-filter"><i class="fas fa-filter mr-1"></i> Filter</button>

                <?php if ($search_q !== '' || $status_filter !== '' || $dept_filter !== ''): ?>
                    <a href="manage_staff.php" class="st-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Staff List Table -->
        <div class="st-table-card">
            <?php if (empty($staff_members)): ?>
                <div class="st-empty">
                    <div class="st-empty-ico"><i class="fas fa-user-tie"></i></div>
                    <h4>No Staff Members Found</h4>
                    <p>
                        <?php if ($search_q !== '' || $status_filter !== '' || $dept_filter !== ''): ?>
                            No staff records match your search criteria. Try clearing filters.
                        <?php else: ?>
                            Add your first team member or interviewer to start building your hiring panel.
                        <?php endif; ?>
                    </p>
                    <button type="button" class="btn btn-primary px-4 py-2" style="border-radius: 10px; font-weight: 600;" data-toggle="modal" data-target="#addStaffModal">
                        <i class="fas fa-plus mr-1"></i> Add First Staff Member
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="st-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Designation</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Invitation</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_members as $sm): 
                                $initial = mb_strtoupper(mb_substr(trim($sm['full_name']), 0, 1));
                            ?>
                                <tr>
                                    <td>
                                        <div class="st-member-cell">
                                            <div class="st-avatar"><?php echo htmlspecialchars($initial); ?></div>
                                            <div>
                                                <div class="st-member-name"><?php echo htmlspecialchars($sm['full_name']); ?></div>
                                                <div class="st-member-contact">
                                                    <?php if (!empty($sm['phone'])): ?>
                                                        <span><i class="fas fa-phone mr-1" style="font-size: 0.75rem;"></i><?php echo htmlspecialchars($sm['phone']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="mailto:<?php echo htmlspecialchars($sm['email']); ?>" class="text-muted">
                                            <i class="fas fa-envelope mr-1 text-primary"></i><?php echo htmlspecialchars($sm['email']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong style="color: var(--st-text);"><?php echo htmlspecialchars($sm['designation']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($sm['department'])): ?>
                                            <span class="st-dept-pill"><?php echo htmlspecialchars($sm['department']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.85rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="st-badge <?php echo $sm['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                            <?php echo htmlspecialchars($sm['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($sm['is_activated']): ?>
                                            <span class="text-success" style="font-size: 0.85rem; font-weight: 600;"><i class="fas fa-check-circle mr-1"></i>Activated</span>
                                        <?php elseif (!empty($sm['activation_token']) && strtotime($sm['activation_expires_at']) > time()): ?>
                                            <span class="text-warning" style="font-size: 0.85rem; font-weight: 600;"><i class="fas fa-clock mr-1"></i>Pending</span>
                                        <?php else: ?>
                                            <span class="text-danger" style="font-size: 0.85rem; font-weight: 600;"><i class="fas fa-times-circle mr-1"></i>Expired/Revoked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="st-actions" style="justify-content: flex-end;">
                                            <!-- Resend/Revoke Invitation -->
                                            <?php if (!$sm['is_activated']): ?>
                                                <form method="POST" action="manage_staff.php" style="display: inline;" onsubmit="return confirm('Resend invitation email to this staff member?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="resend_invitation">
                                                    <input type="hidden" name="staff_id" value="<?php echo (int)$sm['id']; ?>">
                                                    <button type="submit" class="st-act-btn" title="Resend Invitation">
                                                        <i class="fas fa-paper-plane text-primary"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" action="manage_staff.php" style="display: inline;" onsubmit="return confirm('Revoke invitation for this staff member?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="revoke_invitation">
                                                    <input type="hidden" name="staff_id" value="<?php echo (int)$sm['id']; ?>">
                                                    <button type="submit" class="st-act-btn" title="Revoke Invitation">
                                                        <i class="fas fa-ban text-warning"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Edit Button -->
                                            <button type="button" class="st-act-btn btn-edit-staff" title="Edit Staff"
                                                data-id="<?php echo (int)$sm['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($sm['full_name']); ?>"
                                                data-email="<?php echo htmlspecialchars($sm['email']); ?>"
                                                data-phone="<?php echo htmlspecialchars($sm['phone'] ?? ''); ?>"
                                                data-designation="<?php echo htmlspecialchars($sm['designation']); ?>"
                                                data-department="<?php echo htmlspecialchars($sm['department'] ?? ''); ?>"
                                                data-status="<?php echo htmlspecialchars($sm['status']); ?>">
                                                <i class="fas fa-pen-to-square"></i>
                                            </button>

                                            <!-- Toggle Status Button -->
                                            <form method="POST" action="manage_staff.php" style="display: inline;" onsubmit="return confirm('Change status of this staff member to <?php echo $sm['status'] === 'active' ? 'Inactive' : 'Active'; ?>?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="staff_id" value="<?php echo (int)$sm['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $sm['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                                <button type="submit" class="st-act-btn toggle" title="<?php echo $sm['status'] === 'active' ? 'Deactivate Staff' : 'Activate Staff'; ?>">
                                                    <i class="fas <?php echo $sm['status'] === 'active' ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted'; ?>" style="font-size: 1.1rem;"></i>
                                                </button>
                                            </form>

                                            <!-- Safe Delete Button -->
                                            <button type="button" class="st-act-btn danger btn-delete-staff" title="Delete Staff"
                                                data-id="<?php echo (int)$sm['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($sm['full_name']); ?>">
                                                <i class="fas fa-trash-can"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── MODAL: ADD STAFF ──────────────────────────────────────────────── -->
    <div class="modal fade" id="addStaffModal" tabindex="-1" role="dialog" aria-labelledby="addStaffModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="POST" action="manage_staff.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_staff">

                    <div class="modal-header">
                        <h5 class="modal-title" id="addStaffModalLabel"><i class="fas fa-user-plus mr-2 text-primary"></i>Add Staff Member</h5>
                        <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        <div class="form-group">
                            <label for="addFullName">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="addFullName" name="full_name" required placeholder="e.g. Rahim Ahmed" maxlength="150">
                        </div>

                        <div class="form-group">
                            <label for="addEmail">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="addEmail" name="email" required placeholder="e.g. rahim@example.com" maxlength="150">
                            <small class="form-text text-muted">Used for notifications and future interviewer login.</small>
                        </div>

                        <div class="form-group">
                            <label for="addPhone">Phone Number</label>
                            <input type="text" class="form-control" id="addPhone" name="phone" placeholder="e.g. +880 1712 345678" maxlength="50">
                        </div>

                        <div class="form-group">
                            <label for="addDesignation">Designation <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="addDesignation" name="designation" required placeholder="e.g. Project Manager, Technical Lead, HR Executive" maxlength="100">
                        </div>

                        <div class="form-group">
                            <label for="addDepartment">Department</label>
                            <input type="text" class="form-control" id="addDepartment" name="department" placeholder="e.g. Engineering, Human Resources, QA" maxlength="100">
                        </div>

                        <div class="form-group">
                            <label for="addStatus">Status</label>
                            <select class="form-control" id="addStatus" name="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold px-4">Add Staff</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── MODAL: EDIT STAFF ─────────────────────────────────────────────── -->
    <div class="modal fade" id="editStaffModal" tabindex="-1" role="dialog" aria-labelledby="editStaffModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="POST" action="manage_staff.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="edit_staff">
                    <input type="hidden" name="staff_id" id="editStaffId" value="">

                    <div class="modal-header">
                        <h5 class="modal-title" id="editStaffModalLabel"><i class="fas fa-user-pen mr-2 text-primary"></i>Edit Staff Member</h5>
                        <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        <div class="form-group">
                            <label for="editFullName">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editFullName" name="full_name" required maxlength="150">
                        </div>

                        <div class="form-group">
                            <label for="editEmail">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="editEmail" name="email" required maxlength="150">
                        </div>

                        <div class="form-group">
                            <label for="editPhone">Phone Number</label>
                            <input type="text" class="form-control" id="editPhone" name="phone" maxlength="50">
                        </div>

                        <div class="form-group">
                            <label for="editDesignation">Designation <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editDesignation" name="designation" required maxlength="100">
                        </div>

                        <div class="form-group">
                            <label for="editDepartment">Department</label>
                            <input type="text" class="form-control" id="editDepartment" name="department" maxlength="100">
                        </div>

                        <div class="form-group">
                            <label for="editStatus">Status</label>
                            <select class="form-control" id="editStatus" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── MODAL: DELETE STAFF CONFIRMATION ─────────────────────────────── -->
    <div class="modal fade" id="deleteStaffModal" tabindex="-1" role="dialog" aria-labelledby="deleteStaffModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="POST" action="manage_staff.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_staff">
                    <input type="hidden" name="staff_id" id="deleteStaffId" value="">

                    <div class="modal-header">
                        <h5 class="modal-title text-danger" id="deleteStaffModalLabel"><i class="fas fa-triangle-exclamation mr-2"></i>Delete Staff Member</h5>
                        <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to remove <strong id="deleteStaffName"></strong>?</p>
                        <div class="alert alert-warning" style="font-size: 0.88rem; border-radius: 10px;">
                            <i class="fas fa-info-circle mr-1"></i>
                            <strong>Tip:</strong> If this staff member conducted or is assigned to past interviews, we recommend setting their status to <strong>Inactive</strong> instead to preserve historical records.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger font-weight-bold px-4">Confirm Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Populate Edit Modal
            $('.btn-edit-staff').on('click', function() {
                const btn = $(this);
                $('#editStaffId').val(btn.data('id'));
                $('#editFullName').val(btn.data('name'));
                $('#editEmail').val(btn.data('email'));
                $('#editPhone').val(btn.data('phone'));
                $('#editDesignation').val(btn.data('designation'));
                $('#editDepartment').val(btn.data('department'));
                $('#editStatus').val(btn.data('status'));
                $('#editStaffModal').modal('show');
            });

            // Populate Delete Modal
            $('.btn-delete-staff').on('click', function() {
                const btn = $(this);
                $('#deleteStaffId').val(btn.data('id'));
                $('#deleteStaffName').text(btn.data('name'));
                $('#deleteStaffModal').modal('show');
            });
        });
    </script>
</body>
</html>
