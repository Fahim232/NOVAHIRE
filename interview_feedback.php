<?php
require_once 'config.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $interview_id = filter_input(INPUT_POST, 'interview_id', FILTER_VALIDATE_INT);
    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
    $comments = filter_input(INPUT_POST, 'comments', FILTER_SANITIZE_SPECIAL_CHARS);

    if ($interview_id && $rating >= 1 && $rating <= 5) {
        $stmt = $con->prepare("INSERT INTO interview_feedback (interview_id, rating, comments, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$interview_id, $rating, $comments]);
        header("Location: interview_feedback.php?status=success");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Interview Feedback - NOVAHIRE</title>
</head>
<body>
    <h2>Submit Interview Feedback</h2>
    <form method="POST" action="">
        <input type="hidden" name="interview_id" value="1">
        <label for="rating">Rating (1 to 5):</label>
        <input type="number" id="rating" name="rating" min="1" max="5" required>
        <br><br>
        <label for="comments">Comments:</label><br>
        <textarea id="comments" name="comments" rows="4" cols="50" required></textarea>
        <br><br>
        <button type="submit">Submit Feedback</button>
    </form>
</body>
</html>