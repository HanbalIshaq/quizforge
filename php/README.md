# QuizForge — PHP edition

A self-hostable quiz / exam / poll / survey / form platform that runs on **any
ordinary PHP + MySQL shared hosting** (cPanel, Hostinger, Namecheap, etc.) with
**zero dependencies** — no Composer, no build step, no Node.

This is a from-scratch PHP 8 port of the Python/Flask QuizForge, designed so
non-technical users can "unzip and run the installer" like WordPress.

## Requirements

- PHP 8.0+ with PDO (`pdo_mysql`), `mbstring`, `json`, `curl` — standard on
  every shared host.
- MySQL 5.7+ / MariaDB (production) — or SQLite for zero-setup local testing.

## Install (shared hosting)

1. Upload the entire `php/` folder contents to your web root (e.g. `public_html`).
2. Create a MySQL database + user in cPanel → MySQL Databases.
3. Visit `https://yourdomain.com/install.php` in a browser.
4. Fill in the DB credentials + your admin account, click **Install**.
5. **Delete `install.php`** afterwards (the installer reminds you).

That's it — the installer creates `config.php`, all database tables, and your
super-admin account.

## Local test (no MySQL needed)

```
cd php
php -S 127.0.0.1:8899 router-dev.php
```

Visit http://127.0.0.1:8899/install.php and choose the **SQLite** engine.

> The single-threaded `php -S` dev server can be slow with a browser's parallel
> requests. Set `PHP_CLI_SERVER_WORKERS=6` (see `dev-serve.bat`) or just use
> real Apache/XAMPP for a smoother local experience. Production is unaffected.

## Layout

```
php/
├── index.php            Front controller / router
├── install.php          Web installer (delete after install)
├── routes.php           Route table (grows per build step)
├── config.php           Your settings — created by installer, git-ignored
├── config.sample.php    Template
├── .htaccess            Apache rewrite + security
├── includes/
│   ├── db.php           Portable PDO layer (MySQL / SQLite)
│   ├── schema.php       All table definitions
│   ├── helpers.php      config, CSRF, flash, escaping, feature flags
│   ├── auth.php         Sessions, bcrypt, login/lockout, guards
│   └── grading.php      (Step 3) grading engine
├── views/               PHP templates (Tailwind CDN)
├── assets/css/          Styles
├── uploads/             User uploads (git-ignored)
└── tests/               CLI smoke tests
```

## Build progress

- [x] **Step 1** — Foundation: DB layer, installer, auth, sessions, CSRF, layout
- [ ] Step 2 — Quiz CRUD + dashboard + question editor (30 types)
- [ ] Step 3 — Take-quiz flow + grading + results
- [ ] Step 4 — Polls / surveys / forms + poll dashboard
- [ ] Step 5 — CSV/Excel export, PDF certificates, AI gen, email, bulk import
- [ ] Step 6 — Multi-tenant orgs, plans, site admin, live polling
