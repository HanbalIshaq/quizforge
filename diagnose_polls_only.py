import db
from collections import Counter

conn = db.get_conn()
polls = conn.execute(
    "SELECT id, title, kind, share_code FROM quizzes WHERE kind IN ('poll','survey') ORDER BY id"
).fetchall()

for quiz in polls:
    quiz_id = quiz["id"]
    print(f"\n=== Quiz {quiz_id} ({quiz['kind']}): {quiz['title']} (share={quiz['share_code']}) ===")
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
    print(f"raw attempts: {len(raw)}")
    def key(a):
        return ((a.get("student_name") or "").strip().lower(),
                (a.get("student_email") or "").strip().lower())
    submitted_keys = {key(a) for a in raw if a["submitted_at"]}
    seen = set()
    visible = []
    for a in raw:
        if a["submitted_at"]:
            visible.append(a); continue
        k = key(a)
        if k in submitted_keys: continue
        if k in seen: continue
        seen.add(k); visible.append(a)
    visible_ids = [a["id"] for a in visible]
    print(f"visible attempts after dedupe: {len(visible)}")
    print(f"breakdown: submitted={sum(1 for a in visible if a['submitted_at'])} partial={sum(1 for a in visible if not a['submitted_at'])}")
    for a in visible:
        flag = "submitted" if a["submitted_at"] else "partial"
        print(f"  id={a['id']:>3} name='{a['student_name']}' email='{a['student_email']}' {flag}")

    questions = conn.execute("SELECT id, text, type FROM questions WHERE quiz_id=? ORDER BY position", (quiz_id,)).fetchall()
    for q in questions:
        if visible_ids:
            ph = ",".join(["?"] * len(visible_ids))
            rows = conn.execute(
                f"SELECT attempt_id, answer FROM answers WHERE question_id=? AND attempt_id IN ({ph})",
                (q["id"], *visible_ids),
            ).fetchall()
        else:
            rows = []
        all_rows = conn.execute("SELECT attempt_id, answer FROM answers WHERE question_id=?", (q["id"],)).fetchall()
        print(f"  Q{q['id']} {q['type']}: filtered={len(rows)} total_in_db={len(all_rows)}")
        att_counts = Counter(r["attempt_id"] for r in all_rows)
        dupes = {k: v for k, v in att_counts.items() if v > 1}
        if dupes:
            print(f"    !! DUPLICATE answer rows for same attempt: {dupes}")
        excluded = [a for a in att_counts if a not in visible_ids]
        if excluded:
            print(f"    excluded attempt_ids: {excluded}")
conn.close()
