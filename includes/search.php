<?php
/**
 * NovaHire — Advanced Job Search Helper
 */

if (defined('NOVAHIRE_SEARCH_LOADED')) return;
define('NOVAHIRE_SEARCH_LOADED', true);

if (!isset($con)) {
    require_once __DIR__ . '/../admin/dbcon.php';
}

/**
 * Execute job search with filters and sorting
 */
function execute_job_search($con, $params = []) {
    if (!$con) return ['jobs' => [], 'total' => 0];

    $where = ["cj.status = 'active'", "cj.deadline >= CURDATE()"];
    $bind_types = '';
    $bind_params = [];

    // Keyword search
    if (!empty($params['q'])) {
        $where[] = "(cj.job_title LIKE ? OR cj.job_description LIKE ? OR c.company_name LIKE ?)";
        $like = '%' . $params['q'] . '%';
        array_push($bind_params, $like, $like, $like);
        $bind_types .= 'sss';
    }

    // Category / Sector
    if (!empty($params['category'])) {
        $where[] = "cj.category = ?";
        $bind_params[] = $params['category'];
        $bind_types .= 's';
    }

    // Job Type (full-time, part-time, internship, etc.)
    if (!empty($params['type'])) {
        $where[] = "cj.job_type = ?";
        $bind_params[] = $params['type'];
        $bind_types .= 's';
    }

    // Location / City
    if (!empty($params['location'])) {
        $where[] = "cj.job_location LIKE ?";
        $bind_params[] = '%' . $params['location'] . '%';
        $bind_types .= 's';
    }

    $where_sql = implode(' AND ', $where);
    
    // Total count query
    $count_sql = "SELECT COUNT(*) as total FROM company_jobs cj JOIN companies c ON cj.company_id = c.id WHERE {$where_sql}";
    $stmt = mysqli_prepare($con, $count_sql);
    if (!empty($bind_params)) {
        mysqli_stmt_bind_param($stmt, $bind_types, ...$bind_params);
    }
    mysqli_stmt_execute($stmt);
    $total = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0;
    mysqli_stmt_close($stmt);

    // Results query
    $page = max(1, intval($params['page'] ?? 1));
    $limit = max(1, min(50, intval($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT cj.*, c.company_name, c.logo as company_logo, c.industry 
            FROM company_jobs cj 
            JOIN companies c ON cj.company_id = c.id 
            WHERE {$where_sql} 
            ORDER BY cj.is_featured DESC, cj.posted_date DESC 
            LIMIT ? OFFSET ?";

    $bind_params[] = $limit;
    $bind_params[] = $offset;
    $bind_types .= 'ii';

    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, $bind_types, ...$bind_params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $jobs = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $jobs[] = $row;
    }
    mysqli_stmt_close($stmt);

    return [
        'jobs' => $jobs,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ];
}
