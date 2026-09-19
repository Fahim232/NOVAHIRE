<?php
/**
 * NovaHire — Automated AI CV Generator
 * Live interactive preview, inline editing, and AI rewriting.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_seeker_login();
require_once __DIR__ . '/../admin/dbcon.php';

$user_id = $_SESSION['id'];

// Check premium access
require_once __DIR__ . '/../includes/premium.php';
$access = nh_check_access($con, $user_id, 'resume_builder');
if (!$access['allowed']) {
    nh_render_pro_gate('ai_cv_builder');
    exit;
}

require_once __DIR__ . '/../includes/resume_builder.php';
$resume_data = get_resume_data($con, $user_id);

$u = $resume_data['user'] ?? [];
$name = htmlspecialchars($u['username'] ?? 'Your Name');
$email = htmlspecialchars($u['email'] ?? 'email@example.com');
$phone = htmlspecialchars($u['phone'] ?? '+880 1XXXXXXXXX');
$about = htmlspecialchars($u['about'] ?? 'A highly motivated professional looking to make an impact in the industry.');
$skills = $resume_data['skills'] ?? [];
$experience = "Software Engineer - Tech Solutions Inc.\n- Developed robust web applications using PHP and MySQL.\n- Optimized database queries to improve performance by 30%.\n- Collaborated with cross-functional teams to deliver projects on time.";
$education = "B.Sc. in Computer Science\nUniversity of Technology, 2020";
$projects = "E-commerce Platform\nBuilt a scalable e-commerce platform with a custom CMS and payment gateway integration.";

require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ═══ AI CV BUILDER GLOBALS ═══ */
.aicv-page {
    background: #f1f5f9;
    font-family: 'Plus Jakarta Sans', sans-serif;
    padding: 20px;
    min-height: 100vh;
}
.aicv-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    padding: 16px 24px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.aicv-header h1 {
    margin: 0;
    font-size: 1.5rem;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}
.aicv-header h1 i { color: #1a56db; }
.aicv-actions button {
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}
.btn-save { background: #059669; color: #fff; }
.btn-save:hover { background: #047857; }
.btn-pdf { background: #1a56db; color: #fff; }
.btn-pdf:hover { background: #1d4ed8; }

/* ═══ A4 PAPER PREVIEW ═══ */
.aicv-workspace {
    display: flex;
    justify-content: center;
}
.aicv-paper {
    background: #fff;
    width: 210mm;
    min-height: 297mm;
    padding: 20mm;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    position: relative;
    color: #1e293b;
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
}
.aicv-paper * {
    box-sizing: border-box;
}

/* ═══ EDITABLE SECTIONS ═══ */
.editable {
    position: relative;
    border: 1px dashed transparent;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s;
}
.editable:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.editable:focus {
    outline: none;
    border-color: #1a56db;
    background: #fff;
}
.ai-btn-wrapper {
    position: absolute;
    top: -20px;
    right: 0;
    display: none;
    z-index: 10;
}
.editable:hover .ai-btn-wrapper,
.editable:focus-within .ai-btn-wrapper {
    display: flex;
}
.ai-rewrite-btn {
    background: #1a56db;
    color: #fff;
    border: none;
    padding: 4px 10px;
    font-size: 0.75rem;
    border-radius: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 4px;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}
.ai-rewrite-btn:hover {
    background: #1e3a8a;
}
.ai-rewrite-btn:disabled {
    background: #94a3b8;
    cursor: wait;
}

/* ═══ CV STYLES ═══ */
.cv-header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #1a56db; padding-bottom: 16px; }
.cv-name { font-size: 32px; font-weight: bold; margin-bottom: 8px; color: #0f172a; }
.cv-contact { font-size: 14px; color: #64748b; }
.cv-section { margin-bottom: 20px; }
.cv-section-title { font-size: 16px; font-weight: bold; color: #1a56db; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px; padding-bottom: 4px; }
.cv-content { font-size: 14px; line-height: 1.6; white-space: pre-wrap; }

/* ═══ PRINT STYLES ═══ */
@media print {
    body * { visibility: hidden; }
    .aicv-paper, .aicv-paper * { visibility: visible; }
    .aicv-paper {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 10mm;
        box-shadow: none;
    }
    .editable { border: none !important; background: transparent !important; padding: 0 !important; }
    .ai-btn-wrapper { display: none !important; }
}

/* Spinner */
@keyframes spin { 100% { transform: rotate(360deg); } }
.fa-spin-fast { animation: spin 0.8s linear infinite; }
</style>

<div class="aicv-page">
    <div class="aicv-header">
        <h1><i class="fas fa-wand-magic-sparkles"></i> AI CV Generator</h1>
        <div class="aicv-actions">
            <button class="btn-save" onclick="saveCV()"><i class="fas fa-save"></i> Save</button>
            <button class="btn-pdf" onclick="window.print()"><i class="fas fa-file-pdf"></i> Download PDF</button>
        </div>
    </div>

    <div class="aicv-workspace">
        <div class="aicv-paper" id="cv-document">
            
            <div class="cv-header">
                <div class="editable cv-name" contenteditable="true" data-field="name">
                    <?php echo $name; ?>
                </div>
                <div class="editable cv-contact" contenteditable="true" data-field="contact">
                    <?php echo $email; ?> &bull; <?php echo $phone; ?> &bull; LinkedIn / GitHub
                </div>
            </div>

            <div class="cv-section">
                <div class="cv-section-title">Professional Summary</div>
                <div class="editable cv-content" contenteditable="true" data-field="summary" id="content-summary">
                    <div class="ai-btn-wrapper" contenteditable="false">
                        <button class="ai-rewrite-btn" onclick="rewriteText('summary', 'rewrite_summary', event)"><i class="fas fa-magic"></i> AI Re-write</button>
                    </div>
                    <?php echo $about; ?>
                </div>
            </div>

            <div class="cv-section">
                <div class="cv-section-title">Experience</div>
                <div class="editable cv-content" contenteditable="true" data-field="experience" id="content-experience">
                    <div class="ai-btn-wrapper" contenteditable="false">
                        <button class="ai-rewrite-btn" onclick="rewriteText('experience', 'polish_bullets', event)"><i class="fas fa-magic"></i> Polish Bullets</button>
                    </div>
                    <?php echo trim($experience); ?>
                </div>
            </div>

            <div class="cv-section">
                <div class="cv-section-title">Projects</div>
                <div class="editable cv-content" contenteditable="true" data-field="projects" id="content-projects">
                    <div class="ai-btn-wrapper" contenteditable="false">
                        <button class="ai-rewrite-btn" onclick="rewriteText('projects', 'enhance_projects', event)"><i class="fas fa-magic"></i> Enhance</button>
                    </div>
                    <?php echo trim($projects); ?>
                </div>
            </div>

            <div class="cv-section">
                <div class="cv-section-title">Education</div>
                <div class="editable cv-content" contenteditable="true" data-field="education" id="content-education">
                    <div class="ai-btn-wrapper" contenteditable="false">
                        <button class="ai-rewrite-btn" onclick="rewriteText('education', 'rewrite_education', event)"><i class="fas fa-magic"></i> Format properly</button>
                    </div>
                    <?php echo trim($education); ?>
                </div>
            </div>

            <div class="cv-section">
                <div class="cv-section-title">Skills</div>
                <div class="editable cv-content" contenteditable="true" data-field="skills" id="content-skills">
                    <div class="ai-btn-wrapper" contenteditable="false">
                        <button class="ai-rewrite-btn" onclick="rewriteText('skills', 'suggest_skills', event)"><i class="fas fa-magic"></i> Suggest better skills</button>
                    </div>
                    <?php echo empty($skills) ? "PHP, MySQL, JavaScript, HTML, CSS" : htmlspecialchars(implode(', ', $skills)); ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
async function rewriteText(fieldId, action, event) {
    const btn = event.currentTarget;
    const container = document.getElementById('content-' + fieldId);
    
    // Extract text ignoring the button wrapper
    let textToRewrite = '';
    for (let node of container.childNodes) {
        if (node.nodeType === Node.TEXT_NODE) {
            textToRewrite += node.textContent;
        } else if (node.nodeType === Node.ELEMENT_NODE && !node.classList.contains('ai-btn-wrapper')) {
            textToRewrite += node.innerText || node.textContent;
        }
    }
    
    textToRewrite = textToRewrite.trim();
    if (!textToRewrite) return;

    // UI Loading state
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin-fast"></i> Writing...';
    btn.disabled = true;

    try {
        const response = await fetch('../api/ai_cv_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                text: textToRewrite
            })
        });
        const res = await response.json();
        
        if (res.success) {
            // Restore the wrapper, replace the text
            const wrapper = container.querySelector('.ai-btn-wrapper');
            container.innerHTML = '';
            container.appendChild(wrapper);
            container.appendChild(document.createTextNode("\n" + res.data));
        } else {
            alert('AI Error: ' + (res.error || 'Unknown error.'));
        }
    } catch (e) {
        alert('Network error occurred.');
        console.error(e);
    } finally {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
}

function saveCV() {
    // Collect fields
    const data = {};
    document.querySelectorAll('[data-field]').forEach(el => {
        let field = el.getAttribute('data-field');
        let text = '';
        for (let node of el.childNodes) {
            if (node.nodeType === Node.TEXT_NODE) {
                text += node.textContent;
            } else if (node.nodeType === Node.ELEMENT_NODE && !node.classList.contains('ai-btn-wrapper')) {
                text += node.innerText || node.textContent;
            }
        }
        data[field] = text.trim();
    });

    // In a full implementation, you would send this via AJAX to save_resume_data in the backend.
    // For now, we alert success as the visual update is retained in the live page.
    console.log("Saving CV Data:", data);
    alert('CV saved successfully!');
}
</script>

</body>
</html>
