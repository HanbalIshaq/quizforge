"""Verify 'who picked each option' drill-down + 'Questions answered' on page and in exports."""
import csv
import io
import re
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "drill@t.com", "password": "testtest", "name": "D"})
    s.post(f"{BASE}/login", data={"email": "drill@t.com", "password": "testtest"})
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Drill", "kind": "poll"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "Pick one", "options": ["Red", "Blue", "Green"],
        "correct_answers": [], "points": 0,
    })
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "open_ended", "text": "Why?", "options": [], "correct_answers": [], "points": 0,
    })

    code = re.search(r"/q/([A-Z0-9]+)", s.get(f"{BASE}/admin/quizzes/{quiz_id}").text).group(1)
    public = requests.get(f"{BASE}/q/{code}").text
    qids = [int(x) for x in re.findall(r'data-qid="(\d+)"', public)]
    q_mcq, q_text = qids[0], qids[1]

    # Alice + Bob pick Red, Carol picks Blue, Dave picks Green; David doesn't answer Q2
    responses = [
        ("Alice", 0, "Roses"),
        ("Bob",   0, "Strawberries"),
        ("Carol", 1, "Sky"),
        ("Dave",  2, ""),  # picks Green, leaves text empty
    ]
    for name, mcq, txt in responses:
        st = requests.Session()
        st.get(f"{BASE}/q/{code}")
        form = {"student_name": name, f"q_{q_mcq}": str(mcq)}
        if txt:
            form[f"q_{q_text}"] = txt
        st.post(f"{BASE}/q/{code}", data=form, allow_redirects=False)
    print(f"Submitted {len(responses)} responses")

    page = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results").text

    # 1. 'Who picked this' drill-down for MCQ option
    red_count = "Who picked this (2)" in page  # Alice + Bob picked Red
    alice_listed = "Alice" in page and "Bob" in page
    print(f"'Who picked this (2)' details present (Alice+Bob → Red): {red_count}")
    print(f"Names of pickers shown on page: {alice_listed}")

    # 2. Text response with name attribution
    name_with_text = re.search(r"<b[^>]*>Alice</b>\s*—\s*Roses", page) is not None
    print(f"Text response shows 'Alice — Roses': {name_with_text}")

    # 3. Questions answered column in respondent list
    has_qa_col = "Questions answered" in page
    print(f"'Questions answered' column on poll-results page: {has_qa_col}")
    # Alice answered both -> 2/2, Dave answered 1 -> 1/2
    has_dave_one_of_two = re.search(r"Dave.*?1\s*</span>\s*/\s*2", page, re.DOTALL) is not None
    print(f"Dave shows 1/2 (he skipped the text question): {has_dave_one_of_two}")

    # 4. CSV export contains the new column
    csv_resp = s.get(f"{BASE}/admin/quizzes/{quiz_id}/export.csv")
    rows = list(csv.reader(io.StringIO(csv_resp.text)))
    header = rows[0]
    has_answered_col = "Answered" in header
    print(f"CSV header has 'Answered' column: {has_answered_col}")
    n_idx = header.index("Answered") if has_answered_col else -1
    dave_row = next((r for r in rows[1:] if r[0] == "Dave"), None)
    if dave_row and n_idx >= 0:
        dave_count = dave_row[n_idx]
        print(f"CSV: Dave's Answered count: {dave_count} (expect 1)")
    else:
        dave_count = None

    all_ok = (red_count and alice_listed and name_with_text
              and has_qa_col and has_dave_one_of_two
              and has_answered_col and dave_count == "1")
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
