# NovaHire

**AI-Powered Job Portal & Career Grooming Platform**

A full-stack recruitment ecosystem connecting job seekers, companies, and mentors — powered by a hybrid AI engine that works both online and offline.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql)
![Bootstrap](https://img.shields.io/badge/Bootstrap-4-7952B3?style=flat-square&logo=bootstrap)

---

## Quick Start

```bash
# 1. Clone & copy to htdocs
git clone https://github.com/Fahim232/NOVAHIRE.git
cp -r NOVAHIRE /Applications/XAMPP/xamppfiles/htdocs/

# 2. Create database
mysql -u root -e "CREATE DATABASE projects CHARACTER SET utf8mb4"

# 3. Import schemas (in order)
mysql -u root projects < database/database.sql
mysql -u root projects < database/ai_db.sql
mysql -u root projects < database/features_v3.sql
mysql -u root projects < database/job_categories_v2.sql

# 4. Open browser
open http://localhost/NOVAHIRE/
```

**Admin Login:** `admin` / `admin123` at `/admin/admin_login.php`

---

## Features

| Role | Highlights |
|------|-----------|
| **Job Seeker** | AI job matching, resume analyzer, cover letter generator, mock interviews, skill certificates, mentor marketplace, Pro subscription |
| **Company** | Job posting, quiz creation, applicant tracking, interview scheduling, AI job description generator, subscription plans |
| **Mentor** | Profile & availability management, session bookings, earnings tracking |
| **Admin** | Dashboard analytics, user/company management, AI settings, revenue tracking |

### AI Engine (8 Tools)

All tools have **offline rule-based fallbacks** — no API key required. Optional: OpenAI or Google Gemini for enhanced responses.

| Tool | Description |
|------|-------------|
| Job Matching | Skill-based candidate-job matching |
| Resume Analyzer | Scores resume against job requirements |
| Cover Letter Generator | Multi-tone cover letters |
| Mock Interview | Category-based Q&A with scoring |
| Grooming Coach | Personalized study plans |
| Career Path Explorer | Career progression suggestions |
| Skill Gap Analyzer | Missing skills identification |
| AI Chatbot | 22+ intents with sentiment detection |

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 7.4+ (procedural, MySQLi) |
| Database | MySQL 5.7+ |
| Frontend | HTML5, CSS3, Bootstrap 4, Font Awesome 6 |
| JavaScript | jQuery |
| Email | PHPMailer 7.1 (SMTP + mail() fallback) |
| Payments | SSLCOMMERZ (bKash, cards) |
| Auth | Session-based + Google OAuth |

---

## Project Structure

```
NOVAHIRE/
├── index.php              # Landing page
├── auth/                  # Authentication (login, register, OAuth)
├── seeker/                # Job seeker portal (35 pages)
├── company/               # Company portal (18 pages)
├── mentor/                # Mentor portal (8 pages)
├── admin/                 # Admin panel (22 pages)
├── ai/                    # AI engine (8 tools)
├── api/                   # AJAX endpoints (16 files)
├── includes/              # Shared libraries (18 files)
├── database/              # SQL schemas (4 files, 38+ tables)
├── assets/                # CSS, JS
├── images/                # Static assets
├── uploads/               # User uploads
└── vendor/                # Composer (PHPMailer)
```

---

## Database

| File | Tables | Purpose |
|------|--------|---------|
| `database.sql` | ~25 | Users, companies, jobs, applications |
| `ai_db.sql` | 6 | Chat history, cover letters, analyses |
| `features_v3.sql` | 10 | Payments, mentors, certificates |
| `job_categories_v2.sql` | — | Categories + quiz data |

---

## Monetization

| Item | Price (BDT) |
|------|-------------|
| NovaHire Pro | ৳499/month (unlimited AI + applications) |
| Company Basic | ৳2,999/month (10 job posts) |
| Company Professional | ৳7,999/month (50 job posts) |
| Company Enterprise | ৳19,999/year (unlimited) |
| Verified Certificate | ৳299 |
| Featured Job Boost | ৳1,499 (14 days) |

---

## Configuration

**Database** — `admin/dbcon.php`
```php
$host = '127.0.0.1';  $user = 'root';
$password = '';        $database = 'projects';
```

**AI** — Admin Panel → AI Settings (or `ai_settings` table)

**Email** — Admin Panel → Email Settings (or `site_settings` table)

**Google OAuth** — `includes/google_auth.php`

---

## Branches

| Branch | Purpose |
|--------|---------|
| `main` | Production |
| `dev-infra` | Infrastructure, auth, security |
| `dev-seeker` | Job seeker portal |
| `dev-company` | Company portal |
| `dev-admin-ai` | Admin, mentor, AI engine |

---

## License

Educational project.
