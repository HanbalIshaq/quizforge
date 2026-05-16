"""
Database abstraction layer for QuizForge. (Persistent storage live since 2026-05-14.)

Picks the engine at startup from the DATABASE_URL env var:
  - postgresql://user:pass@host:port/dbname  -> PostgreSQL (production)
  - postgres://...                            -> PostgreSQL (legacy URL form)
  - (unset)                                   -> SQLite at $DATABASE_PATH (local dev / quick demo)

The Connection wrapper exposes the same API as Python's sqlite3 (`conn.execute(...).fetchone()`,
`cur.lastrowid`, `conn.commit()`, etc.) so the rest of the codebase doesn't care which engine
is in use. To move to ANY new Postgres host (Render, Neon, Supabase, AWS RDS, your own VPS),
just point DATABASE_URL at it.

Supported SQL dialect: the subset used by QuizForge. The translator handles:
  - `?` placeholders   -> `%s` for psycopg
  - INTEGER PRIMARY KEY AUTOINCREMENT -> SERIAL PRIMARY KEY
  - automatic RETURNING id on INSERT  -> populates lastrowid for callers
  - PRAGMA foreign_keys = ON           -> no-op (PG always enforces FKs)

Adding a new column to the schema: edit SCHEMA below AND add a matching `_ensure_column`
call in `init_db()` so existing databases get migrated in-place.
"""
import os
import re
import secrets
import string
import time

DATABASE_URL = os.environ.get("DATABASE_URL", "").strip()
# Render / Heroku-style legacy URLs use "postgres://"; psycopg wants "postgresql://"
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = "postgresql://" + DATABASE_URL[len("postgres://"):]

IS_POSTGRES = DATABASE_URL.startswith("postgresql://")
DB_PATH = os.environ.get("DATABASE_PATH", "quizforge.db")


# ---------------------------------------------------------------------------
# Engine selection
# ---------------------------------------------------------------------------

if IS_POSTGRES:
    import psycopg
    from psycopg.rows import dict_row
else:
    import sqlite3


def _translate_sql(sql: str) -> str:
    """Translate the SQLite dialect (which the schema is written in) to Postgres."""
    # `?` placeholders -> `%s`
    # We only have `?` in placeholders for our app (no `?` inside string literals).
    sql = re.sub(r"\?", "%s", sql)
    # INTEGER PRIMARY KEY AUTOINCREMENT -> SERIAL PRIMARY KEY
    sql = re.sub(
        r"INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT",
        "SERIAL PRIMARY KEY",
        sql,
        flags=re.IGNORECASE,
    )
    # Postgres rejects PRAGMA — strip it.
    sql = re.sub(r"PRAGMA\s+[^;]+;?", "", sql, flags=re.IGNORECASE)
    return sql


_INSERT_RE = re.compile(r"^\s*INSERT\s+INTO\s+([\w\"]+)", re.IGNORECASE)
_HAS_RETURNING_RE = re.compile(r"\bRETURNING\b", re.IGNORECASE)


def _maybe_add_returning(sql: str) -> tuple[str, bool]:
    """For Postgres INSERTs, append `RETURNING id` so we can populate `cur.lastrowid`."""
    if not _INSERT_RE.match(sql):
        return sql, False
    if _HAS_RETURNING_RE.search(sql):
        return sql, False
    return sql.rstrip().rstrip(";") + " RETURNING id", True


class Row(dict):
    """dict-like row that also supports attribute access (`row.id`) like sqlite3.Row."""

    def __getattr__(self, key):
        try:
            return self[key]
        except KeyError as e:
            raise AttributeError(key) from e


class _CursorWrapper:
    """Uniform cursor that exposes .fetchone(), .fetchall(), .lastrowid, .rowcount."""

    def __init__(self, native_cursor, lastrowid=None):
        self._cur = native_cursor
        self.lastrowid = lastrowid
        try:
            self.rowcount = native_cursor.rowcount
        except Exception:
            self.rowcount = 0

    def fetchone(self):
        row = self._cur.fetchone()
        if row is None:
            return None
        if IS_POSTGRES:
            # psycopg dict_row returns a real dict; wrap so attribute access works.
            return Row(row)
        # sqlite3.Row already supports both index and key access; keep as-is.
        return row

    def fetchall(self):
        rows = self._cur.fetchall()
        if IS_POSTGRES:
            return [Row(r) for r in rows]
        return list(rows)

    def __iter__(self):
        for r in self.fetchall():
            yield r

    def close(self):
        try:
            self._cur.close()
        except Exception:
            pass


class Connection:
    """Drop-in replacement for sqlite3.Connection that works on either engine."""

    def __init__(self, native_conn):
        self._conn = native_conn

    def execute(self, sql: str, params=()):
        if IS_POSTGRES:
            translated = _translate_sql(sql)
            translated, added_returning = _maybe_add_returning(translated)
            cur = self._conn.cursor(row_factory=dict_row)
            cur.execute(translated, tuple(params) if params else ())
            lastrowid = None
            if added_returning:
                row = cur.fetchone()
                if row:
                    lastrowid = row.get("id")
            return _CursorWrapper(cur, lastrowid=lastrowid)
        cur = self._conn.execute(sql, params)
        return _CursorWrapper(cur, lastrowid=cur.lastrowid)

    def executescript(self, sql: str):
        if IS_POSTGRES:
            translated = _translate_sql(sql)
            cur = self._conn.cursor()
            # Naive split is OK for our schema (no `;` inside literals/comments).
            for stmt in translated.split(";"):
                if stmt.strip():
                    cur.execute(stmt)
            cur.close()
        else:
            self._conn.executescript(sql)

    def executemany(self, sql: str, seq_of_params):
        """Run the same SQL for many rows in a single roundtrip.

        Critical for the quiz-submit path where we were doing N separate
        INSERTs (one per question) — on Neon that's N network roundtrips
        adding up to seconds of latency. With executemany the entire batch
        ships in one go.
        """
        seq = [tuple(p) for p in seq_of_params]
        if not seq:
            return None
        if IS_POSTGRES:
            translated = _translate_sql(sql)
            # executemany doesn't return per-row ids, so don't bother adding
            # RETURNING. Call sites that need lastrowid use single execute().
            cur = self._conn.cursor()
            cur.executemany(translated, seq)
            return _CursorWrapper(cur, lastrowid=None)
        cur = self._conn.executemany(sql, seq)
        return _CursorWrapper(cur, lastrowid=cur.lastrowid)

    def commit(self):
        self._conn.commit()

    def rollback(self):
        self._conn.rollback()

    def close(self):
        try:
            self._conn.close()
        except Exception:
            pass


# Connection settings tuned for Neon-style serverless Postgres: short connect
# timeout (so a sleeping endpoint doesn't hang the request for 30s+), plus a
# small retry loop because the first request after the DB sleeps will reset
# the TCP connection before the wake-up handshake completes.
_PG_CONNECT_TIMEOUT = int(os.environ.get("PG_CONNECT_TIMEOUT", "10"))
_PG_CONNECT_RETRIES = int(os.environ.get("PG_CONNECT_RETRIES", "3"))


def is_retryable_db_error(e: BaseException) -> bool:
    """True for transient Postgres errors that are safe to retry by re-running
    the whole transaction from scratch — primarily DeadlockDetected (40P01)
    and SerializationFailure (40001). The Postgres docs explicitly call out
    re-running the transaction as the correct response to these.

    Detection by SQLSTATE / class name so we don't have to import psycopg
    here (this module is also imported from SQLite-only environments).
    """
    sqlstate = getattr(e, "sqlstate", None) or getattr(e, "pgcode", None)
    if sqlstate in ("40P01", "40001"):
        return True
    name = type(e).__name__
    if name in ("DeadlockDetected", "SerializationFailure", "TransactionRollback"):
        return True
    # Some psycopg versions wrap deadlocks; check the chain.
    msg = (str(e) or "").lower()
    return "deadlock detected" in msg or "could not serialize" in msg


def run_in_txn(work, retries: int = 3, backoff: float = 0.1):
    """Run `work(conn)` inside a fresh connection, retrying on transient
    Postgres serialization / deadlock errors. The work function MUST be
    idempotent on retry — i.e. don't perform side effects (HTTP calls,
    sending email, etc.) before commit, only after.

    Returns whatever `work(conn)` returns. The function is responsible for
    its own commit/rollback semantics on the happy path; we only rollback
    on retryable errors before retrying."""
    last_err: BaseException | None = None
    for attempt in range(retries):
        conn = get_conn()
        try:
            return work(conn)
        except Exception as e:  # noqa: BLE001
            try:
                conn.rollback()
            except Exception:
                pass
            if is_retryable_db_error(e) and attempt < retries - 1:
                last_err = e
                # Tiny jitter so two contending requests don't lockstep again.
                time.sleep(backoff * (2 ** attempt) + (secrets.randbelow(50) / 1000.0))
                continue
            raise
        finally:
            try:
                conn.close()
            except Exception:
                pass
    if last_err is not None:
        raise last_err


def get_conn() -> Connection:
    if IS_POSTGRES:
        last_err = None
        for attempt in range(_PG_CONNECT_RETRIES):
            try:
                native = psycopg.connect(
                    DATABASE_URL,
                    connect_timeout=_PG_CONNECT_TIMEOUT,
                    # Auto-reconnect on dropped sockets between requests.
                    autocommit=False,
                )
                return Connection(native)
            except Exception as e:  # noqa: BLE001 — psycopg raises many subclasses
                last_err = e
                # Quick backoff: 0.4s, 0.8s, 1.6s. Neon usually wakes within 1–2s.
                time.sleep(0.4 * (2 ** attempt))
        # Out of retries — propagate so the Flask error handler can render a 500
        # page with a useful message instead of hanging.
        raise last_err  # type: ignore[misc]
    native = sqlite3.connect(DB_PATH, timeout=10)
    native.row_factory = sqlite3.Row
    native.execute("PRAGMA foreign_keys = ON")
    return Connection(native)


# ---------------------------------------------------------------------------
# Schema — written in SQLite-style; the translator handles the Postgres dialect
# ---------------------------------------------------------------------------

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
    kind TEXT NOT NULL DEFAULT 'exam',
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
    quiz_password TEXT,
    anti_paste INTEGER DEFAULT 0,
    anti_rightclick INTEGER DEFAULT 0,
    block_selection INTEGER DEFAULT 0,
    require_fullscreen INTEGER DEFAULT 0,
    detect_tab_switch INTEGER DEFAULT 0,
    violation_limit INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    text TEXT NOT NULL,
    options TEXT,
    correct_answers TEXT,
    points INTEGER DEFAULT 1,
    position INTEGER DEFAULT 0,
    explanation TEXT,
    time_limit_seconds INTEGER DEFAULT 0
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
    live_session_id INTEGER,
    needs_grading INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    answer TEXT,
    is_correct INTEGER,
    points_earned REAL DEFAULT 0,
    graded INTEGER DEFAULT 1,
    feedback TEXT
);

CREATE TABLE IF NOT EXISTS violations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    details TEXT,
    created_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_violations_attempt ON violations(attempt_id);

CREATE TABLE IF NOT EXISTS live_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
    join_code TEXT UNIQUE NOT NULL,
    status TEXT DEFAULT 'waiting',
    current_question_index INTEGER DEFAULT -1,
    started_at INTEGER NOT NULL,
    ended_at INTEGER
);

CREATE INDEX IF NOT EXISTS idx_questions_quiz ON questions(quiz_id, position);
CREATE INDEX IF NOT EXISTS idx_attempts_quiz ON attempts(quiz_id);
CREATE INDEX IF NOT EXISTS idx_answers_attempt ON answers(attempt_id);
CREATE INDEX IF NOT EXISTS idx_live_quiz ON live_sessions(quiz_id);
"""


def init_db() -> None:
    """Create all tables and run additive migrations. Safe to call repeatedly."""
    conn = get_conn()
    try:
        conn.executescript(SCHEMA)
        # Idempotent column adds (so an older DB upgrades to the latest schema in place)
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
        _ensure_column(conn, "users", "failed_login_count", "INTEGER DEFAULT 0")
        _ensure_column(conn, "users", "locked_until", "INTEGER")
        _ensure_column(conn, "quizzes", "ip_allowlist", "TEXT")
        _ensure_column(conn, "quizzes", "detect_devtools", "INTEGER DEFAULT 0")
        _ensure_column(conn, "users", "plan", "TEXT DEFAULT 'free'")
        _ensure_column(conn, "users", "plan_expires_at", "INTEGER")
        _ensure_column(conn, "quizzes", "camera_proctor", "INTEGER DEFAULT 0")
        _ensure_column(conn, "quizzes", "proctor_snapshot_interval", "INTEGER DEFAULT 30")
        _ensure_column(conn, "questions", "image_url", "TEXT")
        _ensure_column(conn, "questions", "is_required", "INTEGER DEFAULT 1")
        conn.execute(
            """CREATE TABLE IF NOT EXISTS proctor_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id INTEGER NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
                captured_at INTEGER NOT NULL,
                kind TEXT,
                notes TEXT,
                image_data TEXT
            )"""
        )
        conn.execute("CREATE INDEX IF NOT EXISTS idx_proctor_attempt ON proctor_snapshots(attempt_id)")
        # Question bank — reusable questions per teacher
        conn.execute(
            """CREATE TABLE IF NOT EXISTS question_bank (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type TEXT NOT NULL,
                text TEXT NOT NULL,
                options TEXT,
                correct_answers TEXT,
                points INTEGER DEFAULT 1,
                explanation TEXT,
                time_limit_seconds INTEGER DEFAULT 0,
                category TEXT,
                tags TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )"""
        )
        conn.execute("CREATE INDEX IF NOT EXISTS idx_bank_user ON question_bank(user_id)")
        conn.execute(
            """CREATE TABLE IF NOT EXISTS certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id INTEGER NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
                quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
                serial TEXT UNIQUE NOT NULL,
                recipient_name TEXT,
                score REAL,
                max_score REAL,
                percentage REAL,
                issued_at INTEGER NOT NULL
            )"""
        )
        conn.execute(
            """CREATE TABLE IF NOT EXISTS password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token TEXT UNIQUE NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                used_at INTEGER
            )"""
        )
        # Site-wide feature flags / key-value settings (super-admin controls)
        conn.execute(
            """CREATE TABLE IF NOT EXISTS site_settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )"""
        )
        conn.commit()
    finally:
        conn.close()


def _ensure_column(conn: Connection, table: str, column: str, type_decl: str) -> None:
    if IS_POSTGRES:
        # Postgres supports ADD COLUMN IF NOT EXISTS natively.
        try:
            conn.execute(
                f"ALTER TABLE {table} ADD COLUMN IF NOT EXISTS {column} {type_decl}"
            )
        except Exception:
            pass
        return
    # SQLite: introspect then add if missing.
    cols = {r["name"] for r in conn.execute(f"PRAGMA table_info({table})").fetchall()}
    if column not in cols:
        conn.execute(f"ALTER TABLE {table} ADD COLUMN {column} {type_decl}")


# ---------------------------------------------------------------------------
# Misc helpers used across the app
# ---------------------------------------------------------------------------

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
