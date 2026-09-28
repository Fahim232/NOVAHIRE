<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (!isset($_SESSION['id'])) { header('location: ' . BASE_URL . '/auth/login.php'); exit(); }
require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../ai/config.php';
require_once __DIR__ . '/../ai/helpers.php';

$user_id = $_SESSION['id'];
$user = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM user_info WHERE id = '$user_id'"));
$username = htmlspecialchars($user['username'] ?? 'User');
$initial = strtoupper(substr($user['username'] ?? 'U', 0, 1));

$fields = ['username'=>15,'email'=>15,'phone'=>15,'user_degree'=>15,'user_skills'=>20,'profile'=>10,'about_me'=>10];
$completion = 0;
foreach ($fields as $f=>$w) if (!empty($user[$f])) $completion += $w;

$provider_label = ai_provider_label();
$llm_on = ai_llm_available();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
:root {
    --ai-primary: var(--primary, #1a56db);
    --ai-secondary: var(--secondary, #0ea5e9);
    --ai-accent: var(--accent, #0ea5e9);
    --ai-grad: var(--grad, linear-gradient(135deg, #1a56db, #0ea5e9 55%, #0ea5e9));
    --ai-bg: var(--bg, #f8fafc);
    --ai-card: var(--bg-card, #ffffff);
    --ai-text: var(--text, #0f172a);
    --ai-muted: var(--text-muted, #64748b);
    --ai-border: var(--border, #e2e8f0);
    --ai-radius: var(--radius-lg, 18px);
}

[data-theme="dark"],
body.dark-theme,
:root[data-theme="dark"] {
    --ai-bg: var(--bg, #0c1222);
    --ai-card: var(--bg-card, #162032);
    --ai-text: var(--text, #f1f5f9);
    --ai-muted: var(--text-muted, #94a3b8);
    --ai-border: var(--border, #334155);
}

body {
    background: var(--ai-bg);
    margin: 0;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--ai-text);
    transition: background-color 0.25s ease, color 0.25s ease;
}

/* ── AI Center Hero ── */
.ai-hub-hero {
    background: var(--ai-grad);
    color: #fff;
    padding: 56px 0 46px;
    position: relative;
    overflow: hidden;
    text-align: center;
}
.ai-hub-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -20%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,.12) 0%, transparent 70%);
    border-radius: 50%;
}
.ai-hub-hero::after {
    content: '';
    position: absolute;
    bottom: -40%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 70%);
    border-radius: 50%;
}
.ai-hero-chip {
    background: rgba(255,255,255,.18);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.25);
    padding: 6px 18px;
    border-radius: 9999px;
    font-size: .8rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(0,0,0,0.1);
}
.ai-hub-hero h1 {
    font-weight: 800;
    font-size: 2.2rem;
    margin: 0 0 10px;
    position: relative;
    letter-spacing: -0.5px;
}
.ai-hub-hero p {
    opacity: .92;
    font-size: 1.05rem;
    margin: 0 auto;
    max-width: 680px;
    position: relative;
    line-height: 1.6;
}

/* ── Stats Row ── */
.ai-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-top: -34px;
    position: relative;
    z-index: 2;
    padding: 0 24px;
    max-width: 960px;
    margin-left: auto;
    margin-right: auto;
}
.ai-stat {
    background: var(--ai-card);
    border: 1px solid var(--ai-border);
    border-radius: var(--ai-radius);
    padding: 22px 18px;
    text-align: center;
    box-shadow: 0 8px 24px rgba(0,0,0,.06);
    transition: all .3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    display: block;
}
.ai-stat:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 32px rgba(0,0,0,.12);
    border-color: var(--ai-primary);
    text-decoration: none;
    color: inherit;
}
[data-theme="dark"] .ai-stat:hover,
body.dark-theme .ai-stat:hover {
    box-shadow: 0 16px 36px rgba(0,0,0,.45);
    border-color: var(--ai-primary);
}
.ai-stat .val {
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--ai-text);
    line-height: 1.2;
}
.ai-stat .lbl {
    font-size: .72rem;
    font-weight: 700;
    color: var(--ai-muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-top: 5px;
}
.ai-stat .ico {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    font-size: 1.1rem;
}

/* ── Section & Filter Navigation ── */
.ai-section {
    max-width: 1340px;
    margin: 0 auto;
    padding: 44px 24px 60px;
}
.ai-section-title {
    font-weight: 800;
    font-size: 1.35rem;
    color: var(--ai-text);
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.3px;
}
.ai-section-title i {
    color: var(--ai-primary);
    font-size: 1.2rem;
}
.ai-section-sub {
    font-size: .92rem;
    color: var(--ai-muted);
    margin: 0 0 28px;
}

/* ── Feature Cards ── */
.ai-features {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 22px;
}
.ai-fcard {
    background: var(--ai-card);
    border: 1px solid var(--ai-border);
    border-radius: var(--ai-radius);
    padding: 28px 24px;
    text-decoration: none !important;
    display: flex;
    flex-direction: column;
    transition: all .3s cubic-bezier(.4, 0, .2, 1);
    box-shadow: 0 4px 18px rgba(0,0,0,.04);
    position: relative;
    overflow: hidden;
}
.ai-fcard::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--ai-grad);
    opacity: 0;
    transition: opacity .3s ease;
}
.ai-fcard:hover,
.ai-fcard:focus-visible {
    transform: translateY(-6px);
    box-shadow: 0 18px 42px rgba(0,0,0,.12);
    border-color: var(--ai-primary);
}
[data-theme="dark"] .ai-fcard:hover,
body.dark-theme .ai-fcard:hover {
    box-shadow: 0 18px 46px rgba(0,0,0,.5);
    border-color: var(--ai-primary);
}
.ai-fcard:hover::before,
.ai-fcard:focus-visible::before {
    opacity: 1;
}
.ai-fcard:focus-visible {
    outline: 2px solid var(--ai-primary);
    outline-offset: 2px;
}
.ai-fcard-icon {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    margin-bottom: 18px;
    transition: transform .3s ease;
    flex-shrink: 0;
}
.ai-fcard:hover .ai-fcard-icon {
    transform: scale(1.1);
}
.ai-fcard h5 {
    font-weight: 700;
    color: var(--ai-text);
    font-size: 1.06rem;
    margin: 0 0 8px;
    letter-spacing: -0.2px;
}
.ai-fcard p {
    color: var(--ai-muted);
    font-size: .86rem;
    line-height: 1.62;
    margin: 0;
    flex: 1;
}
.ai-fcard .ai-go {
    color: var(--ai-primary);
    font-weight: 700;
    font-size: .84rem;
    margin-top: 18px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: gap .2s ease;
}
.ai-fcard:hover .ai-go {
    gap: 9px;
}
.ai-fcard-tag {
    position: absolute;
    top: 16px;
    right: 16px;
    font-size: .65rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

/* Tags in Light / Dark */
.tag-blue { background: rgba(26,86,219,.08); color: #1a56db; }
.tag-green { background: rgba(16,185,129,.08); color: #10b981; }
.tag-pink { background: rgba(236,72,153,.08); color: #db2777; }
.tag-cyan { background: rgba(14,165,233,.08); color: #0ea5e9; }

[data-theme="dark"] .tag-blue, body.dark-theme .tag-blue { background: rgba(96,165,250,.18); color: #93c5fd; }
[data-theme="dark"] .tag-green, body.dark-theme .tag-green { background: rgba(52,211,153,.18); color: #34d399; }
[data-theme="dark"] .tag-pink, body.dark-theme .tag-pink { background: rgba(244,114,182,.18); color: #f472b6; }
[data-theme="dark"] .tag-cyan, body.dark-theme .tag-cyan { background: rgba(56,189,248,.18); color: #38bdf8; }

/* ── Scroll Animations ── */
.ai-fcard, .ai-stat, .ai-how-item {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity .5s ease, transform .5s ease;
}
.ai-fcard.visible, .ai-stat.visible, .ai-how-item.visible {
    opacity: 1;
    transform: translateY(0);
}

/* ── How It Works ── */
.ai-how {
    background: var(--ai-card);
    border: 1px solid var(--ai-border);
    border-radius: var(--ai-radius);
    padding: 34px 30px;
    box-shadow: 0 4px 18px rgba(0,0,0,.04);
    margin-top: 36px;
}
.ai-how h4 {
    font-weight: 800;
    font-size: 1.15rem;
    color: var(--ai-text);
    margin: 0 0 24px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ai-how h4 i {
    color: var(--ai-primary);
}
.ai-how-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}
.ai-how-item {
    display: flex;
    gap: 15px;
    align-items: flex-start;
}
.ai-how-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.1rem;
}
.ai-how-item strong {
    font-size: .92rem;
    color: var(--ai-text);
    display: block;
    margin-bottom: 4px;
    font-weight: 700;
}
.ai-how-item p {
    font-size: .83rem;
    color: var(--ai-muted);
    margin: 0;
    line-height: 1.55;
}

/* ── Scroll to Top ── */
.ai-scroll-top {
    position: fixed;
    bottom: 90px;
    right: 24px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--ai-grad);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    box-shadow: 0 4px 16px rgba(26,86,219,.3);
    opacity: 0;
    transform: translateY(20px);
    transition: all .3s ease;
    z-index: 9989;
}
.ai-scroll-top.show {
    opacity: 1;
    transform: translateY(0);
}
.ai-scroll-top:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(26,86,219,.45);
}

/* ── Responsive ── */
@media(max-width:991px) {
    .ai-features { grid-template-columns: repeat(2, 1fr); }
    .ai-how-grid { grid-template-columns: 1fr; gap: 20px; }
}
@media(max-width:767px) {
    .ai-stats { grid-template-columns: repeat(2, 1fr); margin-top: -24px; gap: 12px; }
    .ai-hub-hero h1 { font-size: 1.65rem; }
    .ai-hub-hero { padding: 42px 16px 36px; }
}
@media(max-width:575px) {
    .ai-features { grid-template-columns: 1fr; }
    .ai-stats { padding: 0 14px; gap: 10px; }
    .ai-stat { padding: 16px 12px; }
    .ai-stat .val { font-size: 1.35rem; }
    .ai-section { padding: 28px 14px 44px; }
    .ai-how { padding: 22px 18px; }
}
</style>

<!-- Hero -->
<div class="ai-hub-hero">
    <div class="container">
        <div class="ai-hero-chip">
            <i class="fas fa-brain"></i>
            <span><?php echo $llm_on ? 'Powered by ' . $provider_label : 'Hybrid AI Engine - Always Online'; ?></span>
        </div>
        <h1>AI Career Center</h1>
        <p>Your intelligent toolkit for finding, applying, and preparing for your next career milestone.</p>
    </div>
</div>

<!-- Stats -->
<div class="ai-stats">
    <a class="ai-stat" href="profile.php" role="link" aria-label="Go to profile">
        <div class="ico" style="background:rgba(26,86,219,.12);color:var(--ai-primary);"><i class="fas fa-user-check"></i></div>
        <div class="val"><?php echo $completion; ?>%</div>
        <div class="lbl">Profile Ready</div>
    </a>
    <div class="ai-stat">
        <div class="ico" style="background:rgba(14,165,233,.12);color:var(--ai-secondary);"><i class="fas fa-bolt"></i></div>
        <div class="val" style="font-size:1.3rem;"><i class="fas fa-bolt"></i></div>
        <div class="lbl">Hybrid Engine</div>
    </div>
    <div class="ai-stat">
        <div class="ico" style="background:<?php echo $llm_on ? 'rgba(5,150,105,.12)' : 'rgba(217,119,6,.12)'; ?>;color:<?php echo $llm_on ? '#059669' : '#d97706'; ?>;"><i class="fas fa-cloud-bolt"></i></div>
        <div class="val" style="color:<?php echo $llm_on ? '#059669' : '#d97706'; ?>;font-size:1.15rem;"><?php echo $llm_on ? 'ONLINE' : 'OFFLINE'; ?></div>
        <div class="lbl"><?php echo $provider_label; ?></div>
    </div>
    <div class="ai-stat">
        <div class="ico" style="background:rgba(14,165,233,.12);color:var(--ai-secondary);"><i class="fas fa-shield-halved"></i></div>
        <div class="val" style="font-size:1.3rem;"><i class="fas fa-check-circle" style="color:#059669;"></i></div>
        <div class="lbl">Always Online</div>
    </div>
</div>

<!-- Features -->
<div class="ai-section">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <div>
            <h2 class="ai-section-title"><i class="fas fa-wand-magic-sparkles"></i> AI Career Tools</h2>
            <p class="ai-section-sub">Powerful AI assistants to analyze, write, practice, and accelerate your job search.</p>
        </div>
    </div>

    <div class="ai-features">
        <!-- Resume Analyzer -->
        <a href="ai_resume_analyzer.php" class="ai-fcard">
            <span class="ai-fcard-tag tag-blue">Popular</span>
            <div class="ai-fcard-icon" style="background:rgba(26,86,219,.12);color:var(--ai-primary);"><i class="fas fa-file-lines"></i></div>
            <h5>AI Resume Analyzer</h5>
            <p>Score your profile across skills, education, experience and completeness. Get strengths, gaps and tailored suggestions.</p>
            <span class="ai-go">Analyze my resume <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- AI CV Builder -->
        <a href="ai_cv_generator.php" class="ai-fcard">
            <span class="ai-fcard-tag tag-green">New</span>
            <div class="ai-fcard-icon" style="background:rgba(16,185,129,.12);color:#10b981;"><i class="fas fa-wand-magic-sparkles"></i></div>
            <h5>Automated AI CV Generator</h5>
            <p>Generate a professional A4 CV with live templates, inline editing, and AI rewriting of your summaries and bullet points.</p>
            <span class="ai-go">Build my CV <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Cover Letter -->
        <a href="ai_cover_letter_generator.php" class="ai-fcard">
            <span class="ai-fcard-tag tag-pink">New</span>
            <div class="ai-fcard-icon" style="background:rgba(236,72,153,.12);color:#db2777;"><i class="fas fa-envelope-open-text"></i></div>
            <h5>AI Cover Letter Generator</h5>
            <p>Pick any open job and get a personalised, professional cover letter drafted from your real profile data in one click.</p>
            <span class="ai-go">Generate a letter <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Grooming Coach -->
        <a href="ai_grooming_coach.php" class="ai-fcard">
            <div class="ai-fcard-icon" style="background:rgba(5,150,105,.12);color:#059669;"><i class="fas fa-graduation-cap"></i></div>
            <h5>AI Grooming Coach</h5>
            <p>Personalised study plans for the grooming hub. Identify weak topics, build strengths, and find targeted learning videos.</p>
            <span class="ai-go">Get study plan <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Mock Interview -->
        <a href="ai_mock_interview.php" class="ai-fcard">
            <div class="ai-fcard-icon" style="background:rgba(217,119,6,.12);color:#d97706;"><i class="fas fa-clipboard-question"></i></div>
            <h5>AI Mock Interview</h5>
            <p>Answer real interview questions, get instant scoring, key phrase analysis, and constructive coaching feedback.</p>
            <span class="ai-go">Practice now <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Job Matching -->
        <a href="browse_jobs.php" class="ai-fcard">
            <div class="ai-fcard-icon" style="background:rgba(59,130,246,.12);color:#2563eb;"><i class="fas fa-magnifying-glass-chart"></i></div>
            <h5>AI Job Matching</h5>
            <p>Every active job on NovaHire shows a personalized match percentage computed from your skills, experience, and profile.</p>
            <span class="ai-go">Find best match <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Career Assistant -->
        <a href="ai_assistant.php" class="ai-fcard">
            <span class="ai-fcard-tag tag-cyan">Chat</span>
            <div class="ai-fcard-icon" style="background:rgba(14,165,233,.12);color:var(--ai-secondary);"><i class="fas fa-robot"></i></div>
            <h5>AI Career Assistant</h5>
            <p>Engage in full-page conversations with Nova about job applications, interview tactics, resume tips, and career strategies.</p>
            <span class="ai-go">Start chatting <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Skill Gap -->
        <a href="skill_gap.php" class="ai-fcard">
            <div class="ai-fcard-icon" style="background:rgba(14,165,233,.12);color:var(--ai-secondary);"><i class="fas fa-chart-simple"></i></div>
            <h5>Skill Gap Analyzer</h5>
            <p>Discover which high-demand skills you need to learn. Visualize your gaps and unlock tailored learning roadmaps.</p>
            <span class="ai-go">Analyze skills <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Career Path -->
        <a href="career_path.php" class="ai-fcard">
            <div class="ai-fcard-icon" style="background:rgba(249,115,22,.12);color:#f97316;"><i class="fas fa-signs-post"></i></div>
            <h5>Career Path Explorer</h5>
            <p>Visualize your career trajectory from entry-level to target roles with concrete milestones, skill requirements, and tracks.</p>
            <span class="ai-go">Explore paths <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Job Recommendations -->
        <a href="recommendations.php" class="ai-fcard">
            <div class="ai-fcard-icon" style="background:rgba(236,72,153,.12);color:#ec4899;"><i class="fas fa-wand-magic-sparkles"></i></div>
            <h5>Smart Job Matches</h5>
            <p>Curated job recommendations ranked specifically for your profile. Learn why each match fits and how to apply early.</p>
            <span class="ai-go">View matches <i class="fas fa-arrow-right"></i></span>
        </a>
    </div>

    <!-- How It Works -->
    <div class="ai-how">
        <h4><i class="fas fa-circle-info"></i> How NovaHire AI Works</h4>
        <div class="ai-how-grid">
            <div class="ai-how-item">
                <div class="ai-how-icon" style="background:rgba(26,86,219,.12);color:var(--ai-primary);"><i class="fas fa-puzzle-piece"></i></div>
                <div>
                    <strong>Rule-Based Intelligence</strong>
                    <p>Scoring, skill alignment, and coaching run on deterministic algorithms — always dependable and lightning fast.</p>
                </div>
            </div>
            <div class="ai-how-item">
                <div class="ai-how-icon" style="background:rgba(236,72,153,.12);color:#db2777;"><i class="fas fa-cloud-bolt"></i></div>
                <div>
                    <strong>LLM Enhancement</strong>
                    <p>Powered by advanced AI models for human-quality cover letters, deep conversational assistance, and contextual advice.</p>
                </div>
            </div>
            <div class="ai-how-item">
                <div class="ai-how-icon" style="background:rgba(5,150,105,.12);color:#059669;"><i class="fas fa-shield-heart"></i></div>
                <div>
                    <strong>Privacy & Security First</strong>
                    <p>Your resume data stays confidential. External LLM services are only invoked when generating enriched outputs.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scroll to Top -->
<button class="ai-scroll-top" id="aiScrollTop" aria-label="Scroll to top"><i class="fas fa-arrow-up"></i></button>

<script src="<?php echo BASE_URL; ?>/ai/assets/js/chat.js"></script>
<?php ai_chat_widget(); ?>
<script>
if (typeof aiChatInit === 'function') aiChatInit();

/* Scroll to Top */
(function(){
    var btn = document.getElementById('aiScrollTop');
    if (!btn) return;
    window.addEventListener('scroll', function(){
        window.scrollY > 400 ? btn.classList.add('show') : btn.classList.remove('show');
    });
    btn.addEventListener('click', function(){ window.scrollTo({top: 0, behavior: 'smooth'}); });
})();

/* Scroll Animations */
(function(){
    var els = document.querySelectorAll('.ai-fcard, .ai-stat, .ai-how-item');
    if (!els.length) return;
    var obs = new IntersectionObserver(function(entries){
        entries.forEach(function(e){
            if (e.isIntersecting){
                var idx = Array.from(els).indexOf(e.target);
                setTimeout(function(){ e.target.classList.add('visible'); }, idx * 50);
                obs.unobserve(e.target);
            }
        });
    }, {threshold: 0.1});
    els.forEach(function(el){ obs.observe(el); });
})();
</script>
</body>
</html>
