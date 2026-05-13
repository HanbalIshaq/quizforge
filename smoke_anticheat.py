"""End-to-end test for anti-cheating features."""
import re
import sqlite3
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "t@t.com", "password": "testtest", "name": "T"})
    s.post(f"{BASE}/login", data={"email": "t@t.com", "password": "testtest"})
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "AntiCheat", "kind": "exam"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "Q1?", "options": ["A","B"], "correct_answers": [0], "points": 1, "time_limit_seconds": 0
    })

    # Enable anti-cheating with password + tab-switch detection + auto-submit after 3 violations
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/settings", data={
        "title": "AntiCheat", "kind": "exam",
        "time_limit_seconds": "0", "pass_mark": "50", "max_attempts": "0",
        "require_name": "on", "show_correct_answers": "on", "is_published": "on",
        "quiz_password": "letmein",
        "detect_tab_switch": "on",
        "anti_paste": "on",
        "anti_rightclick": "on",
        "violation_limit": "3",
    })

    conn = sqlite3.connect("quizforge.db")
    conn.row_factory = sqlite3.Row
    share = conn.execute("SELECT share_code, quiz_password FROM quizzes WHERE id=?", (quiz_id,)).fetchone()
    print(f"share={share['share_code']}, password={share['quiz_password']}")

    # ---- Password gate works ----
    st = requests.Session()
    r = st.get(f"{BASE}/q/{share['share_code']}")
    gate_visible = "Password required" in r.text or "Quiz password" in r.text
    print(f"Password gate visible without password: {gate_visible}")

    # ---- Wrong password rejected ----
    r = st.post(f"{BASE}/q/{share['share_code']}", data={"__password": "wrong"})
    rejected = "Incorrect" in r.text
    print(f"Wrong password rejected: {rejected}")

    # ---- Correct password lets through ----
    r = st.post(f"{BASE}/q/{share['share_code']}", data={"__password": "letmein"}, allow_redirects=True)
    on_quiz = "data-attempt-id" in r.text
    print(f"Correct password lets through to quiz: {on_quiz}")

    # ---- Verify anti-cheat banner is rendered ----
    banner_ok = "Integrity mode is ON" in r.text and "Tab / window switches" in r.text
    print(f"Anti-cheat banner shown to student: {banner_ok}")

    attempt_id = int(re.search(r'data-attempt-id="(\d+)"', r.text).group(1))
    print(f"Attempt {attempt_id}")

    # ---- Log violations ----
    for i, kind in enumerate(["tab_switch", "paste", "rightclick"], 1):
        vr = st.post(f"{BASE}/q/{share['share_code']}/violation", json={
            "attempt_id": attempt_id, "type": kind, "details": f"test {i}"
        })
        j = vr.json()
        print(f"  violation {kind}: count={j['count']}, limit={j['limit']}, auto_submit={j['auto_submit']}")
    expected_auto = j["auto_submit"]
    print(f"After 3 violations, auto_submit flag: {expected_auto}")

    # ---- Verify violations stored ----
    rows = list(conn.execute("SELECT type, details FROM violations WHERE attempt_id=?", (attempt_id,)).fetchall())
    print(f"Stored violations: {[dict(r) for r in rows]}")

    # ---- Verify Results page shows violation badge ----
    rresults = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results")
    badge_visible = '⚠ 3' in rresults.text
    print(f"Results page shows violation badge with count 3: {badge_visible}")

    # ---- Verify attempt detail shows violations ----
    rdetail = s.get(f"{BASE}/admin/quizzes/{quiz_id}/attempts/{attempt_id}")
    detail_ok = "3 integrity violation" in rdetail.text and "tab switch" in rdetail.text
    print(f"Attempt detail shows violation list: {detail_ok}")

    all_ok = (gate_visible and rejected and on_quiz and banner_ok
              and len(rows) == 3 and expected_auto
              and badge_visible and detail_ok)
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
