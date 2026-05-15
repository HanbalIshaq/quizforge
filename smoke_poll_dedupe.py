"""Verify: poll display dedupes by name+email, duplicate submission is blocked, cleanup button works."""
import re
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "dd@t.com", "password": "testtest", "name": "D"})
    s.post(f"{BASE}/login", data={"email": "dd@t.com", "password": "testtest"})
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Dedupe poll", "kind": "poll"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "Pick", "options": ["A","B","C"], "correct_answers": [], "points": 0,
    })
    code = re.search(r"/q/([A-Z0-9]+)", s.get(f"{BASE}/admin/quizzes/{quiz_id}").text).group(1)
    public = requests.get(f"{BASE}/q/{code}").text
    qid = int(re.findall(r'data-qid="(\d+)"', public)[0])

    def submit(name, choice, session_=None):
        st = session_ or requests.Session()
        st.get(f"{BASE}/q/{code}")
        rr = st.post(f"{BASE}/q/{code}",
                     data={"student_name": name, f"q_{qid}": str(choice)},
                     allow_redirects=False)
        return st, rr

    # ----- Test 1: re-submit by SAME session is blocked (redirects to existing) -----
    st_alice = requests.Session()
    _, r1 = submit("Alice", 0, st_alice)
    print(f"Alice first submit: HTTP {r1.status_code} -> {r1.headers.get('Location')}")
    # Try to submit again with fresh draft creation
    _, r2 = submit("Alice", 1, st_alice)
    print(f"Alice second submit (same session): HTTP {r2.status_code} -> {r2.headers.get('Location')}")
    # Should redirect to the FIRST attempt id (not a new one)
    first_id = int(r1.headers.get('Location').rsplit('/', 1)[-1])
    second_id = int(r2.headers.get('Location').rsplit('/', 1)[-1])
    block_same_session = (second_id == first_id)
    print(f"Blocked duplicate (same id returned): {block_same_session}")

    # ----- Test 2: re-submit by NEW session as Alice (incognito) is also blocked -----
    _, r3 = submit("Alice", 2)
    third_id = int(r3.headers.get('Location').rsplit('/', 1)[-1])
    block_new_session = (third_id == first_id)
    print(f"Blocked duplicate (new session): {block_new_session}")

    # ----- Test 3: display dedupes BEFORE cleanup (existing dupes get hidden) -----
    # Let's create some duplicates by inserting directly bypassing the block — simulate legacy data.
    import db
    db_conn = db.get_conn()
    now = db.now_ts()
    # Two extra Alice submissions (legacy duplicates)
    db_conn.execute(
        "INSERT INTO attempts(quiz_id, student_name, started_at, submitted_at) VALUES(?,?,?,?)",
        (quiz_id, "Alice", now, now)
    )
    db_conn.execute(
        "INSERT INTO attempts(quiz_id, student_name, started_at, submitted_at) VALUES(?,?,?,?)",
        (quiz_id, "Alice", now - 100, now - 100)
    )
    # Also create answers for them so they show up
    new_ids = [r["id"] for r in db_conn.execute(
        "SELECT id FROM attempts WHERE quiz_id=? AND student_name='Alice'", (quiz_id,)
    ).fetchall()]
    for aid in new_ids:
        db_conn.execute(
            "INSERT INTO answers(attempt_id, question_id, answer) VALUES(?,?,?)",
            (aid, qid, "0")
        )
    db_conn.commit()
    db_conn.close()

    print(f"\nDB now has {len(new_ids)} Alice attempts total (legacy dupes inserted)")
    page = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results").text
    alice_rows_displayed = len(re.findall(r"<td[^>]*>Alice</td>", page))
    print(f"Display dedupe: Alice shown N times in respondent table: {alice_rows_displayed}")
    display_ok = alice_rows_displayed == 1
    print(f"Display dedupes to 1 row: {display_ok}")

    # ----- Test 4: cleanup button physically removes duplicates -----
    cleanup_r = s.post(f"{BASE}/admin/quizzes/{quiz_id}/dedupe-submissions",
                       allow_redirects=False)
    print(f"Cleanup: HTTP {cleanup_r.status_code}")
    # Now check DB
    import db
    db_conn = db.get_conn()
    remaining = db_conn.execute(
        "SELECT COUNT(*) AS c FROM attempts WHERE quiz_id=? AND student_name='Alice'", (quiz_id,)
    ).fetchone()["c"]
    db_conn.close()
    print(f"Remaining Alice rows after cleanup: {remaining}")
    cleanup_ok = remaining == 1

    all_ok = (block_same_session and block_new_session
              and display_ok and cleanup_ok)
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
