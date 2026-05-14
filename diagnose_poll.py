"""Diagnose why a poll's respondent count and per-question count don't match.
Walks the data the same way the Results page does."""
import db

conn = db.get_conn()

# Show all quizzes
print("=== Quizzes in DB ===")
quizzes = conn.execute("SELECT id, title, kind, share_code FROM quizzes ORDER BY id").fetchall()
for q in quizzes:
    print(f"  id={q['id']}  kind={q['kind']}  title={q['title']}  share={q['share_code']}")

if not quizzes:
    print("No quizzes.")
    conn.close()
    raise SystemExit

# Walk each quiz
for quiz in quizzes:
    quiz_id = quiz["id"]
    print(f"\n--- Quiz {quiz_id} ({quiz['title']}) ---")

    # All attempts with activity (same query as the route)
    raw = conn.execute(
        """SELECT a.* FROM attempts a
           WHERE a.quiz_id=?
             AND (a.submitted_at IS NOT NULL
                  OR EXISTS (SELECT 1 FROM answers WHERE attempt_id=a.id)
                  OR EXISTS (SELECT 1 FROM violations WHERE attempt_id=a.id))
           ORDER BY a.submitted_at DESC NULLS LAST, a.started_at DESC""",
        (quiz_id,),
    ).fetchall()
    raw = [dict(r) for r in raw]
    print(f"  raw_attempts (rows that the dedupe sees): {len(raw)}")

    # Dedupe the same way
    def key(a):
        return ((a.get("student_name") or "").strip().lower(),
                (a.get("student_email") or "").strip().lower())
    submitted_keys = {key(a) for a in raw if a["submitted_at"]}
    seen = set()
    visible = []
    for a in raw:
        if a["submitted_at"]:
            visible.append(a)
            continue
        k = key(a)
        if k in submitted_keys: continue
        if k in seen: continue
        seen.add(k)
        visible.append(a)
    print(f"  visible attempts (deduped): {len(visible)}")
    print(f"  Respondents card would show: {len(visible)}")
    for a in visible:
        flag = "✓ submitted" if a["submitted_at"] else "… partial"
        print(f"    attempt_id={a['id']:>3}  name='{a['student_name']}'  email='{a['student_email']}'  {flag}")
    visible_ids = [a["id"] for a in visible]

    # Per-question counts (filtered the new way)
    questions = conn.execute("SELECT id, text, type FROM questions WHERE quiz_id=? ORDER BY position", (quiz_id,)).fetchall()
    for q in questions:
        qid = q["id"]
        if visible_ids:
            ph = ",".join(["?"] * len(visible_ids))
            rows = conn.execute(
                f"SELECT attempt_id, answer FROM answers WHERE question_id=? AND attempt_id IN ({ph})",
                (qid, *visible_ids),
            ).fetchall()
        else:
            rows = []
        n_filtered = len(rows)

        # And the unfiltered count, for comparison
        all_rows = conn.execute(
            "SELECT attempt_id, answer FROM answers WHERE question_id=?", (qid,)
        ).fetchall()
        all_attempt_ids = [r["attempt_id"] for r in all_rows]
        print(f"  Q{q['id']} ({q['type']}): {q['text'][:50]}")
        print(f"    filtered count: {n_filtered}   total in DB (all attempts ever): {len(all_rows)}")
        # Per-attempt breakdown
        from collections import Counter
        c = Counter(all_attempt_ids)
        dupes = {k: v for k, v in c.items() if v > 1}
        if dupes:
            print(f"    WARNING: attempts with multiple rows for this question: {dupes}")
        outside_visible = [a for a in all_attempt_ids if a not in visible_ids]
        if outside_visible:
            print(f"    Answers from attempt_ids NOT in visible (will be excluded by filter): {outside_visible}")

conn.close()
