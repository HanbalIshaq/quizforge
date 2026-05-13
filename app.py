"""QuizForge — self-hostable quiz + live-poll platform."""
import json
import os
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
})
LIVE_LOCK = threading.Lock()


# ---------- helpers ----------

def current_user():
    uid = session.get("uid")
    if not uid:
        return None
    conn = db.get_conn()
    try:
        row = conn.execute("SELECT id, email, name FROM users WHERE id=?", (uid,)).fetchone()
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


@app.context_processor
def inject_globals():
    return {"user": current_user(), "fmt_ts": fmt_ts, "QUESTION_TYPES": grading.QUESTION_TYPES}


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
            cur = conn.execute(
                "INSERT INTO users(email, password_hash, name, created_at) VALUES(?,?,?,?)",
                (email, ph, name, db.now_ts()),
            )
            conn.commit()
            session["uid"] = cur.lastrowid
            return redirect(url_for("admin_dashboard"))
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
            session["uid"] = row["id"]
            nxt = request.args.get("next") or url_for("admin_dashboard")
            return redirect(nxt)
        finally:
            conn.close()
    return render_template("login.html", form={})


@app.route("/logout")
def logout():
    session.clear()
    return redirect(url_for("home"))


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
    code = db.unique_code("quizzes", "share_code", 7)
    conn = db.get_conn()
    try:
        cur = conn.execute(
            """INSERT INTO quizzes(user_id, title, share_code, kind, created_at, updated_at)
               VALUES(?,?,?,?,?,?)""",
            (session["uid"], title, code, kind, db.now_ts(), db.now_ts()),
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
                is_published=?, updated_at=?
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
        if not text:
            return jsonify({"ok": False, "error": "Question text required"}), 400
        if qid:
            conn.execute(
                """UPDATE questions SET type=?, text=?, options=?, correct_answers=?, points=?, explanation=?
                   WHERE id=? AND quiz_id=?""",
                (qtype, text, json.dumps(options), json.dumps(correct), points, explanation, qid, quiz_id),
            )
        else:
            pos_row = conn.execute(
                "SELECT COALESCE(MAX(position), -1)+1 AS p FROM questions WHERE quiz_id=?", (quiz_id,)
            ).fetchone()
            cur = conn.execute(
                """INSERT INTO questions(quiz_id, type, text, options, correct_answers, points, position, explanation)
                   VALUES(?,?,?,?,?,?,?,?)""",
                (quiz_id, qtype, text, json.dumps(options), json.dumps(correct), points, pos_row["p"], explanation),
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
        attempts = [dict(r) for r in conn.execute(
            "SELECT * FROM attempts WHERE quiz_id=? ORDER BY submitted_at DESC NULLS LAST, started_at DESC",
            (quiz_id,),
        ).fetchall()]
        for a in attempts:
            a["started_at_fmt"] = fmt_ts(a["started_at"])
            a["submitted_at_fmt"] = fmt_ts(a["submitted_at"])
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
            student_name = (request.form.get("student_name") or "Anonymous").strip()
            student_email = (request.form.get("student_email") or "").strip()
            now = db.now_ts()
            cur = conn.execute(
                """INSERT INTO attempts(quiz_id, student_name, student_email, started_at, submitted_at, ip_address)
                   VALUES(?,?,?,?,?,?)""",
                (quiz["id"], student_name, student_email, now, now, request.remote_addr),
            )
            attempt_id = cur.lastrowid
            total_pts = 0.0
            max_pts = 0.0
            needs_grading = 0
            for q in questions:
                max_pts += float(q["points"] or 1)
                raw = request.form.getlist(f"q_{q['id']}")
                # parse raw based on type
                value = _parse_submitted(q["type"], raw)
                is_correct, pts, manual = grading.grade_answer(q, value)
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
            pct = (total_pts / max_pts * 100) if max_pts else 0
            conn.execute(
                "UPDATE attempts SET score=?, max_score=?, percentage=?, needs_grading=? WHERE id=?",
                (total_pts, max_pts, pct, needs_grading, attempt_id),
            )
            conn.commit()
            return redirect(url_for("quiz_result", code=code, attempt_id=attempt_id))
        return render_template("student/quiz.html", quiz=dict(quiz), questions=questions)
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
        emit("error_msg", {"msg": "Not logged in"})
        return
    conn = db.get_conn()
    try:
        s = conn.execute(
            """SELECT ls.* FROM live_sessions ls
               JOIN quizzes q ON q.id = ls.quiz_id
               WHERE ls.id=? AND q.user_id=?""",
            (session_id, uid),
        ).fetchone()
        if not s:
            emit("error_msg", {"msg": "Not your session"})
            return
    finally:
        conn.close()
    join_room(f"live_{session_id}")
    join_room(f"host_{session_id}")
    # Send current state
    sess, questions = _load_live(session_id)
    state = LIVE_STATE[session_id]
    emit("host_state", {
        "session": sess,
        "participants": [
            {"sid": sid, "name": p["name"], "score": p["score"]}
            for sid, p in state["participants"].items()
        ],
        "total_questions": len(questions),
    })


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
    with LIVE_LOCK:
        state["participants"][request.sid] = {"name": name, "score": 0}
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
    uid = session.get("uid")
    if not uid:
        return
    conn = db.get_conn()
    try:
        s = conn.execute(
            """SELECT ls.* FROM live_sessions ls
               JOIN quizzes q ON q.id = ls.quiz_id
               WHERE ls.id=? AND q.user_id=?""",
            (session_id, uid),
        ).fetchone()
        if not s:
            return
        questions = [dict(r) for r in conn.execute(
            "SELECT * FROM questions WHERE quiz_id=? ORDER BY position", (s["quiz_id"],)
        ).fetchall()]
        for q in questions:
            q["options"] = json.loads(q["options"] or "[]")
            q["correct_answers"] = json.loads(q["correct_answers"] or "[]")
        next_idx = (s["current_question_index"] or -1) + 1
        if next_idx >= len(questions):
            conn.execute(
                "UPDATE live_sessions SET status='finished', ended_at=? WHERE id=?",
                (db.now_ts(), session_id),
            )
            conn.commit()
            socketio.emit("session_ended", {"session_id": session_id}, room=f"live_{session_id}")
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
        }
        socketio.emit("show_question", payload, room=f"live_{session_id}")
    finally:
        conn.close()


@socketio.on("host_reveal")
def on_host_reveal(data):
    session_id = int(data.get("session_id") or 0)
    uid = session.get("uid")
    if not uid:
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
    uid = session.get("uid")
    if not uid:
        return
    conn = db.get_conn()
    try:
        conn.execute(
            """UPDATE live_sessions SET status='finished', ended_at=?
               WHERE id=? AND quiz_id IN (SELECT id FROM quizzes WHERE user_id=?)""",
            (db.now_ts(), session_id, uid),
        )
        conn.commit()
    finally:
        conn.close()
    state = LIVE_STATE[session_id]
    leaderboard = sorted(
        [{"name": p["name"], "score": p["score"]} for p in state["participants"].values()],
        key=lambda x: -x["score"],
    )[:50]
    socketio.emit("session_ended", {"leaderboard": leaderboard}, room=f"live_{session_id}")


@socketio.on("student_answer")
def on_student_answer(data):
    session_id = int(data.get("session_id") or 0)
    qid = int(data.get("question_id") or 0)
    answer = data.get("answer")
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
    with LIVE_LOCK:
        if sid in state["answers_per_q"][qid]:
            return  # one answer per student per question
        state["answers_per_q"][qid][sid] = answer
        # grade if auto
        is_correct, pts, _manual = grading.grade_answer(q, answer)
        if is_correct:
            state["participants"][sid]["score"] += int(pts)
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
    for session_id, state in list(LIVE_STATE.items()):
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
