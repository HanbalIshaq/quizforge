"""Smoke tests for the UI/UX + accessibility overhaul.

Covers:
  - Skip-to-main-content link present and proper sr-only styling hooks
  - Mobile hamburger button present with aria-controls + aria-expanded
  - Mobile drawer markup present (hidden md:hidden)
  - Tables on admin pages wrapped in .qf-table-wrap for horizontal scroll
  - Login/register forms have autocomplete + inputmode + proper labels
  - CSS has touch-target rule + focus-visible + responsive table styles
"""
import os
import sys
import re

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_ui.db"
if os.path.exists("test_smoke_ui.db"):
    os.remove("test_smoke_ui.db")

import bcrypt
from app import app
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


# Seed user so admin pages render
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("admin@test.local", pw, "Admin", db.now_ts()),
)
import json as _json
conn.execute(
    """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,is_published)
       VALUES(1,'UI Test','','UITEST','exam',?,?,1)""",
    (db.now_ts(), db.now_ts()),
)
conn.execute(
    """INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,score,max_score,percentage)
       VALUES(1,'Student',?,?,5,5,100)""",
    (db.now_ts(), db.now_ts()),
)
conn.commit()
conn.close()


print("base.html — skip link + mobile nav + ARIA:")
with app.test_client() as c:
    r = c.get("/")
    body = r.data.decode("utf-8", errors="replace")
    check("page returns 200", r.status_code == 200)
    check("skip-to-main-content link present",
          'href="#main-content"' in body and 'Skip to main content' in body)
    check("<main> has id='main-content'", 'id="main-content"' in body)
    check("hamburger button has aria-controls + aria-expanded",
          'id="qf-nav-toggle"' in body and 'aria-controls="qf-mobile-nav"' in body and
          'aria-expanded="false"' in body)
    check("mobile drawer markup present",
          'id="qf-mobile-nav"' in body and 'md:hidden' in body)
    check("desktop nav has aria-label='Primary'", 'aria-label="Primary"' in body)
    # Flash messages block is conditional on messages existing; check the
    # template source instead of the rendered HTML.
    _base_src = open("templates/base.html", encoding="utf-8").read()
    check("flash messages region has role=status + aria-live (template source)",
          'role="status"' in _base_src and 'aria-live="polite"' in _base_src)
    check("body brand 'Q' icon marked aria-hidden", 'aria-hidden="true"' in body)


print("\ncustom.css — a11y + touch targets:")
css = open("static/css/custom.css", encoding="utf-8").read()
check("focus-visible outline rule present", ":focus-visible" in css)
check("sr-only utility class defined", ".sr-only" in css)
check("44px touch-target rule present", "min-height: 44px" in css)
check("iOS-zoom prevention: input font-size 16px on mobile", "font-size: 16px" in css)
check(".qf-table-wrap class defined", ".qf-table-wrap" in css)
check("scroll-shadow gradient present", "linear-gradient" in css and "qf-scrollable" in css)
check("line-clamp-2 helper defined", ".line-clamp-2" in css)
check("prefers-reduced-motion media query present", "prefers-reduced-motion" in css)


print("\nLogin / Register — autocomplete + accessibility:")
login = open("templates/login.html", encoding="utf-8").read()
register = open("templates/register.html", encoding="utf-8").read()
check("login: email field has autocomplete=email", 'autocomplete="email"' in login)
check("login: password field has autocomplete=current-password",
      'autocomplete="current-password"' in login)
check("login: email input has inputmode=email", 'inputmode="email"' in login)
check("login: explicit <label for=...>", 'for="login-email"' in login)
check("login: turns off autocapitalize for email",
      'autocapitalize="off"' in login)
check("register: email autocomplete=email", 'autocomplete="email"' in register)
check("register: password autocomplete=new-password",
      'autocomplete="new-password"' in register)
check("register: name autocomplete=name", 'autocomplete="name"' in register)
check("register: password hint linked via aria-describedby",
      'aria-describedby="reg-password-hint"' in register)


print("\nAdmin tables wrapped for mobile scroll:")
for f in ["templates/admin/quiz_results.html",
          "templates/admin/dashboard.html",
          "templates/admin/site_users.html",
          "templates/admin/site_dashboard.html",
          "templates/admin/live_list.html"]:
    s = open(f, encoding="utf-8").read()
    check(f"{f.rsplit('/',1)[-1]}: tables wrapped in qf-table-wrap",
          ".qf-table-wrap" in s or "qf-table-wrap" in s)


print("\nQuiz results: 📷 table header has accessible name:")
qr = open("templates/admin/quiz_results.html", encoding="utf-8").read()
check("📷 header has aria-label", 'aria-label="Proctoring snapshots"' in qr)


print("\nLive render check — admin dashboard mobile scroll wrap shows up:")
with app.test_client() as c:
    c.post("/login", data={"email": "admin@test.local", "password": "pass1234"})
    r = c.get("/admin/quizzes/1/results")
    body = r.data.decode("utf-8", errors="replace")
    check("quiz_results page returns 200", r.status_code == 200)
    check("qf-table-wrap appears in rendered HTML", "qf-table-wrap" in body)
    check("rendered page exposes csrf-token meta", 'meta name="csrf-token"' in body)


# Cleanup
if os.path.exists("test_smoke_ui.db"):
    os.remove("test_smoke_ui.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
