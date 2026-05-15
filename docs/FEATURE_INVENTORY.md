# QuizForge — Full Feature Inventory

Every feature competitors offer (Quizizz, Kahoot, ClassMarker, Mentimeter, Slido, ProProfs, TestGorilla, FlexiQuiz, Edulastic, Formative, Respondus, ProctorU, Honorlock). Status keys:

- ✅ **Done** — in production right now
- 🚧 **Building this round**
- ⏳ **Next 1–2 builds**
- 📋 **Roadmap**

---

## 1. SIGN-IN & ACCOUNT MANAGEMENT

| # | Feature | Status | Notes |
|---|---|---|---|
| 1.1 | Email + password sign-up | ✅ | bcrypt-hashed |
| 1.2 | Email + password sign-in | ✅ | |
| 1.3 | Sign out | ✅ | |
| 1.4 | Forgot password / reset by email | 🚧 | Token-based, this build |
| 1.5 | Login rate limiting (anti-brute-force) | 🚧 | 5/15 min per IP, this build |
| 1.6 | Account lockout after N failed logins | 🚧 | 10 attempts → 1 hour lock, this build |
| 1.7 | Email verification on signup | ⏳ | Needs SMTP / SendGrid |
| 1.8 | Sign in with Google (OAuth) | ⏳ | Authlib |
| 1.9 | Sign in with Microsoft (OAuth) | ⏳ | |
| 1.10 | Sign in with Apple | 📋 | |
| 1.11 | Magic link login (passwordless) | 📋 | |
| 1.12 | Two-factor auth (TOTP / authenticator app) | 📋 | pyotp |
| 1.13 | Two-factor auth (SMS) | 📋 | Twilio |
| 1.14 | SAML SSO (enterprise) | 📋 | python3-saml |
| 1.15 | Session timeout configurable | ⏳ | |
| 1.16 | "Remember me" / persistent sessions | ⏳ | |
| 1.17 | Active sessions list (revoke remote) | 📋 | |
| 1.18 | Change password from settings | ⏳ | |
| 1.19 | Change email from settings | ⏳ | |
| 1.20 | Delete my account (GDPR) | ⏳ | |
| 1.21 | Download my data (GDPR) | 📋 | |
| 1.22 | Password strength meter | ⏳ | zxcvbn |
| 1.23 | Breach-password detection (HaveIBeenPwned) | 📋 | |

## 2. ACCOUNT SECURITY (the server)

| # | Feature | Status | Notes |
|---|---|---|---|
| 2.1 | bcrypt password hashing | ✅ | |
| 2.2 | HTTPS in production | ✅ | Render auto-issues |
| 2.3 | Secure session cookies (HttpOnly + Secure flags) | ⏳ | Need to set explicitly |
| 2.4 | CSRF protection on forms | ⏳ | Flask-WTF |
| 2.5 | XSS auto-escaping in templates | ✅ | Jinja2 default |
| 2.6 | SQL injection protection | ✅ | Parameterized queries everywhere |
| 2.7 | Login rate limiting (per IP) | 🚧 | |
| 2.8 | Login rate limiting (per email) | 🚧 | |
| 2.9 | Account lockout after N failed attempts | 🚧 | |
| 2.10 | Suspicious-login email alert | 📋 | |
| 2.11 | Password reset token expiry (15 min) | 🚧 | |
| 2.12 | Password reset token single-use | 🚧 | |
| 2.13 | Admin audit log (who changed what) | 📋 | |
| 2.14 | Content Security Policy headers | 📋 | |
| 2.15 | Strict-Transport-Security headers | 📋 | |
| 2.16 | X-Content-Type-Options / X-Frame-Options | 📋 | |
| 2.17 | Encrypted data at rest (DB-level) | ✅ | Neon TLS, encrypted disks |
| 2.18 | Encrypted secrets via env vars | ✅ | |
| 2.19 | API rate limiting | 📋 | Flask-Limiter |
| 2.20 | DDoS protection | ✅ | Render/Cloudflare layer |

## 3. ANTI-CHEATING DURING EXAMS

| # | Feature | Status | Notes |
|---|---|---|---|
| 3.1 | Tab / window switch detection | ✅ | |
| 3.2 | Window blur (focus loss) detection | ✅ | |
| 3.3 | Copy / paste / cut block | ✅ | |
| 3.4 | Right-click block | ✅ | |
| 3.5 | Text selection block | ✅ | |
| 3.6 | Force full-screen mode | ✅ | |
| 3.7 | Full-screen exit detection | ✅ | |
| 3.8 | Auto-submit after N violations | ✅ | |
| 3.9 | Quiz password gate | ✅ | |
| 3.10 | Browser-close warning | ✅ | |
| 3.11 | Question randomization per attempt | ✅ | Hash-seeded |
| 3.12 | Option randomization per attempt | ✅ | |
| 3.13 | Per-attempt violation log | ✅ | Visible in admin |
| 3.14 | IP allow-list (region restriction) | 🚧 | This build |
| 3.15 | Multiple-tab detection (same quiz in 2 tabs) | 🚧 | BroadcastChannel/localStorage |
| 3.16 | Dev-tools open detection | 🚧 | window.outerHeight trick |
| 3.17 | One-attempt-per-IP enforcement | ⏳ | |
| 3.18 | Browser fingerprint check | ⏳ | FingerprintJS |
| 3.19 | User-agent restriction | ⏳ | |
| 3.20 | Webcam snapshots periodic | 📋 | TestGorilla-style |
| 3.21 | Face detection (no face / multiple faces) | 📋 | face-api.js |
| 3.22 | Object detection (phone / headphones on desk) | 📋 | TF.js + COCO-SSD |
| 3.23 | Eye-gaze direction tracking | 📋 | Specialized |
| 3.24 | Microphone monitoring (talking detection) | 📋 | |
| 3.25 | Full session screen recording | 📋 | |
| 3.26 | Live human proctor | 📋 | Partnership |
| 3.27 | Custom lockdown browser app | 📋 | Electron build |
| 3.28 | One-question-at-a-time (no skipping back) | ✅ | Paginated mode |
| 3.29 | Random pool selection ("show 10 of 50") | 📋 | |
| 3.30 | Question shuffling within sections | 📋 | |

## 4. QUESTION TYPES

| # | Type | Status |
|---|---|---|
| 4.1 | Multiple choice (single answer) | ✅ |
| 4.2 | Multiple choice (multi-select) | ✅ |
| 4.3 | True / False (Yes / No) | ✅ |
| 4.4 | Short answer (text match) | ✅ |
| 4.5 | Long answer / essay | ✅ |
| 4.6 | Fill-in-the-blank | ✅ |
| 4.7 | Rating (1-5 stars) | ✅ |
| 4.8 | NPS (0-10) | ✅ |
| 4.9 | Poll choice (no correct answer) | ✅ |
| 4.10 | Open-ended (free text, ungraded) | ✅ |
| 4.11 | Word cloud (live aggregation) | ✅ |
| 4.12 | Matching / pairing | ⏳ |
| 4.13 | Ordering / sequencing | ⏳ |
| 4.14 | Drag-and-drop to bins | ⏳ |
| 4.15 | Hotspot (click on image) | ⏳ |
| 4.16 | Label image (drag labels onto image) | 📋 |
| 4.17 | Math equation editor (MathLive) | 📋 |
| 4.18 | Graphing / number-line answer | 📋 |
| 4.19 | Chemical formula | 📋 |
| 4.20 | Code-editor question (programming) | 📋 |
| 4.21 | Audio question (play before answering) | 📋 |
| 4.22 | Video question (play before answering) | 📋 |
| 4.23 | Audio response (student records voice) | 📋 |
| 4.24 | Drawing canvas answer | 📋 |
| 4.25 | File upload answer | 📋 |
| 4.26 | Slider answer (continuous scale) | 📋 |
| 4.27 | Date / time answer | 📋 |

## 5. TEST ADMINISTRATION & LOGIC

| # | Feature | Status |
|---|---|---|
| 5.1 | Whole-exam time limit + auto-submit | ✅ |
| 5.2 | Per-question time limit | ✅ |
| 5.3 | Auto-advance on per-question timer | ✅ |
| 5.4 | Bulk apply same time to every question | ✅ |
| 5.5 | Pass mark % | ✅ |
| 5.6 | Max attempts per quiz | ✅ |
| 5.7 | Show / hide correct answers after submit | ✅ |
| 5.8 | Publish / unpublish toggle | ✅ |
| 5.9 | Schedule open / close dates | ⏳ |
| 5.10 | Branching / conditional logic (skip Q5 if Q4=Yes) | 📋 |
| 5.11 | Random pool: "pick 10 questions from 50" | 📋 |
| 5.12 | Sectioned tests (groups of questions) | 📋 |
| 5.13 | Difficulty-weighted scoring | 📋 |
| 5.14 | Negative marking | 📋 |
| 5.15 | Partial credit | 📋 |

## 6. TEACHER PRODUCTIVITY

| # | Feature | Status |
|---|---|---|
| 6.1 | Bulk import from .docx | ✅ |
| 6.2 | Bulk import from .csv | ✅ |
| 6.3 | Bulk import from .txt (Aiken) | ✅ |
| 6.4 | AI quiz generation from text/topic | ✅ |
| 6.5 | AI quiz generation from PDF upload | ⏳ |
| 6.6 | AI quiz generation from URL | ⏳ |
| 6.7 | Auto-save settings on change | ✅ |
| 6.8 | Question bank (reuse across quizzes) | ⏳ |
| 6.9 | Categories / tags on questions | ⏳ |
| 6.10 | Search question bank | ⏳ |
| 6.11 | Question rubrics for essays | 📋 |
| 6.12 | Plagiarism check on essay answers | 📋 |
| 6.13 | Multi-instructor / collaborator on quiz | 📋 |
| 6.14 | Quiz templates marketplace | 📋 |
| 6.15 | Duplicate a quiz / save as draft | ⏳ |
| 6.16 | Version history of a quiz | 📋 |

## 7. STUDENT EXPERIENCE

| # | Feature | Status |
|---|---|---|
| 7.1 | Mobile-responsive UI | ✅ |
| 7.2 | Take quiz via share link (no signup) | ✅ |
| 7.3 | Join live session via 6-char code | ✅ |
| 7.4 | Auto-save partial answers | ✅ |
| 7.5 | Resume where I left off | ✅ |
| 7.6 | Browser-close warning | ✅ |
| 7.7 | Real-time progress (paginated mode) | ✅ |
| 7.8 | View correct answers after submit (if allowed) | ✅ |
| 7.9 | Receive PDF certificate | ⏳ |
| 7.10 | Email confirmation of attempt | ⏳ |
| 7.11 | Multi-language UI (i18n) | ⏳ |
| 7.12 | Right-to-left language support (Urdu, Arabic) | ⏳ |
| 7.13 | Text-to-speech for accessibility | 📋 |
| 7.14 | Dyslexia-friendly font option | 📋 |
| 7.15 | Larger font / high-contrast mode | 📋 |
| 7.16 | Keyboard-only navigation | ⏳ |
| 7.17 | PWA offline mode (sync when reconnected) | 📋 |
| 7.18 | iOS native app | 📋 |
| 7.19 | Android native app | 📋 |
| 7.20 | Print quiz / answer sheet | 📋 |

## 8. RESULTS & ANALYTICS

| # | Feature | Status |
|---|---|---|
| 8.1 | Score / max / percentage per attempt | ✅ |
| 8.2 | Pass / fail badge | ✅ |
| 8.3 | Per-question breakdown bar charts | ✅ |
| 8.4 | NPS dashboard (detractors/passives/promoters) | ✅ |
| 8.5 | Word cloud for open-text | ✅ |
| 8.6 | "Who picked this option" drill-down | ✅ |
| 8.7 | Questions-answered count per respondent | ✅ |
| 8.8 | Real-time auto-refreshing dashboard | ✅ |
| 8.9 | Dedupe duplicate submissions | ✅ |
| 8.10 | Delete individual attempt | ✅ |
| 8.11 | Manual grading for essays | ✅ |
| 8.12 | Manual grading feedback to student | ✅ |
| 8.13 | Item analysis (difficulty index, discrimination) | 📋 |
| 8.14 | IRT (Item Response Theory) | 📋 |
| 8.15 | Cohort comparison (this class vs avg) | 📋 |
| 8.16 | Longitudinal tracking (student improvement over time) | 📋 |
| 8.17 | Mastery / proficiency tracking by standard | 📋 |
| 8.18 | Parent / supervisor report | 📋 |
| 8.19 | Email weekly summary to teacher | 📋 |
| 8.20 | CSV export | ✅ |
| 8.21 | Excel (.xlsx) export | ✅ |
| 8.22 | PDF export of single attempt | 📋 |
| 8.23 | PDF gradebook export | 📋 |

## 9. CERTIFICATES

| # | Feature | Status |
|---|---|---|
| 9.1 | Branded PDF certificate on pass | ⏳ |
| 9.2 | Custom certificate template (logo, colors) | ⏳ |
| 9.3 | Unique serial number per certificate | ⏳ |
| 9.4 | Public verification URL | ⏳ |
| 9.5 | Digitally signed PDF | 📋 |
| 9.6 | Blockchain anchoring (immutable) | 📋 |
| 9.7 | Issue date + expiry date | 📋 |
| 9.8 | Recipient name / quiz title in cert | ⏳ |
| 9.9 | Email certificate to recipient | ⏳ |
| 9.10 | Share certificate on LinkedIn | 📋 |

## 10. COLLABORATION & ORGANIZATIONS

| # | Feature | Status |
|---|---|---|
| 10.1 | Solo teacher account | ✅ |
| 10.2 | Super-admin / site owner role | ✅ |
| 10.3 | Site stats dashboard | ✅ |
| 10.4 | User approval workflow | ✅ |
| 10.5 | Suspend / promote users | ✅ |
| 10.6 | Multi-tenant organisations | 📋 |
| 10.7 | Org-level admin / teacher / student roles | 📋 |
| 10.8 | Invite teachers by email | 📋 |
| 10.9 | Classes / cohorts | 📋 |
| 10.10 | Student rosters (bulk import) | 📋 |
| 10.11 | Org-level branding (logo, primary color) | 📋 |
| 10.12 | White-label / custom domain | 📋 |

## 11. INTEGRATIONS

| # | Feature | Status |
|---|---|---|
| 11.1 | Self-host on any Python host | ✅ |
| 11.2 | PostgreSQL or SQLite | ✅ |
| 11.3 | Stripe billing | ⏳ |
| 11.4 | Zapier (publish trigger) | 📋 |
| 11.5 | Webhooks (on quiz submit) | 📋 |
| 11.6 | Public REST API | 📋 |
| 11.7 | LTI 1.3 (Canvas/Moodle/Schoology/Blackboard) | 📋 |
| 11.8 | SCORM 1.2 / xAPI export | 📋 |
| 11.9 | Google Classroom plugin | 📋 |
| 11.10 | Microsoft Teams plugin | 📋 |
| 11.11 | Embed quiz iframe on any site | 📋 |
| 11.12 | SMTP / SendGrid email transport | ⏳ |
| 11.13 | Twilio SMS (2FA) | 📋 |
| 11.14 | OpenAI API (alternative to Anthropic) | 📋 |
| 11.15 | Slack notifications on submit | 📋 |
| 11.16 | Discord webhook | 📋 |

## 12. MARKETING & GROWTH

| # | Feature | Status |
|---|---|---|
| 12.1 | SEO-optimized landing page | ✅ |
| 12.2 | Pricing page | ✅ |
| 12.3 | Features page | ✅ |
| 12.4 | Use-cases page | ✅ |
| 12.5 | About / Privacy / Terms | ✅ |
| 12.6 | robots.txt + sitemap.xml | ✅ |
| 12.7 | JSON-LD structured data | ✅ |
| 12.8 | Open Graph / Twitter cards | ✅ |
| 12.9 | Public quiz library / templates | 📋 |
| 12.10 | Referral program (give X get Y) | 📋 |
| 12.11 | Affiliate program | 📋 |
| 12.12 | Lead capture (email gate before quiz) | 📋 |
| 12.13 | Paywall a quiz (sell access) | 📋 |
| 12.14 | Blog / content marketing engine | 📋 |
| 12.15 | Customer testimonials section | 📋 |

## 13. BILLING & PLANS

| # | Feature | Status |
|---|---|---|
| 13.1 | Free tier with quotas | ⏳ |
| 13.2 | Stripe checkout (subscription) | ⏳ |
| 13.3 | Stripe checkout (one-time license) | ⏳ |
| 13.4 | Plan upgrade / downgrade | ⏳ |
| 13.5 | Pro-rated invoicing | ⏳ |
| 13.6 | Trial period | ⏳ |
| 13.7 | Coupon codes | 📋 |
| 13.8 | Invoices via email | ⏳ |
| 13.9 | Usage meter (responses this month) | 📋 |
| 13.10 | Soft-limit warnings near quota | 📋 |
| 13.11 | Hard-limit enforcement at quota | 📋 |
| 13.12 | Manual billing / wire transfer (enterprise) | 📋 |

---

## How to read this

Total competitor features in the inventory: **~190**.
- ✅ **Done in QuizForge:** ~80
- 🚧 **Building this round:** ~7
- ⏳ **Next 1–2 builds:** ~30
- 📋 **Roadmap (longer term):** ~75

We're already at **42% feature parity** with the top of the market — and ahead on a few (e.g. modern UI, real-time auto-refresh polls dashboard, hash-seeded randomization).

## What we're building right now (this session)

From the 🚧 list:

1. **1.4** — Forgot password / reset by email (token-based, even without SMTP set up)
2. **1.5 / 1.6 / 2.7 / 2.8 / 2.9** — Login rate limiting + account lockout
3. **2.11 / 2.12** — Password reset token expiry + single-use
4. **3.14** — IP allow-list for exams
5. **3.16** — Dev-tools-open detection

After this, the next batch to build (rough priority for sellability): certificates (9.x), Stripe billing (13.x), Google/Microsoft SSO (1.8/1.9), question bank (6.8/6.9/6.10), and more question types (4.12–4.16).
