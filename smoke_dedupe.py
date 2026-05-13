"""Test: same student creating multiple partial attempts should appear as ONE row in Results."""
import re
import sqlite3
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "t@t.com", "password": "testtest", "name": "T"})
    s.post(f"{BASE}/login", data={"email": "t@t.com", "password": "testtest"})
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Dedupe", "kind": "exam"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])

    for q in [
        {"type": "mcq_single", "text": "Q1?", "options": ["A","B","C"], "correct_answers": [1], "points": 1, "time_limit_seconds": 0},
        {"type": "mcq_single", "text": "Q2?", "options": ["A","B","C"], "correct_answers": [1], "points": 1, "time_limit_seconds": 0},
    ]:
        s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json=q)

    s.post(f"{BASE}/admin/quizzes/{quiz_id}/settings", data={
        "title": "Dedupe", "kind": "exam",
        "time_limit_seconds": "0", "pass_mark": "50", "max_attempts": "0",
        "require_name": "on", "show_correct_answers": "on", "is_published": "on",
    })

    conn = sqlite3.connect("quizforge.db")
    conn.row_factory = sqlite3.Row
    share = conn.execute("SELECT share_code FROM quizzes WHERE id=?", (quiz_id,)).fetchone()["share_code"]

    # Simulate student opening quiz 3 times in 3 different sessions, each with a saved answer
    for i in range(3):
        st = requests.Session()
        r = st.get(f"{BASE}/q/{share}")
        att_id = int(re.search(r'data-attempt-id="(\d+)"', r.text).group(1))
        # Get q1 id
        qids = [int(x) for x in re.findall(r'data-qid="(\d+)"', r.text)]
        # Each attempt answers q1 = index 1 (correct, 1 pt)
        rs = st.post(f"{BASE}/q/{share}/save", json={
            "attempt_id": att_id,
            "student_name": "Hanbal",
            "student_email": "hanbal@gmail.com",
            "answers": {str(qids[0]): 1},
        })
        print(f"Attempt {i+1} (id={att_id}) saved: {rs.json()}")

    # Verify DB: 3 attempts total, all partial
    rows = list(conn.execute("SELECT id, student_name, score, max_score, percentage, submitted_at FROM attempts WHERE quiz_id=?", (quiz_id,)).fetchall())
    print(f"\nDB has {len(rows)} attempt rows:")
    for r in rows: print(f"  {dict(r)}")

    # Teacher views Results — should see ONLY ONE row (latest partial for Hanbal)
    rresults = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results")
    hanbal_count = len(re.findall(r"Hanbal", rresults.text))
    in_progress_count = len(re.findall(r"In progress", rresults.text))
    fail_count = len(re.findall(r">Fail<", rresults.text))
    pass_count = len(re.findall(r">Pass<", rresults.text))
    print(f"\nResults page: Hanbal mentions={hanbal_count}, 'In progress' badges={in_progress_count}, Fail badges={fail_count}, Pass badges={pass_count}")

    # Now have one of those drafts actually submit
    st = requests.Session()
    r = st.get(f"{BASE}/q/{share}")
    att_id = int(re.search(r'data-attempt-id="(\d+)"', r.text).group(1))
    qids = sorted([int(x) for x in re.findall(r'data-qid="(\d+)"', r.text)])  # original DB order
    # Answer both correctly
    form = {"student_name": "Hanbal", "student_email": "hanbal@gmail.com"}
    for qid in qids: form[f"q_{qid}"] = "1"
    st.post(f"{BASE}/q/{share}", data=form, allow_redirects=False)

    rresults2 = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results")
    h2 = len(re.findall(r"Hanbal", rresults2.text))
    in_prog2 = len(re.findall(r"In progress", rresults2.text))
    pass2 = len(re.findall(r">Pass<", rresults2.text))
    partial2 = len(re.findall(r"Partial", rresults2.text))
    print(f"After Hanbal submits: mentions={h2}, In progress={in_prog2}, Pass={pass2}, Partial={partial2}")

    # Real-time partial score check
    sample_partial = next((dict(r) for r in conn.execute("SELECT * FROM attempts WHERE quiz_id=? AND submitted_at IS NULL", (quiz_id,)).fetchall()), None)
    if sample_partial:
        print(f"Sample partial in DB: score={sample_partial['score']}, max_score={sample_partial['max_score']}, percentage={sample_partial['percentage']}")

    all_ok = (
        hanbal_count >= 1 and hanbal_count <= 2 and  # one row in students column, maybe one in email
        in_progress_count >= 1 and
        pass_count == 0 and fail_count == 0  # partial should NOT show Pass/Fail
        and pass2 >= 1  # after submit, Pass badge appears
        and in_prog2 == 0  # no partial remaining after submit
    )
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
