<?php
    require_once __DIR__ . '/../includes/bootstrap.php';
    global $con;
    
    // Check if company is logged in
    if (!isset($_SESSION['company_id'])) {
        header('Location: ../auth/login.php');
        exit;
    }
    
    $company_id = $_SESSION['company_id'];
    $company_name = $_SESSION['company_name'] ?? 'Company';
    
    // Get job_id from URL
    if (!isset($_GET['job_id'])) {
        header('Location: my_jobs.php');
        exit;
    }
    
    $job_id = intval($_GET['job_id']);
    
    // Verify job belongs to this company
    $verify_query = "SELECT * FROM company_jobs WHERE id = $job_id AND company_id = $company_id";
    $verify_result = mysqli_query($con, $verify_query);
    
    if (!$verify_result || mysqli_num_rows($verify_result) == 0) {
        header('Location: my_jobs.php');
        exit;
    }
    
    $job_data = mysqli_fetch_assoc($verify_result);
    
    // Add new question
    if (isset($_POST['add_question'])) {
        // Capture raw values first
        $question_raw = trim($_POST['question']);
        $question_type = isset($_POST['question_type']) && $_POST['question_type'] === 'short_answer' ? 'short_answer' : 'mcq';
        $ideal_answer_raw = isset($_POST['ideal_answer']) ? trim($_POST['ideal_answer']) : '';
        $time_limit = isset($_POST['time_limit']) ? intval($_POST['time_limit']) : 60;
        $marks = isset($_POST['marks']) ? intval($_POST['marks']) : 1;

        if ($question_type === 'mcq') {
            $option1_raw = trim($_POST['option1']);
            $option2_raw = trim($_POST['option2']);
            $option3_raw = trim($_POST['option3']);
            $option4_raw = trim($_POST['option4']);
            $correct_key = $_POST['correct_answer']; // option1|option2|option3|option4

            // Map correct answer key to the actual option text
            $correct_answer_value = '';
            $option_map = [
                'option1' => $option1_raw,
                'option2' => $option2_raw,
                'option3' => $option3_raw,
                'option4' => $option4_raw,
            ];
            if (array_key_exists($correct_key, $option_map)) {
                $correct_answer_value = $option_map[$correct_key];
            }

            // Escape values for DB
            $question = mysqli_real_escape_string($con, $question_raw);
            $option1 = mysqli_real_escape_string($con, $option1_raw);
            $option2 = mysqli_real_escape_string($con, $option2_raw);
            $option3 = mysqli_real_escape_string($con, $option3_raw);
            $option4 = mysqli_real_escape_string($con, $option4_raw);
            $correct_answer = mysqli_real_escape_string($con, $correct_answer_value);
            $ideal_answer = mysqli_real_escape_string($con, $ideal_answer_raw);

            $insert_query = "INSERT INTO company_job_questions (job_id, question_type, question, option1, option2, option3, option4, correct_answer, ideal_answer, time_limit, marks)
                            VALUES ($job_id, 'mcq', '$question', '$option1', '$option2', '$option3', '$option4', '$correct_answer', " . ($ideal_answer ? "'$ideal_answer'" : "NULL") . ", $time_limit, $marks)";
        } else {
            // Short answer — no options, no correct_answer
            $question = mysqli_real_escape_string($con, $question_raw);
            $ideal_answer = mysqli_real_escape_string($con, $ideal_answer_raw);

            $insert_query = "INSERT INTO company_job_questions (job_id, question_type, question, option1, option2, option3, option4, correct_answer, ideal_answer, time_limit, marks)
                            VALUES ($job_id, 'short_answer', '$question', NULL, NULL, NULL, NULL, NULL, " . ($ideal_answer ? "'$ideal_answer'" : "NULL") . ", $time_limit, $marks)";
        }

        if (mysqli_query($con, $insert_query)) {
            $success_msg = '<div class="alert alert-success">Question added successfully!</div>';
        } else {
            $error_msg = '<div class="alert alert-danger">Failed to add question: ' . htmlspecialchars(mysqli_error($con)) . '</div>';
        }
    }
    
    // Delete question
    if (isset($_GET['delete_id'])) {
        $delete_id = intval($_GET['delete_id']);
        $delete_query = "DELETE FROM company_job_questions WHERE id = $delete_id AND job_id = $job_id";
        if (mysqli_query($con, $delete_query)) {
            $success_msg = '<div class="alert alert-success">Question deleted successfully!</div>';
        }
    }
    
    // Fetch all questions for this job
    $questions_query = "SELECT * FROM company_job_questions WHERE job_id = $job_id ORDER BY created_at DESC";
    $questions_result = mysqli_query($con, $questions_query);
    $question_count = $questions_result ? mysqli_num_rows($questions_result) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Quiz | Company Dashboard</title>
    <?php include '../includes/links.php'; ?>
    <style>
        /* Theme tokens */
        :root {
            --bg-main: linear-gradient(135deg, #d5d7de 0%, #f5f7ff 100%);
            --bg-accent-1: radial-gradient(circle at 10% 20%, rgba(102, 126, 234, 0.18), transparent 25%);
            --bg-accent-2: radial-gradient(circle at 90% 10%, rgba(244, 114, 182, 0.14), transparent 28%);
            --glass-bg: rgba(255, 255, 255, 0.65);
            --glass-border: rgba(255, 255, 255, 0.6);
            --glass-shadow: rgba(102, 126, 234, 0.2);
            --text-primary: #0f172a;
            --text-secondary: rgba(15, 23, 42, 0.75);
            --nav-bg: rgba(255, 255, 255, 0.75);
            --nav-border: rgba(255, 255, 255, 0.6);
            --badge-bg: rgba(255, 255, 255, 0.6);
        }

        [data-theme="dark"] {
            --bg-main: linear-gradient(135deg, #0f172a 0%, #111827 50%, #0b1021 100%);
            --bg-accent-1: radial-gradient(circle at 10% 20%, rgba(102, 126, 234, 0.32), transparent 25%);
            --bg-accent-2: radial-gradient(circle at 90% 10%, rgba(244, 114, 182, 0.26), transparent 28%);
            --glass-bg: rgba(255, 255, 255, 0.08);
            --glass-border: rgba(255, 255, 255, 0.14);
            --glass-shadow: rgba(0, 0, 0, 0.35);
            --text-primary: #e8edff;
            --text-secondary: rgba(232, 237, 255, 0.8);
            --nav-bg: rgba(255, 255, 255, 0.08);
            --nav-border: rgba(255, 255, 255, 0.15);
            --badge-bg: rgba(255, 255, 255, 0.12);
        }

        body {
            min-height: 100vh;
            background: var(--bg-accent-1), var(--bg-accent-2), var(--bg-main);
            color: var(--text-primary);
            transition: background 0.4s ease, color 0.3s ease;
        }

        .navbar {
            background: var(--nav-bg);
            border: 1px solid var(--nav-border);
            box-shadow: 0 15px 35px var(--glass-shadow);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
        }

        .navbar-brand {
            color: var(--text-primary) !important;
            font-weight: 700;
            font-size: 1.5rem;
        }

        .nav-link {
            color: var(--text-primary) !important;
            font-weight: 500;
            margin: 0 10px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            color: #667eea !important;
        }

        .btn-logout {
            background: var(--glass-bg);
            color: var(--text-primary) !important;
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 8px 25px;
            font-weight: 600;
            box-shadow: 0 8px 20px var(--glass-shadow);
        }

        .theme-toggle {
            background: var(--glass-bg);
            color: var(--text-primary);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 8px 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 8px 20px var(--glass-shadow);
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px var(--glass-shadow);
        }

        .content-section {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 15px;
            padding: 40px;
            margin: 30px 0;
            box-shadow: 0 10px 30px var(--glass-shadow);
            backdrop-filter: blur(16px);
            color: var(--text-primary);
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary);
        }

        .job-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .question-card {
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s;
            color: var(--text-primary);
        }

        .question-card:hover {
            border-color: #667eea;
            box-shadow: 0 8px 20px var(--glass-shadow);
        }

        .question-number {
            background: #667eea;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-right: 15px;
        }

        .option {
            background: var(--badge-bg);
            border: 1px solid var(--glass-border);
            padding: 10px 15px;
            border-radius: 8px;
            margin: 5px 0;
            color: var(--text-primary);
        }

        .correct-answer {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.2), rgba(40, 167, 69, 0.1));
            border: 2px solid #28a745;
            font-weight: 600;
        }

        .form-control {
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            color: var(--text-primary);
            padding: 6px 15px;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 1px 2px var(--glass-shadow);
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            background: rgba(255, 255, 255, 0.9);
            color: gray;
        }

        .btn-add {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            padding: 12px 40px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .stats-badge {
            background: var(--badge-bg);
            border: 1px solid var(--glass-border);
            padding: 10px 20px;
            border-radius: 20px;
            display: inline-block;
            margin-right: 15px;
            color: var(--text-primary);
        }

        label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .badge {
            background: var(--badge-bg);
            border: 1px solid var(--glass-border);
            color: var(--text-primary);
        }

        h1, h2, h3, h4, h5, h6 {
            color: var(--text-primary);
        }

        p {
            color: var(--text-secondary);
        }

        .text-muted {
            color: var(--text-secondary) !important;
        }

        /* Question type toggle */
        .qtype-toggle {
            display: flex;
            gap: 0;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--glass-border);
            width: fit-content;
            margin-bottom: 18px;
        }
        .qtype-toggle input[type="radio"] { display: none; }
        .qtype-toggle label {
            padding: 10px 28px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            background: var(--glass-bg);
            color: var(--text-secondary);
            margin: 0;
        }
        .qtype-toggle input[type="radio"]:checked + label {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .qtype-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .qtype-badge.mcq {
            background: rgba(102, 126, 234, 0.15);
            color: #667eea;
        }
        .qtype-badge.short_answer {
            background: rgba(234, 88, 12, 0.15);
            color: #ea580c;
        }
        .ideal-answer-box {
            background: rgba(5, 150, 105, 0.08);
            border: 1px dashed rgba(5, 150, 105, 0.3);
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 12px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .ideal-answer-box strong {
            color: #059669;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php require_once __DIR__ . '/company_header.php'; ?>

    <div class="container">
        <!-- Job Info Card -->
        <div class="job-info-card mt-4">
            <h3><i class="fas fa-briefcase mr-2"></i><?php echo $job_data['job_title']; ?></h3>
            <p class="mb-3"><?php echo $job_data['job_category']; ?> | <?php echo $job_data['location']; ?></p>
            <div>
                <span class="stats-badge">
                    <i class="fas fa-question-circle mr-2"></i><?php echo $question_count; ?> Questions
                </span>
                <a href="my_jobs.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Jobs
                </a>
            </div>
        </div>

        <!-- Add New Question -->
        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-plus-circle mr-3"></i>Add New Question</h2>
            
            <?php if (isset($error_msg)) echo $error_msg; ?>
            <?php if (isset($success_msg)) echo $success_msg; ?>
            
            <form method="POST" action="" id="addQuestionForm">
                <!-- Question Type Toggle -->
                <label>Question Type</label>
                <div class="qtype-toggle mb-3">
                    <input type="radio" name="question_type" id="qt_mcq" value="mcq" checked>
                    <label for="qt_mcq"><i class="fas fa-list-ul mr-2"></i>Multiple Choice</label>
                    <input type="radio" name="question_type" id="qt_short" value="short_answer">
                    <label for="qt_short"><i class="fas fa-pen-fancy mr-2"></i>Short Answer</label>
                </div>

                <div class="form-group">
                    <label for="question">Question *</label>
                    <textarea class="form-control" id="question" name="question" rows="3" required 
                              placeholder="Enter your quiz question here..."></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="time_limit"><i class="far fa-clock mr-1"></i> Time Limit (Seconds) *</label>
                            <input type="number" class="form-control" id="time_limit" name="time_limit" value="60" min="1" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="marks"><i class="fas fa-star mr-1"></i> Marks (Points) *</label>
                            <input type="number" class="form-control" id="marks" name="marks" value="1" min="1" required>
                        </div>
                    </div>
                </div>
                
                <!-- MCQ Options (shown only for MCQ type) -->
                <div id="mcqOptionsBlock">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="option1">Option 1 *</label>
                                <input type="text" class="form-control mcq-field" id="option1" name="option1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="option2">Option 2 *</label>
                                <input type="text" class="form-control mcq-field" id="option2" name="option2" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="option3">Option 3 *</label>
                                <input type="text" class="form-control mcq-field" id="option3" name="option3" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="option4">Option 4 *</label>
                                <input type="text" class="form-control mcq-field" id="option4" name="option4" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="correct_answer">Correct Answer *</label>
                        <select class="form-control mcq-field" id="correct_answer" name="correct_answer" required>
                            <option value="">Select the correct answer</option>
                            <option value="option1">Option 1</option>
                            <option value="option2">Option 2</option>
                            <option value="option3">Option 3</option>
                            <option value="option4">Option 4</option>
                        </select>
                    </div>
                </div>

                <!-- Ideal Answer (for AI grading — useful for both types but required for short answer) -->
                <div class="form-group" id="idealAnswerBlock">
                    <label for="ideal_answer">
                        <i class="fas fa-robot mr-1" style="color:#667eea"></i>
                        Ideal Answer <span id="idealAnswerReqLabel">(Optional for MCQ)</span>
                    </label>
                    <textarea class="form-control" id="ideal_answer" name="ideal_answer" rows="3" 
                              placeholder="Provide the ideal/expected answer. For short-answer questions this guides the AI grader."></textarea>
                    <small class="text-muted">This is used by the AI engine to evaluate short-answer responses.</small>
                </div>
                
                <button type="submit" name="add_question" class="btn btn-add">
                    <i class="fas fa-plus mr-2"></i>Add Question
                </button>
            </form>
        </div>

        <!-- Existing Questions -->
        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-list mr-3"></i>Quiz Questions (<?php echo $question_count; ?>)</h2>
            
            <?php if ($question_count > 0): ?>
                <?php $counter = 1; ?>
                <?php while ($q = mysqli_fetch_assoc($questions_result)): ?>
                    <?php
                        $q_type = isset($q['question_type']) ? $q['question_type'] : 'mcq';
                        // Normalize legacy correct_answer that might store option key
                        $correct_value = $q['correct_answer'];
                        if (in_array($correct_value, ['option1','option2','option3','option4'])) {
                            $correct_value = $q[$correct_value];
                        }
                    ?>
                    <div class="question-card">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="d-flex align-items-start">
                                <span class="question-number"><?php echo $counter++; ?></span>
                                <div>
                                    <span class="qtype-badge <?php echo $q_type; ?>">
                                        <?php echo $q_type === 'short_answer' ? 'Short Answer' : 'MCQ'; ?>
                                    </span>
                                    <h5 class="mt-1"><?php echo htmlspecialchars($q['question']); ?></h5>
                                    <small class="text-muted">
                                        <i class="far fa-clock mr-1"></i> <?php echo isset($q['time_limit']) ? $q['time_limit'] : 60; ?>s
                                        <span class="mx-2">|</span>
                                        <i class="fas fa-star mr-1"></i> <?php echo isset($q['marks']) ? $q['marks'] : 1; ?> pts
                                        <span class="mx-2">|</span>
                                        Added on <?php echo date('M d, Y', strtotime($q['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                            <a href="?job_id=<?php echo $job_id; ?>&delete_id=<?php echo $q['id']; ?>" 
                               class="btn btn-danger btn-sm" 
                               onclick="return confirm('Are you sure you want to delete this question?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                        
                        <?php if ($q_type === 'mcq'): ?>
                        <div class="options">
                            <div class="option <?php echo ($correct_value == $q['option1']) ? 'correct-answer' : ''; ?>">
                                <strong>A.</strong> <?php echo htmlspecialchars($q['option1']); ?>
                                <?php if ($correct_value == $q['option1']): ?>
                                    <i class="fas fa-check-circle text-success float-right"></i>
                                <?php endif; ?>
                            </div>
                            <div class="option <?php echo ($correct_value == $q['option2']) ? 'correct-answer' : ''; ?>">
                                <strong>B.</strong> <?php echo htmlspecialchars($q['option2']); ?>
                                <?php if ($correct_value == $q['option2']): ?>
                                    <i class="fas fa-check-circle text-success float-right"></i>
                                <?php endif; ?>
                            </div>
                            <div class="option <?php echo ($correct_value == $q['option3']) ? 'correct-answer' : ''; ?>">
                                <strong>C.</strong> <?php echo htmlspecialchars($q['option3']); ?>
                                <?php if ($correct_value == $q['option3']): ?>
                                    <i class="fas fa-check-circle text-success float-right"></i>
                                <?php endif; ?>
                            </div>
                            <div class="option <?php echo ($correct_value == $q['option4']) ? 'correct-answer' : ''; ?>">
                                <strong>D.</strong> <?php echo htmlspecialchars($q['option4']); ?>
                                <?php if ($correct_value == $q['option4']): ?>
                                    <i class="fas fa-check-circle text-success float-right"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info mb-0" style="border-radius:10px">
                            <i class="fas fa-pen-fancy mr-2"></i>
                            Candidates will type a free-text answer. AI will grade responses.
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($q['ideal_answer'])): ?>
                        <div class="ideal-answer-box">
                            <strong><i class="fas fa-robot mr-1"></i>Ideal Answer:</strong>
                            <?php echo htmlspecialchars($q['ideal_answer']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle mr-2"></i>No questions added yet. Add your first question above!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="text-center py-4 mt-5">
        <p class="text-muted">&copy; 2026 NovaHire. All rights reserved.</p>
    </footer>

    <script>
        // Question type toggle: show/hide MCQ options
        const qtMcq   = document.getElementById('qt_mcq');
        const qtShort = document.getElementById('qt_short');
        const mcqBlock = document.getElementById('mcqOptionsBlock');
        const idealLabel = document.getElementById('idealAnswerReqLabel');
        const mcqFields = document.querySelectorAll('.mcq-field');

        const timeLimitInput = document.getElementById('time_limit');
        const marksInput = document.getElementById('marks');

        function toggleQuestionType() {
            const isMcq = qtMcq.checked;
            mcqBlock.style.display = isMcq ? 'block' : 'none';
            idealLabel.textContent = isMcq ? '(Optional for MCQ)' : '(Recommended for AI grading)';
            mcqFields.forEach(function(f) {
                if (isMcq) {
                    f.setAttribute('required', 'required');
                } else {
                    f.removeAttribute('required');
                }
            });

            // Adjust default time limits and marks when toggling
            if (isMcq && timeLimitInput.value == '180') {
                timeLimitInput.value = '60';
                marksInput.value = '1';
            } else if (!isMcq && timeLimitInput.value == '60') {
                timeLimitInput.value = '180';
                marksInput.value = '5';
            }
        }

        qtMcq.addEventListener('change', toggleQuestionType);
        qtShort.addEventListener('change', toggleQuestionType);
        toggleQuestionType(); // init state

        // Auto-populate correct answer select based on typed options
        document.getElementById('correct_answer').addEventListener('change', function() {
            const selectedOption = this.value;
            if (selectedOption) {
                const optionText = document.getElementById(selectedOption).value;
                console.log('Correct answer will be: ' + optionText);
            }
        });
    </script>
</body>
</html>
