<?php
/**
 * QuizForge configuration template.
 *
 * The installer (install.php) writes a real config.php based on this file.
 * You can also copy this to config.php and fill it in by hand.
 *
 * config.php is git-ignored and protected from web access by .htaccess.
 */

return [
    // ── Database ──────────────────────────────────────────────────────────
    // driver: 'mysql' for shared hosting (production), 'sqlite' for quick
    // local testing with zero setup.
    'db_driver'   => 'mysql',

    // MySQL settings (from your cPanel → MySQL Databases)
    'db_host'     => 'localhost',
    'db_port'     => 3306,
    'db_name'     => 'quizforge',
    'db_user'     => 'root',
    'db_pass'     => '',
    'db_charset'  => 'utf8mb4',

    // SQLite path (only used when db_driver = 'sqlite')
    'sqlite_path' => __DIR__ . '/data/quizforge.sqlite',

    // ── App ───────────────────────────────────────────────────────────────
    // A long random string — used to sign sessions. Keep it secret.
    'secret_key'  => 'change-me-to-a-long-random-string',

    // Public base URL (no trailing slash). Used for building absolute links
    // like share URLs and certificate verification links.
    // Leave empty to auto-detect from the request.
    'base_url'    => '',

    // ── Email (optional) ─────────────────────────────────────────────────
    // If SMTP is not configured, password-reset / invite links are shown
    // on-screen instead of emailed.
    'smtp_host'   => '',
    'smtp_port'   => 587,
    'smtp_user'   => '',
    'smtp_pass'   => '',
    'smtp_from'   => 'no-reply@example.com',
    'smtp_secure' => 'tls', // 'tls' | 'ssl' | ''

    // ── AI generation (optional) ─────────────────────────────────────────
    // Provide one. If both empty, the AI feature stays off.
    'anthropic_api_key' => '',
    'openai_api_key'    => '',

    // Marks that the installer has run. Do not edit.
    'installed'   => true,
];
