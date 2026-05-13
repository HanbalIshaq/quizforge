"""QuizForge — self-hostable quiz + live-poll platform."""
import hashlib
import json
import os
import random
import threading
from collections import defaultdict
from datetime import datetime
from functools import wraps

import bcrypt
from dotenv import load_dotenv
from flask import (
    Flask, abort, flash, g, jsonify, redirect, render_template, request,
    send_file, session, url_for, Response,
)
from flask_socketio import SocketIO, emit, join_room, leave_room

import db
import exporters
import grading
import importers

load_dotenv()
app = Flask(__name__)
app.config["SECRET_KEY"] = os.environ.get("SECRET_KEY", "dev-secret-change-me")
app.config["MAX_CONTENT_LENGTH"] = 16 * 1024 * 1024  # 16 MB uploads

socketio = SocketIO(app, async_mode="threading", cors_allowed_origins="*")
db.init_db()

# In-memory live session state: { session_id: { participants, answers, current_q } }
LIVE_STATE: dict[int, dict] = defaultdict(lambda: {
    "participants": {},          # sid -> {"name": str, "score": 0}
    "answers_per_q": defaultdict(dict),  # qid -> {sid: answer}
    "revealed": set(),           # qids revealed
    "host_sids": set(),          # socket sids authorized as host of this session
    "owner_uid": None,           # the teacher who owns this session
    "quiz_kind": "exam",         # exam | poll | survey
})
LIVE_LOCK = threading.Lock()

REQUIRE_APPROVAL = os.environ.get("REQUIRE_APPROVAL", "").lower() in ("1", "true", "yes")

# Maps a socket sid to the live session_id it joined, for routing answers / cleanup
SID_TO_SESSION: dict[str, int] = {}


# ---------- helpers ----------

def current_user():
    uid = session.get("uid")
    if not uid:
        return None
    conn = db.get_conn()
    try:
        row = conn.execute(
            "SELECT id, email, name, is_super_admin, is_approved, is_suspended FROM users WHERE id=?",
            (uid,),
        ).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def login_required(fn):
    @wraps(fn)
    def wrapper(*args, **kwargs):
        if not session.get("uid"):
            return redirect(url_for("login", next=request.path))
        return fn(*args, **kwargs)
    return wrapper


def super_admin_required(fn):
    @wraps(fn)
    def wrapper(*args, **kwargs):
        u = current_user()
        if not u or not u.get("is_super_admin"):
            abort(403)
        return fn(*args, **kwargs)
    return wrapper


def owned_quiz_or_404(conn, quiz_id, uid):
    q = conn.execute(
        "SELECT * FROM quizzes WHERE id=? AND user_id=?", (quiz_id, uid)
    ).fetchone()
    if not q:
        abort(404)
    return q


def fmt_ts(ts):
    if not ts:
        return ""
    return datetime.fromtimestamp(int(ts)).strftime("%Y-%m-%d %H:%M")


ASSET_VERSION = str(int(db.now_ts()))


@app.context_processor
def inject_globals():
    return {
        "user": current_user(),
        "fmt_ts": fmt_ts,
        "QUESTION_TYPES": grading.QUESTION_TYPES,
        "asset_v": ASSET_VERSION,
    }


# ---------- auth ----------

@app.route("/")
def home():
    if session.get("uid"):
        return redirect(url_for("admin_dashboard"))
    return render_template("home.html")


@app.route("/register", methods=["GET", "POST"])
def register():
    if request.method == "POST":
        email = (request.form.get("email") or "").strip().lower()
        password = request.form.get("password") or ""
        name = (request.form.get("name") or "").strip()
        if not email or not password or len(password) < 6:
            flash("Email and password (min 6 chars) required.", "error")
            return render_template("register.html", form=request.form)
        conn = db.get_conn()
        try:
            existing = conn.execute("SELECT 1 FROM users WHERE email=?", (email,)).fetchone()
            if existing:
                flash("Email already registered.", "error")
                return render_template("register.html", form=request.form)
            ph = bcrypt.hashpw(password.encode(), bcrypt.gensalt()).decode()
            user_count = conn.execute("SELECT COUNT(*) AS c FROM users").fetchone()["c"]
            is_first = user_count == 0
            is_super = 1 if is_first else 0
            is_approved = 1 if (is_first or not REQUIRE_APPROVAL) else 0
            cur = conn.execute(
                """INSERT INTO users(email, password_hash, name, created_at,
                                     is_super_admin, is_approved)
                   VALUES(?,?,?,?,?,?)""",
                (email, ph, name, db.now_ts(), is_super, is_approved),
            )
            conn.commit()
            if is_approved:
                session["uid"] = cur.lastrowid
                if is_first:
                    flash("Welcome! You are the site Super Admin.", "success")
                return redirect(url_for("admin_dashboard"))
            flash("Account created — pending admin approval. You'll be able to sign in once approved.", "success")
            return redirect(url_for("login"))
        finally:
            conn.close()
    return render_template("register.html", form={})


@app.route("/login", methods=["GET", "POST"])
def login():
    if request.method == "POST":
        email = (request.form.get("email") or "").strip().lower()
        password = request.form.get("password") or ""
        conn = db.get_conn()
        try:
            row = conn.execute("SELECT * FROM users WHERE email=?", (email,)).fetchone()
            if not row or not bcrypt.checkpw(password.encode(), row["password_hash"].encode()):
                flash("Invalid email or password.", "error")
                return render_template("login.html", form=request.form)
            if row["is_suspended"]:
                flash("This account is suspended. Contact the site administrator.", "error")
                return render_template("login.html", form=request.form)
            if not row["is_approved"]:
                flash("Your account is awaiting administrator approval.", "error")
                return render_template("login.html", form=request.form)
            session["uid"] = row["id"]
            conn.execute("UPDATE users SET last_login_at=? WHERE id=?", (db.now_ts(), row["id"]))
            conn.commit()
            nxt = request.args.get("next") or url_for("admin_dashboard")
            return redirect(nxt)
        finally:
            conn.close()
    return render_template("login.html", form={})


@app.route("/logout")
def logout():
    session.clear()
    return redirect(url_for("home"))


# ---------- super-admin: site management ----------

@app.route("/admin/site")
@login_required
@super_admin_required
def site_dashboard():
    conn = db.get_conn()
    try:
        stats = {
            "users": conn.execute("SELECT COUNT(*) AS c FROM users").fetchone()["c"],
            "users_pending": conn.execute("SELECT COUNT(*) AS c FROM users WHERE is_approved=0").fetchone()["c"],
            "users_suspended": conn.execute("SELECT COUNT(*) AS c FROM users WHERE is_suspended=1").fetchone()["c"],
            "quizzes": conn.execute("SELECT COUNT(*) AS c FROM quizzes").fetchone()["c"],
            "questions": conn.execute("SELECT COUNT(*) AS c FROM questions").fetchone()["c"],
            "attempts": conn.execute(
                "SELECT COUNT(*) AS c FROM attempts WHERE submitted_at IS NOT NULL"
            ).fetchone()["c"],
            "attempts_24h": conn.execute(
                "SELECT COUNT(*) AS c FROM attempts WHERE submitted_at >= ?",
                (db.now_ts() - 86400,),
            ).fetchone()["c"],
            "live_active": conn.execute(
                "SELECT COUNT(*) AS c FROM live_sessions WHERE status IN ('waiting','running')"
            ).fetchone()["c"],
            "live_total": conn.execute("SELECT COUNT(*) AS c FROM live_sessions").fetchone()["c"],
        }
        recent_users = [dict(r) for r in conn.execute(
            "SELECT id, email, name, created_at, last_login_at, is_super_admin, is_approved, is_suspended "
            "FROM users ORDER BY created_at DESC LIMIT 10"
        ).fetchall()]
        recent_quizzes = [dict(r) for r in conn.execute(
            """SELECT q.id, q.title, q.kind, q.share_code, q.created_at, u.email AS owner_email
               FROM quizzes q JOIN users u ON u.id=q.user_id
               ORDER BY q.created_at DESC LIMIT 10"""
        ).fetchall()]
        recent_attempts = [dict(r) for r in conn.execute(
            """SELECT a.id, a.student_name, a.student_email, a.percentage, a.submitted_at,
                      q.title AS quiz_title, u.email AS owner_email
               FROM attempts a
               JOIN quizzes q ON q.id=a.quiz_id
               JOIN users u ON u.id=q.user_id
               WHERE a.submitted_at IS NOT NULL
               ORDER BY a.submitted_at DESC LIMIT 15"""
        ).fetchall()]
        return render_template(
            "admin/site_dashboard.html",
            stats=stats,
            recent_users=recent_users,
            recent_quizzes=recent_quizzes,
            recent_attempts=recent_attempts,
            require_approval=REQUIRE_APPROVAL,
        )
    finally:
        conn.close()


@app.route("/admin/site/users")
@login_required
@super_admin_required
def site_users():
    conn = db.get_conn()
    try:
        users = [dict(r) for r in conn.execute(
            """SELECT u.*,
                      (SELECT COUNT(*) FROM quizzes WHERE user_id=u.id) AS n_q
               FROM users u
               ORDER BY u.created_at DESC"""
        ).fetchall()]
        return render_template("admin/site_users.html", users=users)
    finally:
        conn.close()


@app.route("/admin/site/users/<int:user_id>/<string:action>", methods=["POST"])
@login_required
@super_admin_required
def site_user_action(user_id, action):
    conn = db.get_conn()
    try:
        target = conn.execute("SELECT * FROM users WHERE id=?", (user_id,)).fetchone()
        if not target:
            abort(404)
        # Prevent locking ourselves out
        if user_id == session.get("uid") and action in ("suspend", "demote"):
            flash("You cannot perform this action on your own account.", "error")
            return redirect(url_for("site_users"))
        if action == "approve":
            conn.execute("UPDATE users SET is_approved=1 WHERE id=?", (user_id,))
        elif action == "suspend":
            conn.execute("UPDATE users SET is_suspended=1 WHERE id=?", (user_id,))
        elif action == "unsuspend":
            conn.execute("UPDATE users SET is_suspended=0 WHERE id=?", (user_id,))
        elif action == "promote":
            conn.execute("UPDATE users SET is_super_admin=1 WHERE id=?", (user_id,))
        elif action == "demote":
            conn.execute("UPDATE users SET is_super_admin=0 WHERE id=?", (user_id,))
        elif action == "delete":
            conn.execute("DELETE FROM users WHERE id=?", (user_id,))
        else:
            abort(400)
        conn.commit()
        flash(f"Action '{action}' applied to {target['email']}.", "success")
        return redirect(url_for("site_users"))
    finally:
        conn.close()


# ---------- admin: dashboard + CRUD ----------

@app.route("/admin")
@login_required
def admin_dashboard():
    conn = db.get_conn()
    try:
        rows = conn.execute(
            """SELECT q.*,
                      (SELECT COUNT(*) FROM questions WHERE quiz_id=q.id) AS n_q,
                      (SELECT COUNT(*) FROM attempts WHERE quiz_id=q.id AND submitted_at IS NOT NULL) AS n_a
               FROM quizzes q WHERE user_id=? ORDER BY q.updated_at DESC""",
            (session["uid"],),
        ).fetchall()
        quizzes = [dict(r) for r in rows]
        return render_template("admin/dashboard.html", quizzes=quizzes)
    finally:
        conn.close()


@app.route("/admin/quizzes/new", methods=["POST"])
@login_required
def quiz_new():
    title = (request.form.get("title") or "Untitled quiz").strip()
    kind = (request.form.get("kind") or "exam").strip()
    if kind not in ("exam", "poll", "survey"):
        kind = "exam"
    code = db.unique_code("quizzes", "share_code", 7)
    # Auto-defaults per kind so behavior is meaningfully different
    if kind == "exam":
        defaults = dict(require_name=1, require_email=0, show_correct_answers=1, randomize_questions=0)
    elif kind == "poll":
        defaults = dict(require_name=1, require_email=0, show_correct_answers=0, randomize_questions=0)
    else:  # survey
        defaults = dict(require_name=0, require_email=0, show_correct_answers=0, randomize_questions=0)
    conn = db.get_conn()
    try:
        cur = conn.execute(
            """INSERT INTO quizzes(user_id, title, share_code, kind, created_at, updated_at,
                                   require_name, require_email, show_correct_answers, randomize_questions)
               VALUES(?,?,?,?,?,?,?,?,?,?)""",
            (
                session["uid"], title, code, kind, db.now_ts(), db.now_ts(),
                defaults["require_name"], defaults["require_email"],
                defaults["show_correct_answers"], defaults["randomize_questions"],
            ),
        )
        conn.commit()
        return redirect(url_for("quiz_edit", quiz_id=cur.lastrowid))
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>", methods=["GET"])
@login_required
def quiz_edit(quiz_id):
    conn = db.get_conn()
    try:
        quiz = owned_quiz_or_404(conn, quiz_id, session["uid"])
        questions = conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (quiz_id,)
        ).fetchall()
        qs = []
        for q in questions:
            d = dict(q)
            d["options"] = json.loads(d["options"] or "[]")
            d["correct_answers"] = json.loads(d["correct_answers"] or "[]")
            qs.append(d)
        return render_template("admin/quiz_form.html", quiz=dict(quiz), questions=qs)
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>/settings", methods=["POST"])
@login_required
def quiz_update_settings(quiz_id):
    f = request.form
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
        conn.execute(
            """UPDATE quizzes SET
                title=?, description=?, kind=?, time_limit_seconds=?,
                randomize_questions=?, randomize_options=?, show_correct_answers=?,
                require_name=?, require_email=?, max_attempts=?, pass_mark=?,
                is_published=?, paginated=?, updated_at=?
               WHERE id=?""",
            (
                (f.get("title") or "Untitled").strip(),
                f.get("description") or "",
                f.get("kind") or "exam",
                int(f.get("time_limit_seconds") or 0),
                1 if f.get("randomize_questions") else 0,
                1 if f.get("randomize_options") else 0,
                1 if f.get("show_correct_answers") else 0,
                1 if f.get("require_name") else 0,
                1 if f.get("require_email") else 0,
                int(f.get("max_attempts") or 0),
                int(f.get("pass_mark") or 0),
                1 if f.get("is_published") else 0,
                1 if f.get("paginated") else 0,
                db.now_ts(),
                quiz_id,
            ),
        )
        conn.commit()
        flash("Quiz settings saved.", "success")
        return redirect(url_for("quiz_edit", quiz_id=quiz_id))
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>/delete", methods=["POST"])
@login_required
def quiz_delete(quiz_id):
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
        conn.execute("DELETE FROM quizzes WHERE id=?", (quiz_id,))
        conn.commit()
        return redirect(url_for("admin_dashboard"))
    finally:
        conn.close()


# ---------- admin: questions ----------

@app.route("/admin/quizzes/<int:quiz_id>/questions", methods=["POST"])
@login_required
def question_save(quiz_id):
    payload = request.get_json(force=True)
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
        qid = payload.get("id")
        qtype = payload.get("type") or "mcq_single"
        text = (payload.get("text") or "").strip()
        options = payload.get("options") or []
        correct = payload.get("correct_answers") or []
        points = int(payload.get("points") or 1)
        explanation = payload.get("explanation") or ""
        time_limit = max(0, int(payload.get("time_limit_seconds") or 0))
        if not text:
            return jsonify({"ok": False, "error": "Question text required"}), 400
        if qid:
            conn.execute(
                """UPDATE questions SET type=?, text=?, options=?, correct_answers=?, points=?, explanation=?, time_limit_seconds=?
                   WHERE id=? AND quiz_id=?""",
                (qtype, text, json.dumps(options), json.dumps(correct), points, explanation, time_limit, qid, quiz_id),
            )
        else:
            pos_row = conn.execute(
                "SELECT COALESCE(MAX(position), -1)+1 AS p FROM questions WHERE quiz_id=?", (quiz_id,)
            ).fetchone()
            cur = conn.execute(
                """INSERT INTO questions(quiz_id, type, text, options, correct_answers, points, position, explanation, time_limit_seconds)
                   VALUES(?,?,?,?,?,?,?,?,?)""",
                (quiz_id, qtype, text, json.dumps(options), json.dumps(correct), points, pos_row["p"], explanation, time_limit),
            )
            qid = cur.lastrowid
        conn.execute("UPDATE quizzes SET updated_at=? WHERE id=?", (db.now_ts(), quiz_id))
        conn.commit()
        return jsonify({"ok": True, "id": qid})
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>/questions/<int:qid>/delete", methods=["POST"])
@login_required
def question_delete(quiz_id, qid):
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
        conn.execute("DELETE FROM questions WHERE id=? AND quiz_id=?", (qid, quiz_id))
        conn.commit()
        return jsonify({"ok": True})
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>/reorder", methods=["POST"])
@login_required
def question_reorder(quiz_id):
    order = request.get_json(force=True).get("order") or []
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
        for idx, qid in enumerate(order):
            conn.execute(
                "UPDATE questions SET position=? WHERE id=? AND quiz_id=?",
                (idx, int(qid), quiz_id),
            )
        conn.commit()
        return jsonify({"ok": True})
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>/apply-time", methods=["POST"])
@login_required
def quiz_apply_time(quiz_id):
    """Bulk-set time_limit_seconds on every question in this quiz."""
    secs = max(0, int(request.form.get("seconds") or 0))
    only_unset = request.form.get("only_unset") == "on"
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
        if only_unset:
            cur = conn.execute(
                "UPDATE questions SET time_limit_seconds=? WHERE quiz_id=? AND (time_limit_seconds IS NULL OR time_limit_seconds=0)",
                (secs, quiz_id),
            )
        else:
            cur = conn.execute(
                "UPDATE questions SET time_limit_seconds=? WHERE quiz_id=?",
                (secs, quiz_id),
            )
        conn.execute("UPDATE quizzes SET updated_at=? WHERE id=?", (db.now_ts(), quiz_id))
        conn.commit()
        flash(f"Set {secs}s time limit on {cur.rowcount} question(s).", "success")
        return redirect(url_for("quiz_edit", quiz_id=quiz_id))
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>/import", methods=["POST"])
@login_required
def quiz_import(quiz_id):
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
        fmt = request.form.get("format") or "text"
        raw_text = request.form.get("content") or ""
        file = request.files.get("file")
        questions = []
        if file and file.filename:
            ext = file.filename.rsplit(".", 1)[-1].lower()
            if ext == "docx":
                tmp_path = os.path.join("static", "uploads", f"imp_{db.now_ts()}.docx")
                file.save(tmp_path)
                try:
                    questions = importers.parse_docx(tmp_path)
                finally:
                    try:
                        os.remove(tmp_path)
                    except OSError:
                        pass
            elif ext == "csv":
                questions = importers.parse_csv(file.read().decode("utf-8", errors="replace"))
            elif ext == "txt":
                questions = importers.parse_text(file.read().decode("utf-8", errors="replace"))
            else:
                flash("Unsupported file type. Use .docx, .csv, or .txt", "error")
                return redirect(url_for("quiz_edit", quiz_id=quiz_id))
        elif raw_text.strip():
            if fmt == "csv":
                questions = importers.parse_csv(raw_text)
            else:
                questions = importers.parse_text(raw_text)
        if not questions:
            flash("No questions could be parsed from the input.", "error")
            return redirect(url_for("quiz_edit", quiz_id=quiz_id))
        pos_row = conn.execute(
            "SELECT COALESCE(MAX(position), -1)+1 AS p FROM questions WHERE quiz_id=?", (quiz_id,)
        ).fetchone()
        start_pos = pos_row["p"]
        for i, q in enumerate(questions):
            conn.execute(
                """INSERT INTO questions(quiz_id, type, text, options, correct_answers, points, position, explanation)
                   VALUES(?,?,?,?,?,?,?,?)""",
                (
                    quiz_id, q["type"], q["text"],
                    json.dumps(q.get("options") or []),
                    json.dumps(q.get("correct_answers") or []),
                    int(q.get("points") or 1),
                    start_pos + i,
                    q.get("explanation") or "",
                ),
            )
        conn.execute("UPDATE quizzes SET updated_at=? WHERE id=?", (db.now_ts(), quiz_id))
        conn.commit()
        flash(f"Imported {len(questions)} questions.", "success")
        return redirect(url_for("quiz_edit", quiz_id=quiz_id))
    finally:
        conn.close()


# ---------- admin: results ----------

@app.route("/admin/quizzes/<int:quiz_id>/results")
@login_required
def quiz_results(quiz_id):
    conn = db.get_conn()
    try:
        quiz = owned_quiz_or_404(conn, quiz_id, session["uid"])
        # Gather every attempt that has activity (submitted OR has ≥1 answer)
        raw_attempts = [dict(r) for r in conn.execute(
            """SELECT a.* FROM attempts a
               WHERE a.quiz_id=?
                 AND (a.submitted_at IS NOT NULL
                      OR EXISTS (SELECT 1 FROM answers WHERE attempt_id=a.id))
               ORDER BY a.submitted_at DESC NULLS LAST, a.started_at DESC""",
            (quiz_id,),
        ).fetchall()]
        # Show: all submitted attempts + only the LATEST partial per (name+email) when the
        # student has not yet submitted (avoids 3 rows for one student who refreshed)
        def _stu_key(a):
            return ((a.get("student_name") or "").strip().lower(),
                    (a.get("student_email") or "").strip().lower())
        submitted_keys = {_stu_key(a) for a in raw_attempts if a["submitted_at"]}
        seen_partial_keys: set = set()
        attempts = []
        for a in raw_attempts:
            if a["submitted_at"]:
                attempts.append(a)
                continue
            key = _stu_key(a)
            # Drop partials for students who already submitted
            if key in submitted_keys:
                continue
            # Drop older partials for same student (raw_attempts is started_at DESC)
            if key in seen_partial_keys:
                continue
            seen_partial_keys.add(key)
            attempts.append(a)
        for a in attempts:
            a["started_at_fmt"] = fmt_ts(a["started_at"])
            a["submitted_at_fmt"] = fmt_ts(a["submitted_at"])
            a["is_partial"] = a["submitted_at"] is None
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (quiz_id,)
        ).fetchall()]
        # Per-question stats
        stats = []
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
            ans_rows = conn.execute(
                "SELECT answer, is_correct FROM answers WHERE question_id=?", (q["id"],)
            ).fetchall()
            counts = defaultdict(int)
            correct = 0
            total = 0
            text_answers = []
            for r in ans_rows:
                try:
                    val = json.loads(r["answer"] or "null")
                except Exception:
                    val = None
                if r["is_correct"] == 1:
                    correct += 1
                if val is not None:
                    total += 1
                if q["type"] in ("mcq_single", "true_false"):
                    if isinstance(val, int):
                        counts[val] += 1
                elif q["type"] == "mcq_multi":
                    if isinstance(val, list):
                        for v in val:
                            counts[v] += 1
                elif q["type"] == "rating":
                    if isinstance(val, (int, float)):
                        counts[int(val)] += 1
                elif q["type"] in ("short_answer", "fill_blank", "long_answer", "open_ended", "word_cloud"):
                    if isinstance(val, str) and val.strip():
                        text_answers.append(val)
            stats.append({
                "q": q,
                "counts": dict(counts),
                "correct": correct,
                "total": total,
                "text_answers": text_answers,
            })
        return render_template("admin/quiz_results.html", quiz=dict(quiz), attempts=attempts, stats=stats)
    finally:
        conn.close()


def _gather_results_for_export(quiz_id):
    conn = db.get_conn()
    try:
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (quiz_id,)
        ).fetchall()]
        attempts = [dict(r) for r in conn.execute(
            "SELECT * FROM attempts WHERE quiz_id=? AND submitted_at IS NOT NULL ORDER BY submitted_at DESC",
            (quiz_id,),
        ).fetchall()]
        for a in attempts:
            a["started_at_fmt"] = fmt_ts(a["started_at"])
            a["submitted_at_fmt"] = fmt_ts(a["submitted_at"])
            rows = conn.execute(
                "SELECT * FROM answers WHERE attempt_id=?", (a["id"],)
            ).fetchall()
            a["answers_by_qid"] = {r["question_id"]: dict(r) for r in rows}
        return questions, attempts
    finally:
        conn.close()


@app.route("/admin/quizzes/<int:quiz_id>/export.csv")
@login_required
def export_csv(quiz_id):
    conn = db.get_conn()
    try:
        owned_quiz_or_404(conn, quiz_id, session["uid"])
    finally:
        conn.close()
    questions, attempts = _gather_results_for_export(quiz_id)
    csv_data = exporters.attempts_to_csv(attempts, questions)
    return Response(
        csv_data,
        mimetype="text/csv",
        headers={"Content-Disposition": f"attachment; filename=quiz_{quiz_id}_results.csv"},
    )


@app.route("/admin/quizzes/<int:quiz_id>/export.xlsx")
@login_required
def export_xlsx(quiz_id):
    conn = db.get_conn()
    try:
        quiz = owned_quiz_or_404(conn, quiz_id, session["uid"])
    finally:
        conn.close()
    questions, attempts = _gather_results_for_export(quiz_id)
    data = exporters.attempts_to_xlsx(attempts, questions, quiz["title"])
    return Response(
        data,
        mimetype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": f"attachment; filename=quiz_{quiz_id}_results.xlsx"},
    )


@app.route("/admin/quizzes/<int:quiz_id>/attempts/<int:aid>", methods=["GET", "POST"])
@login_required
def attempt_detail(quiz_id, aid):
    conn = db.get_conn()
    try:
        quiz = owned_quiz_or_404(conn, quiz_id, session["uid"])
        attempt = conn.execute(
            "SELECT * FROM attempts WHERE id=? AND quiz_id=?", (aid, quiz_id)
        ).fetchone()
        if not attempt:
            abort(404)
        if request.method == "POST":
            # Save manual grading
            total = 0.0
            for key, val in request.form.items():
                if key.startswith("pts_"):
                    ans_id = int(key[4:])
                    pts = float(val or 0)
                    conn.execute(
                        "UPDATE answers SET points_earned=?, graded=1, is_correct=CASE WHEN ?>0 THEN 1 ELSE 0 END WHERE id=?",
                        (pts, pts, ans_id),
                    )
                if key.startswith("fb_"):
                    ans_id = int(key[3:])
                    conn.execute("UPDATE answers SET feedback=? WHERE id=?", (val, ans_id))
            agg = conn.execute(
                "SELECT COALESCE(SUM(points_earned),0) AS s FROM answers WHERE attempt_id=?", (aid,)
            ).fetchone()
            total = agg["s"]
            max_score = attempt["max_score"] or 1
            pct = (total / max_score * 100) if max_score else 0
            conn.execute(
                "UPDATE attempts SET score=?, percentage=?, needs_grading=0 WHERE id=?",
                (total, pct, aid),
            )
            conn.commit()
            flash("Saved grading.", "success")
            return redirect(url_for("attempt_detail", quiz_id=quiz_id, aid=aid))
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (quiz_id,)
        ).fetchall()]
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
        answers = conn.execute(
            "SELECT * FROM answers WHERE attempt_id=?", (aid,)
        ).fetchall()
        ans_by_qid = {}
        for a in answers:
            d = dict(a)
            try:
                d["value"] = json.loads(d["answer"] or "null")
            except Exception:
                d["value"] = d["answer"]
            ans_by_qid[d["question_id"]] = d
        return render_template(
            "admin/attempt_detail.html",
            quiz=dict(quiz), attempt=dict(attempt),
            questions=questions, answers=ans_by_qid,
        )
    finally:
        conn.close()


# ---------- admin: live session ----------

@app.route("/admin/quizzes/<int:quiz_id>/live/start", methods=["POST"])
@login_required
def live_start(quiz_id):
    conn = db.get_conn()
    try:
        quiz = owned_quiz_or_404(conn, quiz_id, session["uid"])
        n_q = conn.execute("SELECT COUNT(*) AS c FROM questions WHERE quiz_id=?", (quiz_id,)).fetchone()["c"]
        if n_q == 0:
            flash("Add at least one question before starting a live session.", "error")
            return redirect(url_for("quiz_edit", quiz_id=quiz_id))
        join_code = db.unique_code("live_sessions", "join_code", 6)
        cur = conn.execute(
            """INSERT INTO live_sessions(quiz_id, join_code, status, current_question_index, started_at)
               VALUES(?,?,?,?,?)""",
            (quiz_id, join_code, "waiting", -1, db.now_ts()),
        )
        conn.commit()
        return redirect(url_for("live_host", session_id=cur.lastrowid))
    finally:
        conn.close()


@app.route("/admin/live/<int:session_id>")
@login_required
def live_host(session_id):
    conn = db.get_conn()
    try:
        s = conn.execute("SELECT * FROM live_sessions WHERE id=?", (session_id,)).fetchone()
        if not s:
            abort(404)
        quiz = owned_quiz_or_404(conn, s["quiz_id"], session["uid"])
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (s["quiz_id"],)
        ).fetchall()]
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
        return render_template(
            "admin/live_host.html",
            session=dict(s), quiz=dict(quiz), questions=questions,
        )
    finally:
        conn.close()


# ---------- student-facing ----------

@app.route("/j", methods=["GET", "POST"])
def join():
    if request.method == "POST":
        code = (request.form.get("code") or "").strip().upper()
        if not code:
            flash("Enter a code.", "error")
            return render_template("student/join.html")
        # Check live first
        conn = db.get_conn()
        try:
            live = conn.execute(
                "SELECT * FROM live_sessions WHERE join_code=? AND status!='finished'",
                (code,),
            ).fetchone()
            if live:
                return redirect(url_for("live_student", join_code=code))
            quiz = conn.execute("SELECT * FROM quizzes WHERE share_code=?", (code,)).fetchone()
            if quiz:
                return redirect(url_for("take_quiz", code=code))
            flash("No quiz or live session found with that code.", "error")
            return render_template("student/join.html")
        finally:
            conn.close()
    return render_template("student/join.html")


def _hash_sort(items, seed_prefix: str, key_fn=None):
    """Deterministic shuffle: sort items by hash(seed_prefix + key). Avoids small-list shuffle collisions."""
    def sort_key(item):
        k = str(key_fn(item)) if key_fn else str(item)
        return hashlib.sha1(f"{seed_prefix}:{k}".encode()).digest()
    return sorted(items, key=sort_key)


def _shuffle_for_attempt(quiz, questions, attempt_id):
    """Apply randomization deterministically based on attempt_id so refresh shows same order."""
    seed_prefix = f"qz{quiz['id']}-att{attempt_id}"
    if quiz["randomize_questions"]:
        questions = _hash_sort(questions, seed_prefix + "-q", key_fn=lambda q: q["id"])
    if quiz["randomize_options"]:
        for q in questions:
            if q["type"] in ("mcq_single", "mcq_multi", "poll") and q["options"]:
                pairs = list(enumerate(q["options"]))  # [(orig_idx, opt), ...]
                pairs = _hash_sort(pairs, seed_prefix + f"-o-{q['id']}", key_fn=lambda p: p[0])
                q["options_with_idx"] = pairs
            else:
                q["options_with_idx"] = list(enumerate(q["options"] or []))
    else:
        for q in questions:
            q["options_with_idx"] = list(enumerate(q["options"] or []))
    return questions


def _get_or_create_draft(conn, quiz):
    """Get an existing unsubmitted draft attempt for this browser, or create one."""
    quiz_id = quiz["id"]
    draft_key = f"draft_{quiz_id}"
    draft_id = session.get(draft_key)
    if draft_id:
        row = conn.execute(
            "SELECT * FROM attempts WHERE id=? AND quiz_id=? AND submitted_at IS NULL",
            (draft_id, quiz_id),
        ).fetchone()
        if row:
            return row["id"]
    cur = conn.execute(
        """INSERT INTO attempts(quiz_id, student_name, started_at, ip_address)
           VALUES(?,?,?,?)""",
        (quiz_id, "", db.now_ts(), request.remote_addr),
    )
    conn.commit()
    session[draft_key] = cur.lastrowid
    return cur.lastrowid


@app.route("/q/<code>", methods=["GET", "POST"])
def take_quiz(code):
    conn = db.get_conn()
    try:
        quiz = conn.execute("SELECT * FROM quizzes WHERE share_code=?", (code,)).fetchone()
        if not quiz or not quiz["is_published"]:
            abort(404)
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (quiz["id"],)
        ).fetchall()]
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
        if request.method == "POST":
            is_scored = quiz["kind"] == "exam"
            is_anonymous = quiz["kind"] == "survey"
            student_name = (request.form.get("student_name") or "").strip() or "Anonymous"
            student_email = (request.form.get("student_email") or "").strip()
            now = db.now_ts()
            # Finalize the existing draft if it exists, otherwise create a fresh attempt
            attempt_id = _get_or_create_draft(conn, quiz)
            conn.execute(
                "UPDATE attempts SET student_name=?, student_email=?, submitted_at=?, ip_address=? WHERE id=?",
                (student_name, student_email, now, request.remote_addr, attempt_id),
            )
            # Replace answers (the auto-save may have left old data; rebuild from final submission)
            conn.execute("DELETE FROM answers WHERE attempt_id=?", (attempt_id,))
            total_pts = 0.0
            max_pts = 0.0
            needs_grading = 0
            for q in questions:
                max_pts += float(q["points"] or 1)
                raw = request.form.getlist(f"q_{q['id']}")
                value = _parse_submitted(q["type"], raw)
                if is_scored:
                    is_correct, pts, manual = grading.grade_answer(q, value)
                else:
                    is_correct, pts, manual = (None, 0.0, False)
                if manual:
                    needs_grading = 1
                if is_correct:
                    total_pts += pts
                conn.execute(
                    """INSERT INTO answers(attempt_id, question_id, answer, is_correct, points_earned, graded)
                       VALUES(?,?,?,?,?,?)""",
                    (
                        attempt_id, q["id"], json.dumps(value),
                        None if is_correct is None else (1 if is_correct else 0),
                        pts,
                        0 if manual else 1,
                    ),
                )
            if is_scored:
                pct = (total_pts / max_pts * 100) if max_pts else 0
                conn.execute(
                    "UPDATE attempts SET score=?, max_score=?, percentage=?, needs_grading=? WHERE id=?",
                    (total_pts, max_pts, pct, needs_grading, attempt_id),
                )
            else:
                conn.execute(
                    "UPDATE attempts SET score=0, max_score=0, percentage=0, needs_grading=0 WHERE id=?",
                    (attempt_id,),
                )
            # Clear the draft cookie so a NEW attempt starts a new draft
            session.pop(f"draft_{quiz['id']}", None)
            conn.commit()
            return redirect(url_for("quiz_result", code=code, attempt_id=attempt_id))
        # GET — create / reuse a draft, load partial answers, randomize for this attempt
        attempt_id = _get_or_create_draft(conn, dict(quiz))
        partial_rows = conn.execute(
            "SELECT question_id, answer FROM answers WHERE attempt_id=?", (attempt_id,)
        ).fetchall()
        partial_answers = {}
        for r in partial_rows:
            try:
                partial_answers[r["question_id"]] = json.loads(r["answer"] or "null")
            except Exception:
                partial_answers[r["question_id"]] = r["answer"]
        # Apply randomization (deterministic per attempt_id)
        questions = _shuffle_for_attempt(dict(quiz), questions, attempt_id)
        # Pre-fill name/email from existing draft if available
        draft_row = conn.execute(
            "SELECT student_name, student_email FROM attempts WHERE id=?", (attempt_id,)
        ).fetchone()
        prefill = {"student_name": draft_row["student_name"] or "", "student_email": draft_row["student_email"] or ""}
        return render_template(
            "student/quiz.html",
            quiz=dict(quiz),
            questions=questions,
            attempt_id=attempt_id,
            partial_answers=partial_answers,
            prefill=prefill,
        )
    finally:
        conn.close()


@app.route("/q/<code>/save", methods=["POST"])
def quiz_save_draft(code):
    """Auto-save endpoint — accepts partial answers and updates the draft attempt."""
    payload = request.get_json(force=True, silent=True) or {}
    conn = db.get_conn()
    try:
        quiz = conn.execute("SELECT * FROM quizzes WHERE share_code=?", (code,)).fetchone()
        if not quiz or not quiz["is_published"]:
            return jsonify({"ok": False, "error": "not found"}), 404
        attempt_id = int(payload.get("attempt_id") or 0)
        # Verify attempt belongs to this quiz and is still a draft
        a = conn.execute(
            "SELECT * FROM attempts WHERE id=? AND quiz_id=? AND submitted_at IS NULL",
            (attempt_id, quiz["id"]),
        ).fetchone()
        if not a:
            # Create a fresh draft on demand
            attempt_id = _get_or_create_draft(conn, dict(quiz))
        if "student_name" in payload or "student_email" in payload:
            conn.execute(
                "UPDATE attempts SET student_name=COALESCE(?, student_name), student_email=COALESCE(?, student_email) WHERE id=?",
                (payload.get("student_name"), payload.get("student_email"), attempt_id),
            )
        # Upsert answers
        is_scored = quiz["kind"] == "exam"
        for qid_str, val in (payload.get("answers") or {}).items():
            try:
                qid = int(qid_str)
            except (TypeError, ValueError):
                continue
            q_row = conn.execute(
                "SELECT * FROM questions WHERE id=? AND quiz_id=?", (qid, quiz["id"])
            ).fetchone()
            if not q_row:
                continue
            q = dict(q_row)
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
            if is_scored:
                is_correct, pts, manual = grading.grade_answer(q, val)
            else:
                is_correct, pts, manual = (None, 0.0, False)
            conn.execute("DELETE FROM answers WHERE attempt_id=? AND question_id=?", (attempt_id, qid))
            conn.execute(
                """INSERT INTO answers(attempt_id, question_id, answer, is_correct, points_earned, graded)
                   VALUES(?,?,?,?,?,?)""",
                (
                    attempt_id, qid, json.dumps(val),
                    None if is_correct is None else (1 if is_correct else 0),
                    pts,
                    0 if manual else 1,
                ),
            )
        # Roll up current score so partial attempts show real progress in Results
        earned = conn.execute(
            "SELECT COALESCE(SUM(points_earned), 0) AS s FROM answers WHERE attempt_id=?",
            (attempt_id,),
        ).fetchone()["s"]
        total = conn.execute(
            "SELECT COALESCE(SUM(points), 0) AS m FROM questions WHERE quiz_id=?",
            (quiz["id"],),
        ).fetchone()["m"]
        pct = (float(earned) / float(total) * 100) if total else 0
        conn.execute(
            "UPDATE attempts SET score=?, max_score=?, percentage=? WHERE id=?",
            (float(earned), float(total), pct, attempt_id),
        )
        conn.commit()
        return jsonify({"ok": True, "attempt_id": attempt_id, "score": earned, "max_score": total, "percentage": pct})
    finally:
        conn.close()


def _parse_submitted(qtype: str, raw: list[str]):
    if not raw:
        return None
    if qtype in ("mcq_single", "true_false"):
        try:
            return int(raw[0])
        except Exception:
            return None
    if qtype == "mcq_multi":
        return [int(x) for x in raw if x.strip().isdigit()]
    if qtype == "rating":
        try:
            return int(raw[0])
        except Exception:
            return None
    # text-based
    return raw[0] if len(raw) == 1 else raw


@app.route("/q/<code>/result/<int:attempt_id>")
def quiz_result(code, attempt_id):
    conn = db.get_conn()
    try:
        quiz = conn.execute("SELECT * FROM quizzes WHERE share_code=?", (code,)).fetchone()
        if not quiz:
            abort(404)
        attempt = conn.execute(
            "SELECT * FROM attempts WHERE id=? AND quiz_id=?", (attempt_id, quiz["id"])
        ).fetchone()
        if not attempt:
            abort(404)
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (quiz["id"],)
        ).fetchall()]
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
        ans = {a["question_id"]: dict(a) for a in conn.execute(
            "SELECT * FROM answers WHERE attempt_id=?", (attempt_id,)
        ).fetchall()}
        for a in ans.values():
            try:
                a["value"] = json.loads(a["answer"] or "null")
            except Exception:
                a["value"] = a["answer"]
        return render_template(
            "student/results.html",
            quiz=dict(quiz), attempt=dict(attempt),
            questions=questions, answers=ans,
        )
    finally:
        conn.close()


@app.route("/live/<join_code>")
def live_student(join_code):
    conn = db.get_conn()
    try:
        s = conn.execute(
            "SELECT * FROM live_sessions WHERE join_code=?", (join_code.upper(),)
        ).fetchone()
        if not s:
            abort(404)
        quiz = conn.execute("SELECT * FROM quizzes WHERE id=?", (s["quiz_id"],)).fetchone()
        return render_template("student/live.html", session=dict(s), quiz=dict(quiz))
    finally:
        conn.close()


# ---------- Socket.IO: live sessions ----------

def _question_payload(q: dict, include_correct=False) -> dict:
    out = {
        "id": q["id"],
        "type": q["type"],
        "text": q["text"],
        "options": q["options"] if isinstance(q.get("options"), list) else json.loads(q.get("options") or "[]"),
        "points": q.get("points") or 1,
        "position": q.get("position", 0),
    }
    if include_correct:
        ca = q.get("correct_answers")
        out["correct_answers"] = ca if isinstance(ca, list) else json.loads(ca or "[]")
    return out


def _load_live(session_id):
    conn = db.get_conn()
    try:
        s = conn.execute("SELECT * FROM live_sessions WHERE id=?", (session_id,)).fetchone()
        if not s:
            return None, None
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (s["quiz_id"],)
        ).fetchall()]
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
        return dict(s), questions
    finally:
        conn.close()


def _aggregate(qid: int, qtype: str, answers_dict: dict):
    """Aggregate live answers for a question."""
    counts = defaultdict(int)
    texts = []
    total = 0
    for sid, val in answers_dict.items():
        total += 1
        if qtype in ("mcq_single", "true_false", "rating"):
            if isinstance(val, int):
                counts[val] += 1
        elif qtype == "mcq_multi":
            if isinstance(val, list):
                for v in val:
                    counts[int(v)] += 1
        else:
            if val:
                texts.append(str(val))
    return {"counts": dict(counts), "texts": texts, "total": total}


@socketio.on("join_host")
def on_join_host(data):
    session_id = int(data.get("session_id") or 0)
    # Validate host owns it
    uid = session.get("uid")
    if not uid:
        emit("error_msg", {"msg": "Your login session expired — refresh and sign in again."})
        return
    conn = db.get_conn()
    try:
        s = conn.execute(
            """SELECT ls.*, q.kind AS quiz_kind FROM live_sessions ls
               JOIN quizzes q ON q.id = ls.quiz_id
               WHERE ls.id=? AND q.user_id=?""",
            (session_id, uid),
        ).fetchone()
        if not s:
            emit("error_msg", {
                "msg": "Live session not found. (If the server restarted, please start a new live session from the quiz editor.)"
            })
            return
    finally:
        conn.close()
    join_room(f"live_{session_id}")
    join_room(f"host_{session_id}")
    sess, questions = _load_live(session_id)
    state = LIVE_STATE[session_id]
    with LIVE_LOCK:
        state["host_sids"].add(request.sid)
        state["owner_uid"] = uid
        state["quiz_kind"] = s["quiz_kind"]
        SID_TO_SESSION[request.sid] = session_id
    emit("host_state", {
        "session": sess,
        "participants": [
            {"sid": sid, "name": p["name"], "score": p["score"]}
            for sid, p in state["participants"].items()
        ],
        "total_questions": len(questions),
        "quiz_kind": s["quiz_kind"],
    })


def _is_host_sid(session_id: int) -> bool:
    """Authorize a SocketIO event as coming from a verified host."""
    state = LIVE_STATE.get(session_id)
    return bool(state) and request.sid in state.get("host_sids", set())


def _finalize_live_attempts(session_id: int) -> None:
    """When a live session ends, finalize each participant's attempt row with submitted_at + score."""
    state = LIVE_STATE.get(session_id)
    if not state:
        return
    quiz_id = state.get("quiz_id")
    conn = db.get_conn()
    try:
        max_score = 0.0
        if quiz_id:
            row = conn.execute(
                "SELECT COALESCE(SUM(points), 0) AS s FROM questions WHERE quiz_id=?",
                (quiz_id,),
            ).fetchone()
            max_score = float(row["s"] or 0)
        now = db.now_ts()
        for sid, p in state["participants"].items():
            attempt_id = p.get("attempt_id")
            if not attempt_id:
                continue
            score = float(p.get("score", 0))
            pct = (score / max_score * 100) if max_score else 0
            conn.execute(
                """UPDATE attempts SET submitted_at=?, score=?, max_score=?, percentage=?
                   WHERE id=? AND submitted_at IS NULL""",
                (now, score, max_score, pct, attempt_id),
            )
        conn.commit()
    finally:
        conn.close()


@socketio.on("join_student")
def on_join_student(data):
    join_code = (data.get("join_code") or "").upper()
    name = (data.get("name") or "Anonymous").strip()[:40]
    conn = db.get_conn()
    try:
        s = conn.execute(
            "SELECT * FROM live_sessions WHERE join_code=?", (join_code,)
        ).fetchone()
        if not s:
            emit("error_msg", {"msg": "Session not found"})
            return
        if s["status"] == "finished":
            emit("error_msg", {"msg": "Session has ended"})
            return
    finally:
        conn.close()
    session_id = s["id"]
    join_room(f"live_{session_id}")
    state = LIVE_STATE[session_id]
    # Persist this participant as an attempt so they show up in Results
    conn2 = db.get_conn()
    try:
        cur = conn2.execute(
            """INSERT INTO attempts(quiz_id, student_name, started_at, ip_address, live_session_id)
               VALUES(?,?,?,?,?)""",
            (s["quiz_id"], name, db.now_ts(), request.remote_addr, session_id),
        )
        attempt_id = cur.lastrowid
        conn2.commit()
    finally:
        conn2.close()
    with LIVE_LOCK:
        state["participants"][request.sid] = {
            "name": name,
            "score": 0,
            "attempt_id": attempt_id,
        }
        state["quiz_id"] = s["quiz_id"]
        SID_TO_SESSION[request.sid] = session_id
    emit("student_joined", {"name": name, "session_id": session_id})
    emit("participants_update", {
        "participants": [
            {"name": p["name"], "score": p["score"]}
            for p in state["participants"].values()
        ],
    }, room=f"host_{session_id}")
    # If question is currently active, send it
    sess, questions = _load_live(session_id)
    if sess["status"] == "running" and 0 <= sess["current_question_index"] < len(questions):
        q = questions[sess["current_question_index"]]
        emit("show_question", {"question": _question_payload(q), "index": sess["current_question_index"], "total": len(questions)})


@socketio.on("host_next")
def on_host_next(data):
    session_id = int(data.get("session_id") or 0)
    if not _is_host_sid(session_id):
        emit("error_msg", {"msg": "Host authorization lost — please refresh the page."})
        return
    conn = db.get_conn()
    try:
        s = conn.execute("SELECT * FROM live_sessions WHERE id=?", (session_id,)).fetchone()
        if not s:
            emit("error_msg", {
                "msg": "This live session no longer exists. (Server may have restarted — start a new live session.)"
            })
            return
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (s["quiz_id"],)
        ).fetchall()]
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
        if not questions:
            emit("error_msg", {"msg": "This quiz has no questions to show."})
            return
        cur_idx = s["current_question_index"]
        if cur_idx is None:
            cur_idx = -1
        next_idx = cur_idx + 1
        if next_idx >= len(questions):
            conn.execute(
                "UPDATE live_sessions SET status='finished', ended_at=? WHERE id=?",
                (db.now_ts(), session_id),
            )
            conn.commit()
            _finalize_live_attempts(session_id)
            state = LIVE_STATE[session_id]
            leaderboard = sorted(
                [{"name": p["name"], "score": p["score"]} for p in state["participants"].values()],
                key=lambda x: -x["score"],
            )[:50]
            socketio.emit("session_ended", {
                "session_id": session_id,
                "leaderboard": leaderboard,
            }, room=f"live_{session_id}")
            return
        conn.execute(
            "UPDATE live_sessions SET status='running', current_question_index=? WHERE id=?",
            (next_idx, session_id),
        )
        conn.commit()
        q = questions[next_idx]
        payload = {
            "question": _question_payload(q),
            "index": next_idx,
            "total": len(questions),
            "quiz_kind": LIVE_STATE[session_id].get("quiz_kind", "exam"),
        }
        socketio.emit("show_question", payload, room=f"live_{session_id}")
    finally:
        conn.close()


@socketio.on("host_reveal")
def on_host_reveal(data):
    session_id = int(data.get("session_id") or 0)
    if not _is_host_sid(session_id):
        emit("error_msg", {"msg": "Host authorization lost — please refresh the page."})
        return
    sess, questions = _load_live(session_id)
    if not sess:
        return
    idx = sess["current_question_index"]
    if idx < 0 or idx >= len(questions):
        return
    q = questions[idx]
    state = LIVE_STATE[session_id]
    agg = _aggregate(q["id"], q["type"], state["answers_per_q"][q["id"]])
    state["revealed"].add(q["id"])
    socketio.emit("reveal_answer", {
        "question_id": q["id"],
        "correct_answers": q["correct_answers"],
        "aggregate": agg,
        "type": q["type"],
        "options": q["options"],
    }, room=f"live_{session_id}")
    # leaderboard
    leaderboard = sorted(
        [{"name": p["name"], "score": p["score"]} for p in state["participants"].values()],
        key=lambda x: -x["score"],
    )[:20]
    socketio.emit("leaderboard", {"leaderboard": leaderboard}, room=f"live_{session_id}")


@socketio.on("host_end")
def on_host_end(data):
    session_id = int(data.get("session_id") or 0)
    if not _is_host_sid(session_id):
        emit("error_msg", {"msg": "Host authorization lost — please refresh the page."})
        return
    conn = db.get_conn()
    try:
        conn.execute(
            "UPDATE live_sessions SET status='finished', ended_at=? WHERE id=?",
            (db.now_ts(), session_id),
        )
        conn.commit()
    finally:
        conn.close()
    _finalize_live_attempts(session_id)
    state = LIVE_STATE[session_id]
    leaderboard = sorted(
        [{"name": p["name"], "score": p["score"]} for p in state["participants"].values()],
        key=lambda x: -x["score"],
    )[:50]
    socketio.emit("session_ended", {"leaderboard": leaderboard}, room=f"live_{session_id}")


@socketio.on("student_answer")
def on_student_answer(data):
    # Trust the server-side sid → session_id mapping (the client value can't be relied on)
    session_id = SID_TO_SESSION.get(request.sid) or int(data.get("session_id") or 0)
    qid = int(data.get("question_id") or 0)
    answer = data.get("answer")
    if not session_id:
        emit("error_msg", {"msg": "Lost connection to the live session — please refresh."})
        return
    sess, questions = _load_live(session_id)
    if not sess or sess["status"] != "running":
        return
    q = next((q for q in questions if q["id"] == qid), None)
    if not q:
        return
    state = LIVE_STATE[session_id]
    sid = request.sid
    if sid not in state["participants"]:
        return
    attempt_id = state["participants"][sid].get("attempt_id")
    with LIVE_LOCK:
        if sid in state["answers_per_q"][qid]:
            return  # one answer per student per question
        state["answers_per_q"][qid][sid] = answer
        is_correct, pts, manual = grading.grade_answer(q, answer)
        if is_correct:
            state["participants"][sid]["score"] += int(pts)
    # Persist answer
    if attempt_id:
        conn = db.get_conn()
        try:
            conn.execute(
                """INSERT INTO answers(attempt_id, question_id, answer, is_correct, points_earned, graded)
                   VALUES(?,?,?,?,?,?)""",
                (
                    attempt_id, qid, json.dumps(answer),
                    None if is_correct is None else (1 if is_correct else 0),
                    pts,
                    0 if manual else 1,
                ),
            )
            conn.commit()
        finally:
            conn.close()
    # send aggregate to host
    agg = _aggregate(qid, q["type"], state["answers_per_q"][qid])
    socketio.emit("answer_stats", {
        "question_id": qid,
        "aggregate": agg,
        "type": q["type"],
        "options": q["options"],
    }, room=f"host_{session_id}")
    # ack student
    emit("answer_received", {"question_id": qid, "is_correct": is_correct})


@socketio.on("disconnect")
def on_disconnect():
    sid = request.sid
    with LIVE_LOCK:
        SID_TO_SESSION.pop(sid, None)
    for session_id, state in list(LIVE_STATE.items()):
        with LIVE_LOCK:
            state.get("host_sids", set()).discard(sid)
        if sid in state["participants"]:
            with LIVE_LOCK:
                state["participants"].pop(sid, None)
            socketio.emit("participants_update", {
                "participants": [
                    {"name": p["name"], "score": p["score"]}
                    for p in state["participants"].values()
                ],
            }, room=f"host_{session_id}")


# ---------- main ----------

if __name__ == "__main__":
    host = os.environ.get("HOST", "0.0.0.0")
    port = int(os.environ.get("PORT", "5000"))
    socketio.run(app, host=host, port=port, debug=False, allow_unsafe_werkzeug=True)
