"""Remove all attempts where the respondent is 'Anonymous' (empty name or literally 'Anonymous')."""
import sys
import db


def main():
    dry_run = "--apply" not in sys.argv
    conn = db.get_conn()
    try:
        rows = conn.execute(
            """SELECT a.id, a.quiz_id, q.title, a.student_name, a.student_email,
                      a.submitted_at, a.started_at
               FROM attempts a JOIN quizzes q ON q.id = a.quiz_id
               WHERE TRIM(COALESCE(a.student_name, '')) = ''
                  OR LOWER(TRIM(a.student_name)) = 'anonymous'
               ORDER BY a.quiz_id, a.id"""
        ).fetchall()
        if not rows:
            print("No anonymous attempts found.")
            return
        print(f"Found {len(rows)} anonymous attempt(s):\n")
        per_quiz = {}
        for r in rows:
            per_quiz.setdefault((r["quiz_id"], r["title"]), 0)
            per_quiz[(r["quiz_id"], r["title"])] += 1
        for (qid, title), n in per_quiz.items():
            print(f"  Quiz {qid} ({title}): {n} anonymous attempt(s)")
        if dry_run:
            print("\nDRY RUN — nothing deleted. Run with --apply to actually delete.")
            return
        ids = tuple(r["id"] for r in rows)
        ph = ",".join(["?"] * len(ids))
        cur = conn.execute(f"DELETE FROM attempts WHERE id IN ({ph})", ids)
        conn.commit()
        print(f"\nDeleted {cur.rowcount or len(rows)} attempt(s).")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
