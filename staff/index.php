<?php
require_once 'staff_header.php';
global $con;

$staff_id = $_SESSION['staff_id'];
$company_id = $_SESSION['company_id'];

// Get interview statistics
$stats = [
    'upcoming' => 0,
    'completed' => 0,
    'total' => 0
];

$query = "
    SELECT 
        i.status,
        COUNT(*) as count
    FROM interviews i
    JOIN interview_staff_assignments isa ON i.id = isa.interview_id
    WHERE isa.staff_id = ?
    GROUP BY i.status
";
$stmt = mysqli_prepare($con, $query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    if ($row['status'] === 'scheduled') {
        $stats['upcoming'] += $row['count'];
    } elseif ($row['status'] === 'completed') {
        $stats['completed'] += $row['count'];
    }
    $stats['total'] += $row['count'];
}
mysqli_stmt_close($stmt);

// Get upcoming interviews (limit 5)
$upcoming_query = "
    SELECT 
        i.*,
        ui.username, ui.email,
        j.job_title,
        a.id as application_id
    FROM interviews i
    JOIN interview_staff_assignments isa ON i.id = isa.interview_id
    JOIN user_info ui ON i.user_id = ui.id
    JOIN company_jobs j ON i.job_id = j.id
    JOIN job_applications a ON i.application_id = a.id
    WHERE isa.staff_id = ? AND i.status = 'scheduled'
    ORDER BY i.interview_date ASC, i.interview_time ASC
    LIMIT 5
";
$stmt = mysqli_prepare($con, $upcoming_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$upcoming_interviews = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Staff Dashboard | NovaHire</title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', 'Inter', sans-serif; background: #f0f4f8; margin: 0; }
        [data-theme="dark"] body { background: #0b1120; }
        .staff-dashboard {
            padding: 40px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        [data-theme="dark"] .stat-card { background: #1e293b; color: #f8fafc; border: 1px solid #334155; box-shadow: none; }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.upcoming { background: #e0e7ff; color: #4f46e5; }
        .stat-icon.completed { background: #dcfce7; color: #16a34a; }
        .stat-icon.total { background: #f3e8ff; color: #9333ea; }
        [data-theme="dark"] .stat-icon.upcoming { background: rgba(79,70,229,0.2); }
        [data-theme="dark"] .stat-icon.completed { background: rgba(22,163,74,0.2); }
        [data-theme="dark"] .stat-icon.total { background: rgba(147,51,234,0.2); }
        
        .stat-info h3 { margin: 0; font-size: 28px; font-weight: 700; font-family: 'Sora', sans-serif; }
        .stat-info p { margin: 0; color: #64748b; font-size: 14px; }
        [data-theme="dark"] .stat-info p { color: #94a3b8; }

        .dashboard-section {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 40px;
        }
        [data-theme="dark"] .dashboard-section { background: #1e293b; border: 1px solid #334155; box-shadow: none; color: #f8fafc;}
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .section-header h2 { margin: 0; font-size: 20px; font-weight: 700; font-family: 'Sora', sans-serif;}
        .btn-view-all {
            padding: 8px 16px;
            background: #f1f5f9;
            color: #475569;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .btn-view-all:hover { background: #e2e8f0; color: #0f172a;}
        [data-theme="dark"] .btn-view-all { background: #334155; color: #f8fafc; }
        [data-theme="dark"] .btn-view-all:hover { background: #475569; }

        .interview-list { display: flex; flex-direction: column; gap: 16px; }
        .interview-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            transition: all 0.2s;
        }
        [data-theme="dark"] .interview-item { border-color: #334155; }
        .interview-item:hover { border-color: #cbd5e1; transform: translateY(-2px); }
        .interview-info h4 { margin: 0 0 4px 0; font-size: 16px; font-weight: 700;}
        .interview-info p { margin: 0; font-size: 14px; color: #64748b; }
        [data-theme="dark"] .interview-info p { color: #94a3b8; }
        
        .interview-meta {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .meta-item { display: flex; align-items: center; gap: 6px; font-size: 14px; color: #475569; font-weight: 500;}
        [data-theme="dark"] .meta-item { color: #94a3b8; }
        
        .btn-action {
            padding: 8px 16px;
            background: #3b82f6;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .btn-action:hover { background: #2563eb; color: #fff; }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
        .empty-state i { font-size: 48px; color: #cbd5e1; margin-bottom: 16px; }
        [data-theme="dark"] .empty-state i { color: #475569; }
        
        @media (max-width: 768px) {
            .interview-item { flex-direction: column; align-items: flex-start; gap: 16px; }
            .interview-meta { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>

<div class="staff-dashboard">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon upcoming"><i class="fas fa-calendar-alt"></i></div>
            <div class="stat-info">
                <h3><?php echo $stats['upcoming']; ?></h3>
                <p>Upcoming Interviews</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon completed"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <h3><?php echo $stats['completed']; ?></h3>
                <p>Completed</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon total"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <h3><?php echo $stats['total']; ?></h3>
                <p>Total Assigned</p>
            </div>
        </div>
    </div>

    <div class="dashboard-section">
        <div class="section-header">
            <h2>Upcoming Interviews</h2>
            <a href="interviews.php" class="btn-view-all">View All</a>
        </div>

        <div class="interview-list">
            <?php if (mysqli_num_rows($upcoming_interviews) > 0): ?>
                <?php while ($interview = mysqli_fetch_assoc($upcoming_interviews)): ?>
                    <div class="interview-item">
                        <div class="interview-info">
                            <h4><?php echo htmlspecialchars($interview['username']); ?></h4>
                            <p><?php echo htmlspecialchars($interview['job_title']); ?></p>
                        </div>
                        <div class="interview-meta">
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('M d, Y', strtotime($interview['interview_date'])); ?>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-clock"></i>
                                <?php echo date('h:i A', strtotime($interview['interview_time'])); ?>
                            </div>
                            <a href="interviews.php?id=<?php echo $interview['id']; ?>" class="btn-action">Details</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No upcoming interviews</h3>
                    <p>You don't have any interviews scheduled at the moment.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
