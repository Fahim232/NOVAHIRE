<style>
.sf{position:relative;background:#0c1222;color:#94a3b8;padding:56px 0 0;margin-top:60px;overflow:hidden;}
.sf::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--primary),var(--secondary),var(--primary));}
.sf::after{content:'';position:absolute;width:520px;height:520px;border-radius:50%;background:radial-gradient(circle,rgba(26,86,219,.12),transparent 70%);top:-220px;right:-160px;pointer-events:none;}
.sf-inner{max-width:1200px;margin:0 auto;padding:0 24px;position:relative;z-index:1;}
.sf-brand{display:flex;align-items:center;gap:11px;font-family:'Plus Jakarta Sans',sans-serif;font-size:1.35rem;font-weight:800;color:#fff;margin-bottom:14px;letter-spacing:-.02em;}
.sf-brand-icon{width:38px;height:38px;border-radius:12px;flex-shrink:0;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;box-shadow:0 6px 18px -6px rgba(26,86,219,.65);}
.sf-brand span{background:linear-gradient(135deg,#3b82f6,#38bdf8);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;}
.sf-desc{color:#94a3b8;font-size:.85rem;line-height:1.75;max-width:300px;margin-bottom:18px;}
.sf-heading{font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;font-weight:800;color:#cbd5e1;margin-bottom:18px;position:relative;padding-bottom:10px;}
.sf-heading::after{content:'';position:absolute;bottom:0;left:0;width:28px;height:2px;border-radius:2px;background:linear-gradient(90deg,var(--primary),var(--secondary));}
.sf-link{display:block;color:#94a3b8;font-size:.85rem;padding:5px 0;text-decoration:none;transition:color .2s;}
.sf-link:hover{color:#fff;}
.sf-link i{font-size:.6rem;margin-left:6px;opacity:0;transition:opacity .2s;}
.sf-link:hover i{opacity:1;}
.sf-bottom{margin-top:40px;padding:20px 0;border-top:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
.sf-bottom p{color:#64748b;font-size:.83rem;margin:0;}
.sf-bottom .sf-made i{color:#f43f5e;margin:0 4px;}
@media(max-width:767px){.sf-bottom{justify-content:center;text-align:center;}}
</style>

<footer class="sf">
    <div class="sf-inner">
        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="sf-brand">
                    <div class="sf-brand-icon"><i class="fas fa-layer-group"></i></div>
                    <span>Nova<span>Hire</span></span>
                </div>
                <p class="sf-desc">Your gateway to career success. Connect with top employers, sharpen your skills, and land your dream job.</p>
            </div>
            <div class="col-lg-2 col-md-4 mb-4">
                <div class="sf-heading">For Job Seekers</div>
                <a href="<?php echo BASE_URL; ?>/seeker/browse_jobs.php" class="sf-link">Browse Jobs <i class="fas fa-arrow-right"></i></a>
                <a href="<?php echo BASE_URL; ?>/seeker/available_companies.php" class="sf-link">Companies <i class="fas fa-arrow-right"></i></a>
                <a href="<?php echo BASE_URL; ?>/seeker/profile.php" class="sf-link">My Profile <i class="fas fa-arrow-right"></i></a>
                <a href="<?php echo BASE_URL; ?>/seeker/my_application.php" class="sf-link">Applications <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="col-lg-2 col-md-4 mb-4">
                <div class="sf-heading">Resources</div>
                <a href="<?php echo BASE_URL; ?>/seeker/browse_jobs.php" class="sf-link">Browse Jobs <i class="fas fa-arrow-right"></i></a>
                <a href="<?php echo BASE_URL; ?>/seeker/resume_builder.php" class="sf-link">Resume Builder <i class="fas fa-arrow-right"></i></a>
                <a href="<?php echo BASE_URL; ?>/seeker/mentors.php" class="sf-link">Find a Mentor <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="col-lg-2 col-md-4 mb-4">
                <div class="sf-heading">Platform</div>
                <a href="<?php echo BASE_URL; ?>/seeker/browse_jobs.php" class="sf-link">Browse Jobs <i class="fas fa-arrow-right"></i></a>
                <a href="<?php echo BASE_URL; ?>/seeker/ai_hub.php" class="sf-link">AI Center <i class="fas fa-arrow-right"></i></a>
                <a href="<?php echo BASE_URL; ?>/seeker/pro.php" class="sf-link">Upgrade to Pro <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
        <div class="sf-bottom">
            <p>&copy; <?php echo date('Y'); ?> NovaHire. All rights reserved.</p>
            <p class="sf-made">Crafted with <i class="fas fa-heart"></i> for career growth</p>
        </div>
    </div>
</footer>
</body>
</html>
