<?php
/**
 * api/skills.php
 * Fetches skill categories, skills, and assessments.
 */
session_start();
require_once '../admin/dbcon.php';
global $con;

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'list';
$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;

if ($action === 'list') {
    // Fetch all categories and their skills with assessments
    $sql = "SELECT c.id as cat_id, c.name as cat_name, c.icon, 
                   s.id as skill_id, s.name as skill_name, s.description as skill_desc,
                   a.id as assessment_id, a.title, a.description as assessment_desc, a.time_limit, a.passing_score, a.is_premium
            FROM skill_categories c
            LEFT JOIN skills s ON c.id = s.category_id
            LEFT JOIN skill_assessments a ON s.id = a.skill_id AND a.status = 'active'
            ORDER BY c.name, s.name, a.is_premium ASC";
            
    $result = mysqli_query($con, $sql);
    
    $catalog = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $cat_id = $row['cat_id'];
        if (!isset($catalog[$cat_id])) {
            $catalog[$cat_id] = [
                'id' => $cat_id,
                'name' => $row['cat_name'],
                'icon' => $row['icon'],
                'skills' => []
            ];
        }
        
        if ($row['skill_id']) {
            $skill_id = $row['skill_id'];
            if (!isset($catalog[$cat_id]['skills'][$skill_id])) {
                $catalog[$cat_id]['skills'][$skill_id] = [
                    'id' => $skill_id,
                    'name' => $row['skill_name'],
                    'description' => $row['skill_desc'],
                    'assessments' => []
                ];
            }
            
            if ($row['assessment_id']) {
                $catalog[$cat_id]['skills'][$skill_id]['assessments'][] = [
                    'id' => $row['assessment_id'],
                    'title' => $row['title'],
                    'description' => $row['assessment_desc'],
                    'time_limit' => (int)$row['time_limit'],
                    'passing_score' => (int)$row['passing_score'],
                    'is_premium' => (bool)$row['is_premium']
                ];
            }
        }
    }
    
    // Convert to indexed arrays
    $catalog_arr = [];
    foreach ($catalog as $cat) {
        $skills_arr = [];
        foreach ($cat['skills'] as $skill) {
            $skills_arr[] = $skill;
        }
        $cat['skills'] = $skills_arr;
        $catalog_arr[] = $cat;
    }
    
    echo json_encode(['success' => true, 'data' => $catalog_arr]);
    exit;
}

if ($action === 'me') {
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    // Fetch user's verified skills
    $sql = "SELECT vs.id, vs.skill_level, vs.score, vs.verified_at,
                   s.id as skill_id, s.name as skill_name,
                   a.id as assessment_id, a.title as assessment_title,
                   sc.name as category_name
            FROM verified_skills vs
            JOIN skills s ON vs.skill_id = s.id
            JOIN skill_assessments a ON vs.assessment_id = a.id
            JOIN skill_categories sc ON s.category_id = sc.id
            WHERE vs.user_id = ?
            ORDER BY vs.verified_at DESC";
            
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $verified_skills = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $verified_skills[] = $row;
    }
    
    // Fetch progress history
    $sql2 = "SELECT sp.id, sp.score, sp.skill_level, sp.attempt_date,
                    s.name as skill_name
             FROM skill_progress sp
             JOIN skills s ON sp.skill_id = s.id
             WHERE sp.user_id = ?
             ORDER BY sp.attempt_date DESC";
             
    $stmt2 = mysqli_prepare($con, $sql2);
    mysqli_stmt_bind_param($stmt2, "i", $user_id);
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);
    
    $history = [];
    while ($row = mysqli_fetch_assoc($result2)) {
        $history[] = $row;
    }
    
    echo json_encode([
        'success' => true, 
        'verified_skills' => $verified_skills,
        'history' => $history
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
