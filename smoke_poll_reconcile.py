"""Verify per-question count matches the displayed respondent count."""
import re
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "p2@t.com", "password": "testtest", "name": "P"})
    s.post(f"{BASE}/login", data={"email": "p2@t.com", "password": "testtest"})
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Recon poll", "kind": "poll"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "Pick one",
        "options": ["A", "B", "C"], "correct_answers": [], "points": 0,
    })

    code = re.search(r"/q/([A-Z0-9]+)", s.get(f"{BASE}/admin/quizzes/{quiz_id}").text).group(1)
    public = requests.get(f"{BASE}/q/{code}").text
    qid = int(re.findall(r'data-qid="(\d+)"', public)[0])

    # Same student creates 3 partial attempts (drafts), each picks A, then submits one
    for i in range(3):
        st = requests.Session()
        st.get(f"{BASE}/q/{code}")
        att = int(re.search(r'data-attempt-id="(\d+)"', st.get(f"{BASE}/q/{code}").text).group(1))
        st.post(f"{BASE}/q/{code}/save", json={
            "attempt_id": att, "student_name": "Alice",
            "answers": {str(qid): 0},
        })
    # Two other unique respondents (one submits, one partial)
    st1 = requests.Session()
    st1.get(f"{BASE}/q/{code}")
    st1.post(f"{BASE}/q/{code}", data={"student_name": "Bob", f"q_{qid}": "1"}, allow_redirects=False)
    st2 = requests.Session()
    st2.get(f"{BASE}/q/{code}")
    att2 = int(re.search(r'data-attempt-id="(\d+)"', st2.get(f"{BASE}/q/{code}").text).group(1))
    st2.post(f"{BASE}/q/{code}/save", json={
        "attempt_id": att2, "student_name": "Carol",
        "answers": {str(qid): 2},
    })

    # Now: respondents should be 3 (Alice + Bob + Carol). The 3 Alice drafts deduplicate to 1.
    # Per-question total should also be 3 (Alice's latest answer + Bob's + Carol's).
    page = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results").text
    respondents = re.search(r"Respondents.*?(\d+)", page, re.DOTALL).group(1)
    q_total = re.search(r"(\d+) response\(?s?\)?", page).group(1)
    print(f"Respondents (top stat): {respondents}")
    print(f"Per-question total: {q_total}")
    print(f"Match: {respondents == q_total == '3'}")
    sys.exit(0 if respondents == q_total == "3" else 1)


if __name__ == "__main__":
    main()
