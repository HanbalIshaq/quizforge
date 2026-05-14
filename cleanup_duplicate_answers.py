"""One-shot cleanup: remove duplicate answer rows that an older submit code path left
in the database. For each (attempt_id, question_id) pair with > 1 row, keep only the
row with the highest id (most recently inserted) and delete the rest.

Safe to run multiple times — no-op after the first run.
"""
import db

conn = db.get_conn()
try:
    # Find (attempt_id, question_id) pairs with duplicates
    dup_pairs = conn.execute(
        """SELECT attempt_id, question_id, COUNT(*) AS n
           FROM answers
           GROUP BY attempt_id, question_id
           HAVING COUNT(*) > 1"""
    ).fetchall()
    print(f"Found {len(dup_pairs)} (attempt, question) pairs with duplicate answer rows.")
    total_removed = 0
    for p in dup_pairs:
        keep_id = conn.execute(
            "SELECT MAX(id) AS m FROM answers WHERE attempt_id=? AND question_id=?",
            (p["attempt_id"], p["question_id"]),
        ).fetchone()["m"]
        cur = conn.execute(
            "DELETE FROM answers WHERE attempt_id=? AND question_id=? AND id != ?",
            (p["attempt_id"], p["question_id"], keep_id),
        )
        total_removed += cur.rowcount or (p["n"] - 1)
    conn.commit()
    print(f"Removed {total_removed} duplicate answer row(s). DB now clean.")
finally:
    conn.close()
