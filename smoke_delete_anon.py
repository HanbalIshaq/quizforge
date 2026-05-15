"""Verify: delete-attempt works, anonymous stays separate, two same-named people stay separate."""
import re
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "x@t.com", "password": "testtest", "name": "X"})
    s.post(f"{BASE}/login", data={"email": "x@t.com", "password": "testtest"})
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Anon poll", "kind": "poll"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "Pick", "options": ["A","B"], "correct_answers": [], "points": 0,
    })
    # Email is NOT required for this poll — students may omit it
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/setting", json={"field": "require_email", "value": False})

    code = re.search(r"/q/([A-Z0-9]+)", s.get(f"{BASE}/admin/quizzes/{quiz_id}").text).group(1)
    public = requests.get(f"{BASE}/q/{code}").text
    qid = int(re.findall(r'data-qid="(\d+)"', public)[0])

    def submit(name, choice, email=""):
        st = requests.Session()
        st.get(f"{BASE}/q/{code}")
        form = {f"q_{qid}": str(choice)}
        if name: form["student_name"] = name
        if email: form["student_email"] = email
        return st.post(f"{BASE}/q/{code}", data=form, allow_redirects=False)

    # Two Alices with no email — should stay as TWO separate rows
    submit("Alice", 0)
    submit("Alice", 1)
    # One unnamed -> Anonymous
    submit("", 0)
    # One Alice WITH email + same Alice again with same email -> should dedupe
    submit("Alice", 0, "alice@example.com")
    submit("Alice", 1, "alice@example.com")  # 2nd should be blocked / redirect

    page = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results").text

    # Count rows
    name_alice_rows = len(re.findall(r"<td[^>]*>Alice</td>", page))
    anon_rows = len(re.findall(r"<td[^>]*>Anonymous</td>", page))
    print(f"Alice rows on page: {name_alice_rows} (expect 3 — two no-email + one with-email-deduped)")
    print(f"Anonymous rows on page: {anon_rows} (expect 1)")
    same_name_handled = (name_alice_rows == 3)
    anon_handled = (anon_rows >= 1)

    has_explainer = "Two people with the same name" in page
    has_delete_btn = "Delete</button>" in page or 'rounded hover:bg-red-100">Delete' in page
    print(f"Explainer note present: {has_explainer}")
    print(f"Delete button present: {has_delete_btn}")

    # ----- Delete an attempt -----
    attempts = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results").text
    # Find attempt ids in the page from Delete URL pattern
    ids = [int(m) for m in re.findall(r"/attempts/(\d+)/delete", attempts)]
    print(f"Attempt ids with delete buttons: {ids}")
    if ids:
        target = ids[0]
        dr = s.post(f"{BASE}/admin/quizzes/{quiz_id}/attempts/{target}/delete",
                    allow_redirects=False)
        print(f"Delete attempt {target}: HTTP {dr.status_code}")
        after = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results").text
        new_ids = [int(m) for m in re.findall(r"/attempts/(\d+)/delete", after)]
        print(f"Attempt ids after delete: {new_ids}")
        delete_works = target not in new_ids
    else:
        delete_works = False
    print(f"Delete actually removed the attempt: {delete_works}")

    all_ok = same_name_handled and anon_handled and has_explainer and has_delete_btn and delete_works
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
