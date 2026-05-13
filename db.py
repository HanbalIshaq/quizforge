import sqlite3
import os
import time
import secrets
import string

DB_PATH = os.environ.get("DATABASE_PATH", "quizforge.db")


def get_conn():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    name TEXT,
    created_at INTEGER NOT NULL,
    is_super_admin INTEGER DEFAULT 0,
    is_approved INTEGER DEFAULT 1,
    is_suspended INTEGER DEFAULT 0,
    last_login_at INTEGER
);

CREATE TABLE IF NOT EXISTS quizzes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    description TEXT,
    share_code TEXT UNIQUE NOT NULL,
    kind TEXT NOT NULL DEFAULT 'exam',           -- 'exam' | 'poll' | 'survey'
    time_limit_seconds INTEGER DEFAULT 0,
    randomize_questions INTEGER DEFAULT 0,
    randomize_options INTEGER DEFAULT 0,
    show_correct_answers INTEGER DEFAULT 1,
    require_name INTEGER DEFAULT 1,
    require_email INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 0,
    pass_mark INTEGER DEFAULT 0,
    is_published INTEGER DEFAULT 1,
    paginated INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    text TEXT NOT NULL,
    options TEXT,                                -- JSON array
    correct_answers TEXT,                        -- JSON
    points INTEGER DEFAULT 1,
    position INTEGER DEFAULT 0,
    explanation TEXT,
    time_limit_seconds INTEGER DEFAULT 0         -- 0 = no per-question limit
);

CREATE TABLE IF NOT EXISTS attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
    student_name TEXT,
    student_email TEXT,
    score REAL DEFAULT 0,
    max_score REAL DEFAULT 0,
    percentage REAL DEFAULT 0,
    started_at INTEGER NOT NULL,
    submitted_at INTEGER,
    ip_address TEXT,
    live_session_id INTEGER REFERENCES live_sessions(id) ON DELETE SET NULL,
    needs_grading INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    answer TEXT,                                 -- JSON
    is_correct INTEGER,
    points_earned REAL DEFAULT 0,
    graded INTEGER DEFAULT 1,
    feedback TEXT
);

CREATE TABLE IF NOT EXISTS violations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
    type TEXT NOT NULL,                          -- tab_switch | paste | copy | rightclick | fullscreen_exit | devtools | other
    details TEXT,
    created_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_violations_attempt ON violations(attempt_id);

CREATE TABLE IF NOT EXISTS live_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
    join_code TEXT UNIQUE NOT NULL,
    status TEXT DEFAULT 'waiting',               -- waiting | running | finished
    current_question_index INTEGER DEFAULT -1,
    started_at INTEGER NOT NULL,
    ended_at INTEGER
);

CREATE INDEX IF NOT EXISTS idx_questions_quiz ON questions(quiz_id, position);
CREATE INDEX IF NOT EXISTS idx_attempts_quiz ON attempts(quiz_id);
CREATE INDEX IF NOT EXISTS idx_answers_attempt ON answers(attempt_id);
CREATE INDEX IF NOT EXISTS idx_live_quiz ON live_sessions(quiz_id);
"""


def init_db():
    conn = get_conn()
    conn.executescript(SCHEMA)
    # Lightweight migrations for older DBs
    _ensure_column(conn, "users", "is_super_admin", "INTEGER DEFAULT 0")
    _ensure_column(conn, "users", "is_approved", "INTEGER DEFAULT 1")
    _ensure_column(conn, "users", "is_suspended", "INTEGER DEFAULT 0")
    _ensure_column(conn, "users", "last_login_at", "INTEGER")
    _ensure_column(conn, "questions", "time_limit_seconds", "INTEGER DEFAULT 0")
    _ensure_column(conn, "quizzes", "paginated", "INTEGER DEFAULT 0")
    _ensure_column(conn, "quizzes", "quiz_password", "TEXT")
    _ensure_column(conn, "quizzes", "anti_paste", "INTEGER DEFAULT 0")
    _ensure_column(conn, "quizzes", "anti_rightclick", "INTEGER DEFAULT 0")
    _ensure_column(conn, "quizzes", "block_selection", "INTEGER DEFAULT 0")
    _ensure_column(conn, "quizzes", "require_fullscreen", "INTEGER DEFAULT 0")
    _ensure_column(conn, "quizzes", "detect_tab_switch", "INTEGER DEFAULT 0")
    _ensure_column(conn, "quizzes", "violation_limit", "INTEGER DEFAULT 0")
    conn.commit()
    conn.close()


def _ensure_column(conn, table: str, column: str, type_decl: str) -> None:
    cols = {r["name"] for r in conn.execute(f"PRAGMA table_info({table})")}
    if column not in cols:
        conn.execute(f"ALTER TABLE {table} ADD COLUMN {column} {type_decl}")


def now_ts() -> int:
    return int(time.time())


def gen_code(length: int = 6, alphabet: str = None) -> str:
    alphabet = alphabet or (string.ascii_uppercase + string.digits)
    alphabet = "".join(c for c in alphabet if c not in "0O1IL")
    return "".join(secrets.choice(alphabet) for _ in range(length))


def unique_code(table: str, column: str, length: int = 6) -> str:
    conn = get_conn()
    try:
        while True:
            code = gen_code(length)
            row = conn.execute(
                f"SELECT 1 FROM {table} WHERE {column} = ?", (code,)
            ).fetchone()
            if not row:
                return code
    finally:
        conn.close()
