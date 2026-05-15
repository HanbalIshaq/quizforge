"""End-to-end test: pass a quiz, get a certificate, verify + download."""
import re
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "c@t.com", "password": "testtest", "name": "C"})
    s.post(f"{BASE}/login", data={"email": "c@t.com", "password": "testtest"})

    # Dashboard renders with the new card-based layout?
    r = s.get(f"{BASE}/admin")
    new_dashboard = "Exams &amp; Quizzes" in r.text and "Live Sessions" in r.text and "View all exams" in r.text
    print(f"Dashboard cards rendered: {new_dashboard}")
    has_plan_badge = "Your plan:" in r.text and "Free" in r.text
    print(f"Plan badge shown: {has_plan_badge}")

    # Create an exam with a pass mark
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Cert exam", "kind": "exam"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "1+1?", "options": ["1", "2"], "correct_answers": [1], "points": 1
    })
    # Set pass mark = 1%
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/setting", json={"field": "pass_mark", "value": 1})

    code = re.search(r"/q/([A-Z0-9]+)", s.get(f"{BASE}/admin/quizzes/{quiz_id}").text).group(1)
    public = requests.get(f"{BASE}/q/{code}").text
    qid = int(re.findall(r'data-qid="(\d+)"', public)[0])

    # Student passes
    st = requests.Session()
    st.get(f"{BASE}/q/{code}")
    sub = st.post(f"{BASE}/q/{code}",
                  data={"student_name": "Bob Tester", f"q_{qid}": "1"},
                  allow_redirects=False)
    print(f"Submit: HTTP {sub.status_code}")
    result_url = sub.headers["Location"]
    rr = st.get(BASE + result_url)
    has_cert_button = "Download PDF certificate" in rr.text
    print(f"'Download PDF certificate' button on results page: {has_cert_button}")
    m = re.search(r"/cert/([A-Z0-9\-]+)\.pdf", rr.text)
    if not m:
        print("FAIL — no cert link on results page")
        sys.exit(1)
    serial = m.group(1)
    print(f"Certificate serial: {serial}")

    # Verify endpoint
    rv = requests.get(f"{BASE}/verify/{serial}")
    verify_ok = ("Verified certificate" in rv.text and "Bob Tester" in rv.text
                 and serial in rv.text)
    print(f"Verification page shows the cert: {verify_ok}")

    # Download the PDF
    rd = requests.get(f"{BASE}/cert/{serial}.pdf")
    pdf_ok = rd.status_code == 200 and rd.content[:4] == b"%PDF" and len(rd.content) > 1000
    print(f"PDF download: HTTP {rd.status_code}, {len(rd.content)} bytes, valid PDF header: {rd.content[:4] == b'%PDF'}")

    all_ok = (new_dashboard and has_plan_badge and has_cert_button
              and verify_ok and pdf_ok)
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
