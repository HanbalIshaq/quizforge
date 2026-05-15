"""Verify the new sign-in security + Tier-2 anti-cheat features."""
import re
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "sec@t.com", "password": "testtest", "name": "S"})

    # ---- 1. Forgot-password flow ----
    r = s.post(f"{BASE}/forgot-password", data={"email": "sec@t.com"}, allow_redirects=False)
    print(f"Forgot-pw: HTTP {r.status_code}")
    # SHOW_RESET_LINK=1, so the redirect target shows the link
    r2 = s.get(f"{BASE}/login")
    m = re.search(r"/reset-password/([A-Za-z0-9_\-]+)", r2.text)
    print(f"Reset link surfaced on /login: {bool(m)}")
    if m:
        token = m.group(1)
        # ---- 2. Use the token to set a new password ----
        rs = requests.Session()
        rr = rs.post(f"{BASE}/reset-password/{token}",
                     data={"password": "newpass123"}, allow_redirects=False)
        print(f"Reset POST: HTTP {rr.status_code}")
        # ---- 3. Token is single-use — try again should fail ----
        rr2 = rs.get(f"{BASE}/reset-password/{token}", allow_redirects=False)
        print(f"Reused token: HTTP {rr2.status_code} (expect 302 -> /forgot-password)")
        # ---- 4. Old password no longer works ----
        ls = requests.Session()
        bad = ls.post(f"{BASE}/login",
                      data={"email": "sec@t.com", "password": "testtest"}, allow_redirects=False)
        old_pw_rejected = bad.status_code == 401
        print(f"Old password rejected: {old_pw_rejected}")
        # New password works
        ok = ls.post(f"{BASE}/login",
                     data={"email": "sec@t.com", "password": "newpass123"}, allow_redirects=False)
        new_pw_ok = ok.status_code == 302
        print(f"New password works: {new_pw_ok}")

    # ---- 5. IP allowlist (do this BEFORE rate-limit hammer, so we don't share an IP cool-off window) ----
    s2 = requests.Session()
    s2.post(f"{BASE}/login", data={"email": "sec@t.com", "password": "newpass123"})
    q = s2.post(f"{BASE}/admin/quizzes/new", data={"title": "IP test", "kind": "exam"})
    quiz_id = int(q.url.rsplit("/", 1)[-1])
    s2.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "x", "options": ["a","b"], "correct_answers": [0], "points": 1
    })
    # Set allowlist to a non-matching IP
    s2.post(f"{BASE}/admin/quizzes/{quiz_id}/setting", json={"field": "ip_allowlist", "value": "10.20.30.40"})
    code = re.search(r"/q/([A-Z0-9]+)", s2.get(f"{BASE}/admin/quizzes/{quiz_id}").text).group(1)
    blocked = requests.get(f"{BASE}/q/{code}", allow_redirects=False)
    print(f"\nIP allowlist blocking '127.0.0.1': HTTP {blocked.status_code}, has '🚫' marker: {'🚫' in blocked.text}")
    ip_block_works = blocked.status_code == 403 and "🚫" in blocked.text
    # Now allow 127.0.0.1
    s2.post(f"{BASE}/admin/quizzes/{quiz_id}/setting", json={"field": "ip_allowlist", "value": "127.0.0.0/8, 192.168.0.0/16"})
    allowed = requests.get(f"{BASE}/q/{code}")
    ip_allow_works = allowed.status_code == 200
    print(f"IP allowlist allowing 127.0.0.0/8: HTTP {allowed.status_code}")

    # ---- 6. Rate limit / lockout (after many fails, IP gets 429 or account 423) ----
    print("\n--- Hammering login with bad passwords ---")
    ls = requests.Session()
    last = 0
    for i in range(12):
        r = ls.post(f"{BASE}/login",
                    data={"email": "sec@t.com", "password": "wrong"}, allow_redirects=False)
        last = r.status_code
    print(f"After 12 wrong attempts, last status: {last} (expect 429 IP-rate-limit or 423 account-locked)")
    locked_or_limited = last in (429, 423)

    all_ok = old_pw_rejected and new_pw_ok and locked_or_limited and ip_block_works and ip_allow_works
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
