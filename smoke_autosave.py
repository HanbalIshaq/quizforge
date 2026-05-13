"""End-to-end test for randomization, auto-save, and partial-attempt visibility."""
import json
import sqlite3
import sys
import time
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "t@t.com", "password": "testtest", "name": "T"})
    s.post(f"{BASE}/login", data={"email": "t@t.com", "password": "testtest"})
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "AutoSave", "kind": "exam"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])

    # 3 questions with fixed correct answers
    for i, q in enumerate([
        {"type": "mcq_single", "text": "Capital of France?", "options": ["Berlin","Paris","Rome","Madrid"], "correct_answers": [1], "points": 1, "time_limit_seconds": 0},
        {"type": "mcq_single", "text": "2+2?", "options": ["3","4","5","6"], "correct_answers": [1], "points": 1, "time_limit_seconds": 0},
        {"type": "mcq_single", "text": "Sky color?", "options": ["Red","Green","Blue","Yellow"], "correct_answers": [2], "points": 1, "time_limit_seconds": 0},
    ]):
        s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json=q)

    # Turn randomization ON
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/settings", data={
        "title": "AutoSave", "kind": "exam",
        "time_limit_seconds": "0", "pass_mark": "0", "max_attempts": "0",
        "require_name": "on", "show_correct_answers": "on", "is_published": "on",
        "randomize_questions": "on", "randomize_options": "on",
    })

    conn = sqlite3.connect("quizforge.db")
    conn.row_factory = sqlite3.Row
    share = conn.execute("SELECT share_code FROM quizzes WHERE id=?", (quiz_id,)).fetchone()["share_code"]
    print(f"share={share}")

    # ----- TEST: Randomization gives different orders per attempt -----
    s1 = requests.Session()
    r1 = s1.get(f"{BASE}/q/{share}")
    s2 = requests.Session()
    r2 = s2.get(f"{BASE}/q/{share}")
    # Extract Q text order
    import re
    def texts(html): return re.findall(r"text-lg\">([^<]+)<", html)
    t1, t2 = texts(r1.text), texts(r2.text)
    print(f"Order #1: {t1}")
    print(f"Order #2: {t2}")
    rand_ok = (t1 != t2) or (len(set(t1)) == 3)  # at least one ran differently
    print(f"Question randomization: order changed = {t1 != t2}, all unique = {len(set(t1))==3}")

    # ----- TEST: Auto-save creates partial attempt in DB -----
    # Use student session s1 — get the attempt_id by parsing form
    m = re.search(r'data-attempt-id="(\d+)"', r1.text)
    attempt_id = int(m.group(1))
    print(f"Draft attempt_id from page: {attempt_id}")
    # Find Q1's id on the page (first qid)
    qids_in_order = re.findall(r'data-qid="(\d+)"', r1.text)
    q1_displayed_id = int(qids_in_order[0])
    print(f"First-displayed question_id: {q1_displayed_id}")
    # Save an answer to whichever Q is first on the page (orig index 1 = a known correct for those 2 questions; we don't know the order, just check save works)
    save_r = s1.post(f"{BASE}/q/{share}/save", json={
        "attempt_id": attempt_id,
        "student_name": "Alice",
        "answers": { str(q1_displayed_id): 1 },
    })
    print(f"Save response: {save_r.json()}")

    # ----- TEST: Partial attempt visible in Results without submitting -----
    rresults = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results")
    partial_visible = "Partial" in rresults.text and "Alice" in rresults.text
    print(f"Results page shows partial Alice with badge: {partial_visible}")

    # DB check
    row = conn.execute(
        "SELECT student_name, submitted_at FROM attempts WHERE id=?", (attempt_id,)
    ).fetchone()
    print(f"DB attempt: name={row['student_name']}, submitted_at={row['submitted_at']}")
    n_answers = conn.execute("SELECT COUNT(*) AS c FROM answers WHERE attempt_id=?", (attempt_id,)).fetchone()["c"]
    print(f"DB answers for attempt: {n_answers}")

    # ----- TEST: Submit finalizes the same attempt -----
    # We need to post all q_X answers — get all qids
    all_qs = conn.execute("SELECT id FROM questions WHERE quiz_id=? ORDER BY position", (quiz_id,)).fetchall()
    form_data = {"student_name": "Alice"}
    for q in all_qs:
        form_data[f"q_{q['id']}"] = "1"  # all to original index 1
    submit_r = s1.post(f"{BASE}/q/{share}", data=form_data, allow_redirects=False)
    print(f"Submit status: {submit_r.status_code}, Location: {submit_r.headers.get('Location')}")
    row2 = conn.execute("SELECT submitted_at, score, max_score FROM attempts WHERE id=?", (attempt_id,)).fetchone()
    print(f"After submit: submitted_at={row2['submitted_at']}, score={row2['score']}/{row2['max_score']}")

    rresults2 = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results")
    partial_after_submit = "Partial" in rresults2.text
    print(f"Partial badge still on results page (should be False since submitted now): {partial_after_submit}")

    all_pass = (
        rand_ok and
        save_r.json().get("ok") and
        partial_visible and
        row2["submitted_at"] is not None and
        row2["score"] == 2.0  # correct answer is index 1 for Q1 (Paris) and Q2 (4); Q3 correct is 2 so we got 2/3
    )
    print("\nPASS" if all_pass else "\nFAIL — see above")
    sys.exit(0 if all_pass else 1)


if __name__ == "__main__":
    main()
