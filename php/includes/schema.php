<?php
/**
 * Full database schema for QuizForge (PHP edition).
 *
 * Ported from the Python db.py schema. Every table is created up front by
 * the installer so later features need no migrations. Column types come from
 * the DB::col* helpers so the same file works on MySQL and SQLite.
 *
 * create_schema() is idempotent (CREATE TABLE IF NOT EXISTS) — safe to run
 * repeatedly, e.g. if the installer is re-run.
 */

declare(strict_types=1);

function create_schema(): void
{
    $pk   = DB::colPk();
    $blob = DB::colBlob();
    $ts   = DB::colTs();
    $sfx  = DB::tableSuffix();

    $tables = [];

    $tables[] = "CREATE TABLE IF NOT EXISTS users (
        id $pk,
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(255),
        created_at $ts NOT NULL,
        is_super_admin INT DEFAULT 0,
        is_approved INT DEFAULT 1,
        is_suspended INT DEFAULT 0,
        last_login_at $ts,
        failed_login_count INT DEFAULT 0,
        locked_until $ts,
        plan VARCHAR(32) DEFAULT 'free',
        plan_expires_at $ts
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS quizzes (
        id $pk,
        user_id INT NOT NULL,
        org_id INT,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        share_code VARCHAR(32) UNIQUE NOT NULL,
        kind VARCHAR(16) NOT NULL DEFAULT 'exam',
        time_limit_seconds INT DEFAULT 0,
        randomize_questions INT DEFAULT 0,
        randomize_options INT DEFAULT 0,
        show_correct_answers INT DEFAULT 1,
        require_name INT DEFAULT 1,
        require_email INT DEFAULT 0,
        max_attempts INT DEFAULT 0,
        pass_mark INT DEFAULT 0,
        is_published INT DEFAULT 1,
        paginated INT DEFAULT 0,
        quiz_password VARCHAR(255),
        anti_paste INT DEFAULT 0,
        anti_rightclick INT DEFAULT 0,
        block_selection INT DEFAULT 0,
        require_fullscreen INT DEFAULT 0,
        detect_tab_switch INT DEFAULT 0,
        detect_devtools INT DEFAULT 0,
        violation_limit INT DEFAULT 0,
        ip_allowlist TEXT,
        camera_proctor INT DEFAULT 0,
        proctor_snapshot_interval INT DEFAULT 30,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS questions (
        id $pk,
        quiz_id INT NOT NULL,
        type VARCHAR(32) NOT NULL,
        text TEXT NOT NULL,
        options TEXT,
        correct_answers TEXT,
        points INT DEFAULT 1,
        position INT DEFAULT 0,
        explanation TEXT,
        time_limit_seconds INT DEFAULT 0,
        image_url TEXT,
        is_required INT DEFAULT 1
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS attempts (
        id $pk,
        quiz_id INT NOT NULL,
        student_name VARCHAR(255),
        student_email VARCHAR(255),
        score REAL DEFAULT 0,
        max_score REAL DEFAULT 0,
        percentage REAL DEFAULT 0,
        started_at $ts NOT NULL,
        submitted_at $ts,
        ip_address VARCHAR(64),
        live_session_id INT,
        needs_grading INT DEFAULT 0
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS answers (
        id $pk,
        attempt_id INT NOT NULL,
        question_id INT NOT NULL,
        answer TEXT,
        is_correct INT,
        points_earned REAL DEFAULT 0,
        graded INT DEFAULT 1,
        feedback TEXT
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS violations (
        id $pk,
        attempt_id INT NOT NULL,
        type VARCHAR(32) NOT NULL,
        details TEXT,
        created_at $ts NOT NULL
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS live_sessions (
        id $pk,
        quiz_id INT NOT NULL,
        join_code VARCHAR(32) UNIQUE NOT NULL,
        status VARCHAR(16) DEFAULT 'waiting',
        current_question_index INT DEFAULT -1,
        started_at $ts NOT NULL,
        ended_at $ts
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS proctor_snapshots (
        id $pk,
        attempt_id INT NOT NULL,
        captured_at $ts NOT NULL,
        kind VARCHAR(32),
        notes TEXT,
        image_bytes $blob
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS question_bank (
        id $pk,
        user_id INT NOT NULL,
        type VARCHAR(32) NOT NULL,
        text TEXT NOT NULL,
        options TEXT,
        correct_answers TEXT,
        points INT DEFAULT 1,
        explanation TEXT,
        time_limit_seconds INT DEFAULT 0,
        category VARCHAR(128),
        tags TEXT,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS password_resets (
        id $pk,
        user_id INT NOT NULL,
        token VARCHAR(255) UNIQUE NOT NULL,
        created_at $ts NOT NULL,
        expires_at $ts NOT NULL,
        used_at $ts
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS site_settings (
        skey VARCHAR(64) PRIMARY KEY,
        svalue TEXT
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS certificates (
        id $pk,
        attempt_id INT NOT NULL,
        quiz_id INT NOT NULL,
        serial VARCHAR(64) UNIQUE NOT NULL,
        recipient_name VARCHAR(255),
        score REAL,
        max_score REAL,
        percentage REAL,
        issued_at $ts NOT NULL,
        pdf_bytes $blob
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS ai_generations (
        id $pk,
        user_id INT NOT NULL,
        quiz_id INT,
        n_questions INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS organizations (
        id $pk,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(64) UNIQUE NOT NULL,
        created_at $ts NOT NULL,
        created_by_user_id INT NOT NULL
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS org_members (
        org_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(16) NOT NULL,
        joined_at $ts NOT NULL,
        PRIMARY KEY (org_id, user_id)
    )$sfx";

    $tables[] = "CREATE TABLE IF NOT EXISTS org_invites (
        id $pk,
        org_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        role VARCHAR(16) NOT NULL,
        token VARCHAR(255) UNIQUE NOT NULL,
        invited_by_user_id INT NOT NULL,
        created_at $ts NOT NULL,
        expires_at $ts NOT NULL,
        accepted_at $ts
    )$sfx";

    foreach ($tables as $sql) {
        DB::run($sql);
    }

    // Indexes (IF NOT EXISTS works on both MySQL 8+ and SQLite; wrap in try
    // for older MySQL that lacks IF NOT EXISTS on CREATE INDEX).
    $indexes = [
        "CREATE INDEX idx_questions_quiz ON questions(quiz_id, position)",
        "CREATE INDEX idx_attempts_quiz ON attempts(quiz_id)",
        "CREATE INDEX idx_answers_attempt ON answers(attempt_id)",
        "CREATE INDEX idx_violations_attempt ON violations(attempt_id)",
        "CREATE INDEX idx_live_quiz ON live_sessions(quiz_id)",
        "CREATE INDEX idx_proctor_attempt ON proctor_snapshots(attempt_id)",
        "CREATE INDEX idx_bank_user ON question_bank(user_id)",
        "CREATE INDEX idx_ai_gen_user_time ON ai_generations(user_id, created_at)",
        "CREATE INDEX idx_org_members_user ON org_members(user_id)",
        "CREATE INDEX idx_org_invites_org ON org_invites(org_id)",
        "CREATE INDEX idx_quizzes_org ON quizzes(org_id)",
        "CREATE INDEX idx_quizzes_user ON quizzes(user_id)",
    ];
    foreach ($indexes as $sql) {
        try {
            DB::run($sql);
        } catch (Throwable $e) {
            // Index already exists — ignore (older MySQL has no IF NOT EXISTS
            // for CREATE INDEX, so a duplicate throws; that's fine).
        }
    }
}
