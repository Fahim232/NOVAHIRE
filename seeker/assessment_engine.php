<?php
// Core setup: session, DB, BASE_URL, helpers
require_once __DIR__ . '/../includes/bootstrap.php';
require_seeker_login();
require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/header.php';

$assessment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$assessment_id) {
    echo "<script>window.location.href='skills.php';</script>";
    exit;
}
?>

<style>
    .quiz-container { background: #fff; border-radius: 14px; padding: 32px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); max-width: 820px; margin: 30px auto; border: 1px solid #e2e8f0; }
    .timer-badge { font-size: 1.1rem; font-weight: 700; background: #d9b4b4ff; color: #ef4444; padding: 8px 18px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; }
    .question-card { display: none; }
    .question-card.active { display: block; animation: fadeIn 0.3s ease-in-out; }
    .option-label { display: block; padding: 14px 20px; border: 2px solid #e2e8f0; border-radius: 10px; margin-bottom: 12px; cursor: pointer; transition: all 0.2s ease; background: #6d6c6cff; }
    .option-label:hover { border-color: #3b82f6; background: #6c6d6fff; }
    .option-label input:checked + span { font-weight: 700; color: #1d4ed8; }
    .option-label:has(input:checked) { border-color: #3b82f6; background: #eff6ff; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .loading { text-align: center; padding: 50px 20px; font-size: 1.1rem; color: #64748b; }
    .result-card { text-align: center; padding: 20px 0; }
    .result-badge { display: inline-block; padding: 10px 24px; border-radius: 20px; font-weight: 700; font-size: 1.1rem; margin-top: 15px; }
    .result-passed { background: #d1fae5; color: #059669; }
    .result-failed { background: #fee2e2; color: #dc2626; }
    @keyframes pulse { from { opacity: 1; } to { opacity: 0.5; } }
</style>

<div class="container my-4">
    <div class="quiz-container" id="quizContainer">
        <div class="loading" id="loadingState">
            <i class="fas fa-spinner fa-spin fa-2x mb-3 text-primary"></i><br>
            Preparing your assessment...
        </div>
        
        <div id="errorState" style="display:none;" class="text-center py-4">
            <div class="alert alert-danger" id="errorMessage"></div>
            <a href="skills.php" class="btn btn-primary mt-3">Back to Skills</a>
        </div>
        
        <div id="quizState" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <h4 class="m-0 font-weight-bold" id="quizProgress" style="color: #1e293b;">Question 1 of 10</h4>
                <div class="timer-badge"><i class="far fa-clock"></i> <span id="timeDisplay">00:00</span></div>
            </div>
            
            <form id="quizForm">
                <div id="questionsContainer"></div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary" id="prevBtn" onclick="navigate(-1)" disabled>Previous</button>
                    <button type="button" class="btn btn-primary" id="nextBtn" onclick="navigate(1)">Next</button>
                    <button type="button" class="btn btn-success" id="submitBtn" onclick="submitQuiz()" style="display:none;">Submit Assessment</button>
                </div>
            </form>
        </div>
        
        <div id="resultState" style="display:none;" class="result-card">
            <h2 id="resultTitle" class="font-weight-bold" style="color: #1e293b;">Assessment Complete</h2>
            <div class="display-4 font-weight-bold my-4" id="resultScore" style="color: #2563eb;">0%</div>
            <div id="resultBadge" class="result-badge"></div>
            <p class="mt-4 text-muted" id="resultMessage" style="font-size: 1.05rem;"></p>
            <a href="skills.php" class="btn btn-primary mt-3 px-4">Return to Skills Dashboard</a>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let attemptId = null;
    let questions = [];
    let currentQuestion = 0;
    let timeRemaining = 0;
    let timerInterval = null;
    let answers = {};
    
    $(document).ready(function() {
        startAssessment();
    });
    
    function startAssessment() {
        $.ajax({
            url: '../api/skill_assessments.php?action=start',
            type: 'POST',
            data: { assessment_id: <?php echo $assessment_id; ?> },
            dataType: 'json',
            success: function(res) {
                $('#loadingState').hide();
                
                if (res.success) {
                    attemptId = res.attempt_id;
                    questions = res.questions;
                    timeRemaining = res.time_limit * 60; // minutes to seconds
                    
                    if (questions.length === 0) {
                        showError("This assessment has no questions configured yet.");
                        return;
                    }
                    
                    renderQuestions();
                    $('#quizState').show();
                    startTimer();
                    updateNavigation();
                } else {
                    showError(res.message);
                }
            },
            error: function() {
                $('#loadingState').hide();
                showError("A network error occurred. Please try again.");
            }
        });
    }
    
    function renderQuestions() {
        let html = '';
        questions.forEach((q, index) => {
            let activeClass = index === 0 ? 'active' : '';
            html += `
                <div class="question-card ${activeClass}" id="q_card_${index}">
                    <h5 class="mb-4 font-weight-bold" style="color: #1e293b;">${index + 1}. ${escapeHtml(q.question)}</h5>
                    <label class="option-label">
                        <input type="radio" name="ans_${q.id}" value="${escapeHtml(q.option1)}" onchange="saveAnswer(${q.id}, this.value)"> <span>${escapeHtml(q.option1)}</span>
                    </label>
                    <label class="option-label">
                        <input type="radio" name="ans_${q.id}" value="${escapeHtml(q.option2)}" onchange="saveAnswer(${q.id}, this.value)"> <span>${escapeHtml(q.option2)}</span>
                    </label>
            `;
            
            if (q.option3 && q.option3.trim() !== '') {
                html += `
                    <label class="option-label">
                        <input type="radio" name="ans_${q.id}" value="${escapeHtml(q.option3)}" onchange="saveAnswer(${q.id}, this.value)"> <span>${escapeHtml(q.option3)}</span>
                    </label>
                `;
            }
            
            if (q.option4 && q.option4.trim() !== '') {
                html += `
                    <label class="option-label">
                        <input type="radio" name="ans_${q.id}" value="${escapeHtml(q.option4)}" onchange="saveAnswer(${q.id}, this.value)"> <span>${escapeHtml(q.option4)}</span>
                    </label>
                `;
            }
            
            html += `</div>`;
        });
        $('#questionsContainer').html(html);
    }
    
    function navigate(dir) {
        $(`#q_card_${currentQuestion}`).removeClass('active');
        currentQuestion += dir;
        $(`#q_card_${currentQuestion}`).addClass('active');
        updateNavigation();
    }
    
    function updateNavigation() {
        $('#quizProgress').text(`Question ${currentQuestion + 1} of ${questions.length}`);
        $('#prevBtn').prop('disabled', currentQuestion === 0);
        
        if (currentQuestion === questions.length - 1) {
            $('#nextBtn').hide();
            $('#submitBtn').show();
        } else {
            $('#nextBtn').show();
            $('#submitBtn').hide();
        }
    }
    
    function saveAnswer(qId, val) {
        answers[qId] = val;
    }
    
    function startTimer() {
        updateTimerDisplay();
        timerInterval = setInterval(() => {
            timeRemaining--;
            updateTimerDisplay();
            if (timeRemaining <= 0) {
                clearInterval(timerInterval);
                alert("Time's up! Submitting your answers automatically.");
                submitQuiz();
            }
        }, 1000);
    }
    
    function updateTimerDisplay() {
        let m = Math.floor(timeRemaining / 60).toString().padStart(2, '0');
        let s = (timeRemaining % 60).toString().padStart(2, '0');
        $('#timeDisplay').text(`${m}:${s}`);
        if (timeRemaining < 60) {
            $('.timer-badge').css('animation', 'pulse 1s infinite alternate');
        }
    }
    
    function submitQuiz() {
        clearInterval(timerInterval);
        $('#quizState').hide();
        $('#loadingState').html('<i class="fas fa-spinner fa-spin fa-2x mb-3 text-primary"></i><br>Scoring your assessment...').show();
        
        $.ajax({
            url: '../api/skill_assessments.php?action=submit',
            type: 'POST',
            data: {
                attempt_id: attemptId,
                answers: JSON.stringify(answers)
            },
            dataType: 'json',
            success: function(res) {
                $('#loadingState').hide();
                if (res.success) {
                    $('#resultScore').text(res.score + '%');
                    $('#resultMessage').text(res.message);
                    if (res.passed) {
                        $('#resultBadge').text(`Verified: ${res.level}`).removeClass('result-failed').addClass('result-passed');
                    } else {
                        $('#resultBadge').text('Not Verified').removeClass('result-passed').addClass('result-failed');
                    }
                    $('#resultState').show();
                } else {
                    showError(res.message);
                }
            },
            error: function() {
                $('#loadingState').hide();
                showError("Failed to submit assessment. Please contact support.");
            }
        });
    }
    
    function showError(msg) {
        $('#errorMessage').text(msg);
        $('#errorState').show();
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
</script>
</body>
</html>
