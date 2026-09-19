<?php
/**
 * NovaHire — Public Certificate Verification Portal
 */
require_once __DIR__ . '/bootstrap.php';
global $con;
if (!isset($con) && file_exists(__DIR__ . '/../admin/dbcon.php')) {
    require_once __DIR__ . '/../admin/dbcon.php';
}

$code = trim($_GET['code'] ?? '');
$cert = null;

if (!empty($code) && isset($con) && $con) {
    $stmt = mysqli_prepare($con, "SELECT c.*, u.username, u.email, u.profile 
                                   FROM certificates c 
                                   JOIN user_info u ON c.user_id = u.id 
                                   WHERE c.cert_code = ? AND c.is_paid = 1 
                                   LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $cert = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $cert ? 'Verified: ' . htmlspecialchars($cert['title']) : 'Certificate Verification' ?> · NovaHire</title>
    <?php if (file_exists(__DIR__ . '/links.php')) include __DIR__ . '/links.php'; ?>
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            font-family: 'Inter', sans-serif;
        }
        .cert-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.09);
            max-width: 680px;
            width: 100%;
            padding: 48px 40px;
            position: relative;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .cert-ribbon {
            position: absolute;
            top: 24px;
            right: -35px;
            background: #10b981;
            color: #fff;
            padding: 6px 45px;
            transform: rotate(45deg);
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 1px;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .cert-logo {
            font-weight: 900;
            font-size: 1.6rem;
            background: linear-gradient(135deg, #1e40af, #0284c7);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 24px;
            letter-spacing: -0.5px;
        }
        .badge-icon {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: #ecfdf5;
            color: #059669;
            display: grid;
            place-items: center;
            font-size: 2.2rem;
            margin: 0 auto 20px;
            border: 2px solid #a7f3d0;
        }
        .badge-err {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: #fef2f2;
            color: #dc2626;
            display: grid;
            place-items: center;
            font-size: 2.2rem;
            margin: 0 auto 20px;
            border: 2px solid #fecaca;
        }
        .cert-title {
            font-size: 1.7rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .cert-recipient {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e40af;
            margin: 16px 0 6px;
        }
        .cert-desc {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .cert-meta {
            background: #f8fafc;
            border-radius: 16px;
            padding: 18px 24px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 28px;
            border: 1px solid #e2e8f0;
        }
        .meta-item strong {
            display: block;
            font-size: 1rem;
            font-weight: 800;
            color: #1e293b;
        }
        .meta-item span {
            font-size: 0.78rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }
        .cert-btn {
            background: linear-gradient(135deg, #1e40af, #0284c7);
            color: #fff;
            padding: 12px 28px;
            border-radius: 99px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s;
        }
        .cert-btn:hover {
            opacity: 0.92;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="cert-card">
        <div class="cert-logo">NovaHire</div>

        <?php if ($cert): ?>
            <div class="cert-ribbon">VERIFIED</div>
            <div class="badge-icon"><i class="fas fa-check-circle"></i></div>
            <h1 class="cert-title"><?= htmlspecialchars($cert['title']) ?></h1>
            <p class="cert-desc">This officially confirms that the skill evaluation for this credential was successfully passed.</p>
            
            <div style="margin: 20px 0;">
                <div style="font-size: 0.85rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Issued To</div>
                <div class="cert-recipient"><?= htmlspecialchars($cert['username']) ?></div>
            </div>

            <div class="cert-meta">
                <div class="meta-item">
                    <strong><?= htmlspecialchars($cert['category']) ?></strong>
                    <span>Skill Category</span>
                </div>
                <div class="meta-item">
                    <strong><?= (int)$cert['score'] ?>%</strong>
                    <span>Evaluation Score</span>
                </div>
                <div class="meta-item">
                    <strong><?= date('M j, Y', strtotime($cert['issued_at'])) ?></strong>
                    <span>Issue Date</span>
                </div>
            </div>

            <div style="font-size: 0.82rem; color: #94a3b8; margin-bottom: 24px;">
                Verification Code: <code style="font-weight: 700; color: #334155;"><?= htmlspecialchars($cert['cert_code']) ?></code>
            </div>

            <a href="<?= BASE_URL ?>/" class="cert-btn">
                <i class="fas fa-arrow-left"></i> Return to NovaHire
            </a>
        <?php else: ?>
            <div class="badge-err"><i class="fas fa-times-circle"></i></div>
            <h1 class="cert-title">Certificate Not Found</h1>
            <p class="cert-desc">The requested credential code <code><?= htmlspecialchars($code ?: 'EMPTY') ?></code> is invalid or unverified.</p>
            <a href="<?= BASE_URL ?>/" class="cert-btn">
                <i class="fas fa-arrow-left"></i> Return Home
            </a>
        <?php endif; ?>
    </div>
</body>
</html>
