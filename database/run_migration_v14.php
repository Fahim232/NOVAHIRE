<?php

/**
 * NovaHire v14 Migration
 * Candidate Application Tracker
 */

require_once __DIR__ . '/../config/database.php';

$sqlFile = __DIR__ . '/features_v14_application_tracker.sql';

if (!file_exists($sqlFile)) {
    die("Migration SQL file not found.");
}

$sql = file_get_contents($sqlFile);

if ($sql === false) {
    die("Unable to read migration SQL file.");
}

/*
 * Support both mysqli-style and PDO-style database connections.
 */

try {

    if (isset($conn) && $conn instanceof mysqli) {

        if (!$conn->multi_query($sql)) {
            throw new Exception($conn->error);
        }

        // Consume all result sets.
        while ($conn->more_results() && $conn->next_result()) {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        }

    } elseif (isset($pdo) && $pdo instanceof PDO) {

        $pdo->exec($sql);

    } else {

        throw new Exception(
            "No supported database connection found."
        );
    }

    echo "<!DOCTYPE html>";
    echo "<html>";
    echo "<head>";
    echo "<title>NovaHire v14 Migration</title>";
    echo "<style>";
    echo "
        body {
            font-family: Arial, sans-serif;
            background: #f5f3ff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .box {
            background: white;
            padding: 35px;
            border-radius: 14px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            text-align: center;
        }

        h2 {
            color: #4f46e5;
        }

        p {
            color: #555;
        }
    ";
    echo "</style>";
    echo "</head>";

    echo "<body>";
    echo "<div class='box'>";
    echo "<h2>✓ Migration Successful</h2>";
    echo "<p>NovaHire v14 Candidate Application Tracker has been installed.</p>";
    echo "</div>";
    echo "</body>";
    echo "</html>";

} catch (Throwable $e) {

    http_response_code(500);

    echo "<h2>Migration Failed</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}