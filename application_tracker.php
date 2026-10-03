<?php
require_once 'config.php';

session_start();
$seeker_id = $_SESSION['seeker_id'] ?? 1;

$stmt = $con->prepare("
    SELECT a.id, a.job_title, a.company_name, a.status, a.applied_date 
    FROM applications a 
    WHERE a.seeker_id = ? 
    ORDER BY a.applied_date DESC
");
$stmt->execute([$seeker_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Application Tracker - NOVAHIRE</title>
</head>
<body>
    <h2>My Job Applications</h2>
    <table border="1" cellpadding="8" cellspacing="0">
        <thead>
            <tr>
                <th>Job Title</th>
                <th>Company</th>
                <th>Status</th>
                <th>Applied Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($applications)): ?>
                <?php foreach ($applications as $app): ?>
                    <tr>
                        <td><?= htmlspecialchars($app['job_title']); ?></td>
                        <td><?= htmlspecialchars($app['company_name']); ?></td>
                        <td><?= htmlspecialchars($app['status']); ?></td>
                        <td><?= htmlspecialchars($app['applied_date']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4">No applications submitted yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>