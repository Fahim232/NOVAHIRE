<?php
/**
 * NovaHire AI - UI Helpers
 * Reusable HTML snippets (CSS include, hero, score ring, gauge bars)
 * that work from both root pages and admin/ pages.
 */

if (defined('AI_HELPERS_LOADED')) return;
define('AI_HELPERS_LOADED', true);

require_once __DIR__ . '/config.php';

/**
 * Relative path prefix depending on current page location.
 */
function ai_base_url() {
    static $base = null;
    if ($base === null) {
        if (defined('BASE_URL')) {
            $base = BASE_URL . '/';
        } else {
            $script = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
            $subdir = (strpos($script, '/admin/') !== false || strpos($script, '/company/') !== false
                    || strpos($script, '/seeker/') !== false || strpos($script, '/auth/') !== false);
            $base = $subdir ? '../' : '';
        }
    }
    return $base;
}

function ai_css_link() {
    return '<link rel="stylesheet" href="' . ai_base_url() . 'ai/assets/css/ai.css?v=' . time() . '">';
}

/**
 * Hero banner for AI pages.
 */
function ai_page_header($title, $subtitle = '', $icon = 'fa-robot') {
    $provider = ai_provider_label();
    $chip = $provider !== 'NovaHire AI Engine' ? 'Powered by ' . $provider : 'Hybrid AI Engine - always online';
    $script = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
    $isAdmin = strpos($script, '/admin/') !== false;
    $backUrl = $isAdmin ? ai_base_url() . 'admin/dashboard.php' : ai_base_url() . 'seeker/ai_hub.php';
    $backLabel = $isAdmin ? 'Admin Dashboard' : 'AI Career Center';

    if (!$isAdmin) {
        echo '<div class="ai-breadcrumb-strip" style="background:var(--bg-card);border-bottom:1px solid var(--border);padding:10px 0;transition:all 0.2s;">';
        echo '<div class="container d-flex align-items-center justify-content-between">';
        echo '<div class="d-flex align-items-center" style="gap:8px;font-size:0.86rem;">';
        echo '<a href="' . $backUrl . '" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--primary);font-weight:700;"><i class="fas fa-arrow-left"></i> ' . $backLabel . '</a>';
        echo '<span style="color:var(--border);"><i class="fas fa-chevron-right" style="font-size:0.7rem;"></i></span>';
        echo '<span style="font-weight:700;color:var(--text);">' . htmlspecialchars($title) . '</span>';
        echo '</div>';
        echo '<div class="d-none d-md-flex align-items-center" style="gap:8px;font-size:0.78rem;">';
        echo '<span class="badge badge-pill px-3 py-1" style="background:var(--bg-hover);color:var(--text-muted);border:1px solid var(--border);font-weight:600;"><i class="fas fa-sparkles mr-1" style="color:var(--primary);"></i>' . $chip . '</span>';
        echo '</div>';
        echo '</div></div>';
    } else {
        echo '<nav style="position:sticky;top:0;z-index:1030;background:#0f172a;border-bottom:1px solid rgba(255,255,255,.06);padding:0 20px">';
        echo '<div style="max-width:1080px;margin:0 auto;display:flex;align-items:center;height:52px;gap:12px">';
        echo '<a href="' . $backUrl . '" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:#e2e8f0;font-weight:600;font-size:.85rem;opacity:.85"><i class="fas fa-arrow-left"></i> ' . $backLabel . '</a>';
        echo '<span style="color:#475569;font-size:.7rem"><i class="fas fa-chevron-right"></i></span>';
        echo '<span style="font-weight:700;color:#fff;font-size:.85rem">' . htmlspecialchars($title) . '</span>';
        echo '</div></nav>';
    }

    echo '<div class="ai-hero"><div class="container text-center">';
    echo '<span class="badge-ai"><i class="fas fa-' . $icon . ' mr-2"></i>' . $chip . '</span>';
    echo '<h1 class="mt-3 mb-2">' . htmlspecialchars($title) . '</h1>';
    if ($subtitle !== '') echo '<p class="mb-0">' . htmlspecialchars($subtitle) . '</p>';
    echo '</div></div>';
}

/**
 * SVG circular gauge.
 */
function ai_score_ring($score, $label = '', $size = 130) {
    $score = max(0, min(100, intval($score)));
    $r = ($size / 2) - 10;
    $circ = 2 * 3.14159 * $r;
    $offset = $circ - ($score / 100) * $circ;
    $color = ai_readiness_label($score)[1];
    echo '<div class="ai-score-ring" style="width:' . $size . 'px;height:' . $size . 'px;">';
    echo '<svg viewBox="0 0 ' . $size . ' ' . $size . '" style="width:' . $size . 'px;height:' . $size . 'px;">';
    echo '<circle class="ring-bg" cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $r . '" style="stroke:var(--ai-border, #e2e8f0);"/>';
    echo '<circle class="ring-fill" cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $r . '" style="stroke:' . $color . ';" stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $offset . '"/>';
    echo '</svg>';
    echo '<div class="ai-score-center"><span class="val">' . $score . '%</span>';
    if ($label !== '') echo '<span class="lbl">' . htmlspecialchars($label) . '</span>';
    echo '</div></div>';
}

/**
 * Horizontal gauge bar.
 */
function ai_gauge_bar($label, $score, $note = '') {
    $score = max(0, min(100, intval($score)));
    $color = ai_readiness_label($score)[1];
    echo '<div class="ai-dim">';
    echo '<div class="ai-dim-icon"><i class="fas fa-chart-line"></i></div>';
    echo '<div class="ai-dim-info">';
    echo '<div class="d-flex justify-content-between"><span class="t">' . htmlspecialchars($label) . '</span><span class="font-weight-bold" style="color:' . $color . ';">' . $score . '%</span></div>';
    echo '<div class="ai-bar mt-1"><div style="width:' . $score . '%; background:' . $color . ';"></div></div>';
    if ($note !== '') echo '<div class="n mt-1">' . $note . '</div>';
    echo '</div></div>';
}

/**
 * Floating chat widget markup (include near end of body).
 */
function ai_chat_widget() {
    $name = htmlspecialchars(AI_CHATBOT_NAME);
    echo '<div class="ai-chat-widget">';
    echo '<div class="ai-chat-panel" id="aiChatPanel">';
    echo '<div class="ai-chat-head">';
    echo '<div class="ai-chat-avatar"><i class="fas fa-robot"></i></div>';
    echo '<div><div class="nm">' . $name . '</div>';
    echo '<div class="st"><span class="dot-on"></span>Online now</div></div>';
    echo '<button class="ai-chat-close" onclick="aiChatClose()" aria-label="Close chat"><i class="fas fa-times"></i></button>';
    echo '</div>';
    echo '<div class="ai-chat-body" id="aiChatBody">';
    echo '<div class="ai-chat-welcome">';
    echo '<div class="ai-chat-welcome-icon"><i class="fas fa-robot"></i></div>';
    echo '<h4>Hi, I\'m ' . $name . '</h4>';
    echo '<p>Your AI career assistant. Ask me about jobs, resumes, interviews, or career advice.</p>';
    echo '</div>';
    echo '<div class="ai-msg bot">How can I help you today?';
    echo '<div class="ai-quick">';
    echo '<button onclick="aiChatSet(\'Find jobs for me\')">Find jobs</button>';
    echo '<button onclick="aiChatSet(\'Analyze my resume\')">Resume check</button>';
    echo '<button onclick="aiChatSet(\'Start mock interview\')">Mock interview</button>';
    echo '</div></div>';
    echo '</div>';
    echo '<div class="ai-chat-foot">';
    echo '<input type="text" id="aiChatInput" placeholder="Type a message..." autocomplete="off" onkeydown="if(event.key===\'Enter\')aiChatSend()">';
    echo '<button onclick="aiChatSend()" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>';
    echo '</div></div>';
    echo '<button class="ai-chat-toggle" id="aiChatToggle" onclick="aiChatToggle()" aria-label="Open AI chat">';
    echo '<i class="fas fa-robot"></i><span class="ai-chat-ping"></span></button>';
    echo '</div>';
}
