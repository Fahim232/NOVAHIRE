<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_staff_login();

$current_page = basename($_SERVER['PHP_SELF']);
$staff_name = $_SESSION['staff_name'] ?? 'Staff';
$company_name = $_SESSION['company_name'] ?? 'Company';
$staff_id = $_SESSION['staff_id'] ?? 0;
$company_id = $_SESSION['company_id'] ?? 0;

$staff_initial = mb_strtoupper(mb_substr(trim($staff_name), 0, 1));
?>

<nav class="cmp-nav" id="cmpNav">
    <div class="cmp-nav-inner">
        <!-- Brand -->
        <a class="cmp-brand" href="index.php">
            <span class="cmp-brand-tile">
                <span><?php echo htmlspecialchars($staff_initial); ?></span>
            </span>
            <span class="cmp-brand-txt">
                <span class="cmp-brand-name"><?php echo htmlspecialchars($company_name); ?></span>
                <span class="cmp-brand-sub">Staff Portal</span>
            </span>
        </a>

        <!-- Toggler (mobile) -->
        <button class="cmp-toggler" type="button" id="cmpToggler"
                aria-controls="cmpNavMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="cmp-tg-bar"></span>
            <span class="cmp-tg-bar"></span>
            <span class="cmp-tg-bar"></span>
        </button>

        <!-- Menu -->
        <div class="cmp-menu" id="cmpNavMenu">
            <ul class="cmp-links">
                <li class="cmp-item <?php echo $current_page == 'index.php' ? 'is-active' : ''; ?>">
                    <a class="cmp-link" href="index.php"><i class="fas fa-grip"></i><span>Dashboard</span></a>
                </li>

                <li class="cmp-item <?php echo $current_page == 'interviews.php' ? 'is-active' : ''; ?>">
                    <a class="cmp-link" href="interviews.php"><i class="fas fa-calendar-check"></i><span>Interviews</span></a>
                </li>
            </ul>

            <div class="cmp-actions">
                <button class="cmp-ghost cmp-theme" type="button" title="Toggle theme" aria-label="Toggle theme">
                    <i class="fas fa-moon" id="cmpThemeIcon"></i>
                </button>

                <!-- User menu -->
                <div class="cmp-user cmp-drop">
                    <a class="cmp-user-btn" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="cmp-avatar">
                            <span><?php echo htmlspecialchars($staff_initial); ?></span>
                        </span>
                        <span class="cmp-user-txt"><?php echo htmlspecialchars($staff_name); ?></span>
                        <i class="fas fa-chevron-down cmp-caret"></i>
                    </a>
                    <div class="cmp-dropdown cmp-user-drop">
                        <div class="cmp-user-head">
                            <span class="cmp-avatar"><?php echo htmlspecialchars($staff_initial); ?></span>
                            <span class="cmp-user-txt">
                                <strong><?php echo htmlspecialchars($staff_name); ?></strong>
                                <small>Interviewer / Staff</small>
                            </span>
                        </div>
                        <div class="cmp-drop-sep"></div>
                        <a class="cmp-drop-item cmp-danger" href="logout.php">
                            <span class="cmp-drop-ico"><i class="fas fa-sign-out-alt"></i></span>
                            <span class="cmp-drop-txt">Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<style>
/* ═══ NovaHire — Company Nav (minimal, refined) ═══ */
    .cmp-nav {
        --bg: #ffffff;
        --bg-card: #ffffff;
        --bg-hover: #f1f5f9;
        --border-light: #e2e8f0;
        --text: #1e293b;
        --text-muted: #64748b;
        --primary: #1a56db;
        --danger: #dc2626;
    }
    [data-theme="dark"] .cmp-nav {
        --bg: #0f172a;
        --bg-card: #1e293b;
        --bg-hover: #334155;
        --border-light: #334155;
        --text: #f1f5f9;
        --text-muted: #94a3b8;
        --primary: #60a5fa;
        --danger: #f87171;
    }
    .cmp-nav {
        position: sticky;
        top: 0;
        z-index: 1030;
        background: var(--bg);
        border-bottom: 1px solid var(--border-light);
        transition: box-shadow .35s ease, background .35s ease;
    }
    .cmp-nav.is-scrolled {
        box-shadow: 0 10px 30px -18px rgba(15, 23, 42, .35);
    }
    [data-theme="dark"] .cmp-nav {
        background: var(--bg);
        border-bottom-color: rgba(51, 65, 85, .5);
    }

    .cmp-nav-inner {
        display: flex;
        align-items: center;
        gap: 18px;
        height: 66px;
        padding: 0 26px;
        max-width: 1440px;
        margin: 0 auto;
    }

    /* ── Brand ── */
    .cmp-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
        text-decoration: none !important;
    }
    .cmp-brand-tile {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(140deg, #3b82f6, #06b6d4);
        color: #fff;
        font-family: 'Sora', sans-serif;
        font-weight: 700;
        font-size: 1rem;
        overflow: hidden;
        box-shadow: 0 8px 18px -8px rgba(99, 102, 241, .55);
        transition: transform .3s ease, box-shadow .3s ease;
    }
    .cmp-brand:hover .cmp-brand-tile { transform: translateY(-1px) scale(1.03); }
    .cmp-brand-txt { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
    .cmp-brand-name {
        font-family: 'Sora', 'Manrope', sans-serif;
        font-weight: 700;
        font-size: .95rem;
        letter-spacing: -.01em;
        color: var(--text);
        white-space: nowrap;
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .cmp-brand-sub {
        font-size: .6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .12em;
        color: var(--text-muted);
    }

    /* ── Layout split ── */
    .cmp-menu { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
    .cmp-links { display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0; }
    .cmp-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-left: auto;
        padding-left: 12px;
        border-left: 1px solid var(--border-light);
    }

    /* ── Nav links ── */
    .cmp-link {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 10px;
        color: var(--text) !important;
        font-size: .85rem;
        font-weight: 600;
        text-decoration: none !important;
        position: relative;
        transition: background .25s ease, color .25s ease;
    }
    .cmp-link i { font-size: .9rem; width: 16px; text-align: center; color: var(--text-muted); transition: color .25s ease, transform .25s ease; }
    .cmp-link:hover { background: var(--bg-hover); color: var(--primary) !important; }
    .cmp-link:hover i { color: var(--primary); transform: scale(1.08); }
    
    .cmp-item.is-active > .cmp-link {
        background: rgba(99, 102, 241, .1);
        color: var(--primary) !important;
    }
    .cmp-item.is-active > .cmp-link i { color: var(--primary); }
    .cmp-item.is-active > .cmp-link::after {
        content: '';
        position: absolute;
        left: 14px;
        right: 14px;
        bottom: 2px;
        height: 2px;
        border-radius: 2px;
        background: var(--primary);
        opacity: .8;
    }

    /* ── Dropdowns ── */
    .cmp-drop { position: relative; }
    .cmp-dropdown {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        min-width: 250px;
        padding: 8px;
        background: var(--bg-card);
        border: 1px solid var(--border-light);
        border-radius: 14px;
        box-shadow: 0 20px 45px -14px rgba(15, 23, 42, .3);
        opacity: 0;
        visibility: hidden;
        transform: translateY(8px);
        transition: opacity .22s ease, transform .22s ease, visibility .22s;
        z-index: 1050;
    }
    .cmp-drop:hover .cmp-dropdown,
    .cmp-drop.show .cmp-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }

    .cmp-drop-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 9px 12px;
        border-radius: 10px;
        color: var(--text) !important;
        font-size: .85rem;
        font-weight: 600;
        text-decoration: none !important;
        transition: background .2s ease, color .2s ease;
    }
    .cmp-drop-item:hover { background: var(--bg-hover); color: var(--primary) !important; }
    .cmp-drop-item.is-active { background: rgba(99, 102, 241, .1); color: var(--primary) !important; }
    .cmp-drop-ico {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-hover);
        color: var(--primary);
        font-size: .82rem;
        flex-shrink: 0;
        transition: background .2s ease, color .2s ease;
    }
    .cmp-drop-item:hover .cmp-drop-ico { background: rgba(99, 102, 241, .14); }
    .cmp-drop-txt { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
    .cmp-drop-txt small { font-weight: 500; font-size: .7rem; color: var(--text-muted); }
    .cmp-drop-sep { height: 1px; margin: 6px 4px; background: var(--border-light); }
    .cmp-danger { color: var(--danger) !important; }
    .cmp-danger .cmp-drop-ico { color: var(--danger); background: rgba(239, 68, 68, .1); }
    .cmp-danger:hover { color: var(--danger) !important; background: rgba(239, 68, 68, .08); }

    /* ── Ghost icon buttons ── */
    .cmp-ghost {
        position: relative;
        width: 38px;
        height: 38px;
        border: none;
        border-radius: 11px;
        background: var(--bg-hover);
        color: var(--text);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .88rem;
        text-decoration: none !important;
        cursor: pointer;
        transition: background .25s ease, color .25s ease, transform .25s ease;
    }
    .cmp-ghost:hover { background: rgba(99, 102, 241, .12); color: var(--primary); transform: translateY(-1px); }
    .cmp-ghost i { transition: transform .35s ease; }
    .cmp-ghost:hover i { transform: scale(1.08) rotate(-4deg); }
    .cmp-theme:hover i { transform: rotate(25deg) scale(1.05); }

    /* ── User menu ── */
    .cmp-user { margin-left: 2px; }
    .cmp-user-btn {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 5px 10px 5px 5px;
        border-radius: 999px;
        border: 1px solid var(--border-light);
        background: transparent;
        text-decoration: none !important;
        transition: border-color .25s ease, background .25s ease;
    }
    .cmp-user-btn:hover { background: var(--bg-hover); border-color: rgba(99, 102, 241, .35); }
    .cmp-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(140deg, #3b82f6, #06b6d4);
        color: #fff;
        font-family: 'Sora', sans-serif;
        font-weight: 700;
        font-size: .82rem;
        overflow: hidden;
        flex-shrink: 0;
    }
    .cmp-user-txt {
        font-size: .8rem;
        font-weight: 700;
        color: var(--text);
        white-space: nowrap;
        max-width: 130px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .cmp-user-btn .cmp-caret { font-size: .58rem; }
    .cmp-user-drop { right: 0; left: auto; min-width: 240px; }
    .cmp-user-head {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 10px 12px;
    }
    .cmp-user-head .cmp-user-txt { display: flex; flex-direction: column; line-height: 1.2; max-width: 160px; }
    .cmp-user-head strong { font-size: .82rem; font-weight: 700; color: var(--text); }
    .cmp-user-head small { font-size: .68rem; font-weight: 600; color: var(--text-muted); }

    /* ── Mobile toggler ── */
    .cmp-toggler {
        display: flex;
        width: 40px;
        height: 40px;
        border-radius: 11px;
        background: var(--bg-hover);
        border: 1px solid var(--border-light);
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        cursor: pointer;
        margin-left: auto;
        order: 2;
    }    
    .cmp-tg-bar {
        width: 17px;
        height: 2px;
        border-radius: 2px;
        background: var(--text);
        transition: transform .3s ease, opacity .3s ease, background .3s ease;
    }
    .cmp-toggler[aria-expanded="true"] .cmp-tg-bar:nth-child(1) { transform: translateY(6px) rotate(45deg); background: var(--primary); }
    .cmp-toggler[aria-expanded="true"] .cmp-tg-bar:nth-child(2) { opacity: 0; }
    .cmp-toggler[aria-expanded="true"] .cmp-tg-bar:nth-child(3) { transform: translateY(-6px) rotate(-45deg); background: var(--primary); }

    /* ── Responsive ── */
    @media (max-width: 991.98px) {
        .cmp-nav-inner { height: auto; min-height: 66px; padding: 10px 16px; flex-wrap: wrap; }
        .cmp-toggler { display: flex; }
        .cmp-menu {
            display: none;
            width: 100%;
            flex-direction: column;
            align-items: stretch;
            gap: 4px;
            padding: 14px;
            margin-top: 8px;
            border-radius: 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-light);
        }
        .cmp-menu.show { display: flex; }
        .cmp-links { flex-direction: column; align-items: stretch; width: 100%; }
        .cmp-item.is-active > .cmp-link::after { display: none; }
        .cmp-link { padding: 11px 14px; }
        .cmp-actions {
            margin-left: 0;
            padding-left: 0;
            border-left: none;
            flex-wrap: wrap;
            width: 100%;
            padding-top: 10px;
            margin-top: 4px;
            border-top: 1px solid var(--border-light);
        }
        .cmp-user { margin-left: 0; }
        .cmp-user-btn { width: 100%; }
        .cmp-dropdown, .cmp-user-drop {
            position: static;
            opacity: 1;
            visibility: visible;
            transform: none;
            box-shadow: none;
            border: none;
            background: transparent;
            padding: 4px 0 4px 12px;
            min-width: 0;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile Toggler
    const toggler = document.getElementById('cmpToggler');
    const menu = document.getElementById('cmpNavMenu');
    if (toggler && menu) {
        toggler.addEventListener('click', function(e) {
            e.stopPropagation();
            const isExp = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !isExp);
            menu.classList.toggle('show');
        });
    }

    // Dropdowns
    const drops = document.querySelectorAll('.cmp-drop');
    drops.forEach(drop => {
        const btn = drop.querySelector('[data-toggle="dropdown"]');
        if (btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                drops.forEach(d => { if (d !== drop) d.classList.remove('show'); });
                drop.classList.toggle('show');
            });
        }
    });

    document.addEventListener('click', function(e) {
        drops.forEach(d => {
            if (!d.contains(e.target)) {
                d.classList.remove('show');
            }
        });
        if (menu && toggler && !menu.contains(e.target) && !toggler.contains(e.target)) {
            menu.classList.remove('show');
            toggler.setAttribute('aria-expanded', 'false');
        }
    });

    // Theme toggle
    const themeBtn = document.querySelector('.cmp-theme');
    const themeIcon = document.getElementById('cmpThemeIcon');
    if (themeBtn && themeIcon) {
        const currentTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', currentTheme);
        themeIcon.className = currentTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

        themeBtn.addEventListener('click', () => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const newTheme = isDark ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            themeIcon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        });
    }

    // Nav scroll shadow
    const nav = document.getElementById('cmpNav');
    if (nav) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 10) {
                nav.classList.add('is-scrolled');
            } else {
                nav.classList.remove('is-scrolled');
            }
        }, { passive: true });
    }
});
</script>
