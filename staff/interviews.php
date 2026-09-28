<?php
require_once 'staff_header.php';
global $con;

$staff_id = $_SESSION['staff_id'];
$company_id = $_SESSION['company_id'];

$filter_status = $_GET['status'] ?? '';

// Fetch all interviews assigned to this staff
$query = "
    SELECT 
        i.*,
        ui.username, ui.email, ui.profile as profile_picture,
        j.job_title,
        a.id as application_id
    FROM interviews i
    JOIN interview_staff_assignments isa ON i.id = isa.interview_id
    JOIN user_info ui ON i.user_id = ui.id
    JOIN company_jobs j ON i.job_id = j.id
    JOIN job_applications a ON i.application_id = a.id
    WHERE isa.staff_id = ?
";
$bind_types = "i";
$bind_vals = [$staff_id];

if ($filter_status && in_array($filter_status, ['scheduled', 'completed', 'cancelled'])) {
    $query .= " AND i.status = ?";
    $bind_types .= "s";
    $bind_vals[] = $filter_status;
}

$query .= " ORDER BY i.interview_date DESC, i.interview_time DESC";

$stmt = mysqli_prepare($con, $query);
mysqli_stmt_bind_param($stmt, $bind_types, ...$bind_vals);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$interviews = [];
while ($row = mysqli_fetch_assoc($result)) {
    $interviews[] = $row;
}
mysqli_stmt_close($stmt);

// Status colors
$status_colors = [
    'scheduled' => ['#3b82f6', 'fa-calendar-check', 'Upcoming'],
    'completed' => ['#059669', 'fa-check-circle', 'Completed'],
    'cancelled' => ['#dc2626', 'fa-times-circle', 'Cancelled']
];

$type_icons = [
    'Online' => 'fa-video',
    'Phone' => 'fa-phone',
    'In-Person' => 'fa-building'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>My Interviews | NovaHire</title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', 'Inter', sans-serif; background: #f0f4f8; margin: 0; }
        [data-theme="dark"] body { background: #0b1120; color: #f8fafc; }
        
        .page-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .page-title h1 {
            margin: 0 0 8px 0;
            font-family: 'Sora', sans-serif;
            font-size: 28px;
            font-weight: 700;
        }
        .page-title p {
            margin: 0;
            color: #64748b;
        }
        [data-theme="dark"] .page-title p { color: #94a3b8; }

        .filters {
            background: #fff;
            padding: 16px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            gap: 16px;
            margin-bottom: 30px;
        }
        [data-theme="dark"] .filters { background: #1e293b; border: 1px solid #334155; box-shadow: none; }
        
        .filter-select {
            padding: 10px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: inherit;
            color: #1e293b;
            background: #fff;
            outline: none;
        }
        [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #f8fafc; }

        .interview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }

        .interview-card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            position: relative;
        }
        [data-theme="dark"] .interview-card { background: #1e293b; border: 1px solid #334155; box-shadow: none; }

        .status-badge {
            position: absolute;
            top: 24px;
            right: 24px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .seeker-info {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .seeker-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #475569;
            font-size: 18px;
            overflow: hidden;
        }
        .seeker-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .seeker-details h3 { margin: 0 0 4px 0; font-size: 18px; font-weight: 700; }
        .seeker-details p { margin: 0; color: #64748b; font-size: 14px; }
        [data-theme="dark"] .seeker-details p { color: #94a3b8; }

        .interview-details {
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
            margin-bottom: 24px;
        }
        [data-theme="dark"] .interview-details { border-color: #334155; }
        
        .detail-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 14px;
            color: #475569;
        }
        [data-theme="dark"] .detail-item { color: #94a3b8; }
        .detail-item i { width: 16px; margin-top: 2px; color: #94a3b8; }
        .detail-item strong { color: #1e293b; display: block; margin-bottom: 2px; }
        [data-theme="dark"] .detail-item strong { color: #f8fafc; }

        .card-actions {
            display: flex;
            gap: 12px;
        }
        .btn-view {
            flex: 1;
            padding: 10px;
            text-align: center;
            background: #f1f5f9;
            color: #0f172a;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-view:hover { background: #e2e8f0; }
        [data-theme="dark"] .btn-view { background: #334155; color: #f8fafc; }
        [data-theme="dark"] .btn-view:hover { background: #475569; }

        .btn-primary {
            flex: 1;
            padding: 10px;
            text-align: center;
            background: #3b82f6;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary:hover { background: #2563eb; color: #fff;}

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        [data-theme="dark"] .empty-state { background: #1e293b; box-shadow: none; border: 1px solid #334155; }
        .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 24px; }
        [data-theme="dark"] .empty-state i { color: #475569; }
        .empty-state h3 { margin: 0 0 8px 0; font-size: 24px; font-family: 'Sora', sans-serif;}
        .empty-state p { color: #64748b; margin: 0; }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <h1>My Interviews</h1>
            <p>Manage and conduct your assigned interviews</p>
        </div>
    </div>

    <div class="filters">
        <select class="filter-select" id="statusFilter" onchange="window.location.href='interviews.php?status='+this.value">
            <option value="">All Statuses</option>
            <option value="scheduled" <?php echo $filter_status == 'scheduled' ? 'selected' : ''; ?>>Upcoming</option>
            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>

    <?php if (empty($interviews)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>No Interviews Found</h3>
            <p>You have no interviews matching the current filter.</p>
        </div>
    <?php else: ?>
        <div class="interview-grid">
            <?php foreach ($interviews as $int): 
                $status_info = $status_colors[$int['status']] ?? $status_colors['scheduled'];
                $bg_color = $status_info[0] . '20'; // add transparency
                $text_color = $status_info[0];
                $type_icon = $type_icons[$int['interview_type']] ?? 'fa-video';
            ?>
                <div class="interview-card">
                    <div class="status-badge" style="background: <?php echo $bg_color; ?>; color: <?php echo $text_color; ?>">
                        <i class="fas <?php echo $status_info[1]; ?>"></i> <?php echo $status_info[2]; ?>
                    </div>

                    <div class="seeker-info">
                        <div class="seeker-avatar">
                            <?php if ($int['profile_picture']): ?>
                                <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($int['profile_picture']); ?>" alt="Avatar">
                            <?php else: ?>
                                <?php echo strtoupper(substr($int['username'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="seeker-details">
                            <h3><?php echo htmlspecialchars($int['username']); ?></h3>
                            <p><?php echo htmlspecialchars($int['job_title']); ?></p>
                        </div>
                    </div>

                    <div class="interview-details">
                        <div class="detail-item">
                            <i class="fas fa-calendar-day"></i>
                            <div>
                                <strong>Date & Time</strong>
                                <?php echo date('l, M d, Y', strtotime($int['interview_date'])); ?> at <?php echo date('h:i A', strtotime($int['interview_time'])); ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <i class="fas <?php echo $type_icon; ?>"></i>
                            <div>
                                <strong><?php echo $int['interview_type']; ?></strong>
                                <?php 
                                    if ($int['interview_type'] == 'Online') {
                                        echo "<a href='".htmlspecialchars($int['meeting_link'])."' target='_blank' style='color:#3b82f6;'>Join Meeting</a>";
                                    } else {
                                        echo htmlspecialchars($int['location']);
                                    }
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="card-actions">
                        <a href="interview_details.php?id=<?php echo $int['id']; ?>" class="btn-view">View Details</a>
                        <?php if ($int['status'] == 'scheduled' && $int['interview_type'] == 'Online' && $int['meeting_link']): ?>
                            <a href="<?php echo htmlspecialchars($int['meeting_link']); ?>" target="_blank" class="btn-primary">
                                <i class="fas fa-video"></i> Join
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
