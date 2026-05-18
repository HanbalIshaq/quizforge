"""Smoke tests for the round-2 UI/UX detailed audit fixes.

Covers:
  - quiz_form, quiz_results action bars stack on mobile (no horizontal overflow)
  - Long share-link URL has break-all so it doesn't overflow
  - Question card layout: row on mobile, column on desktop
  - Join page has autocapitalize=characters + spellcheck=false + maxlength
  - Site features toggle now has aria-label + peer-focus-visible ring
  - Snapshot grid images have loading=lazy + decoding=async + alt text
  - base.html exposes theme-color meta and copy-link helper JS
  - custom.css has scroll-margin-top + qf-copy-btn done state
  - Floating Add-Question FAB present + wired
  - Student cert URL has break-all
"""
import os, sys, json

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_ui2.db"
if os.path.exists("test_smoke_ui2.db"):
    os.remove("test_smoke_ui2.db")

import bcrypt
from app import app
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


def read(p):
    with open(p, encoding="utf-8") as f:
        return f.read()


# Seed minimal data
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("admin@test.local", pw, "Admin", db.now_ts()),
)
conn.execute(
    """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,is_published)
       VALUES(1,'UI 2 Test','','UI2TEST','exam',?,?,1)""",
    (db.now_ts(), db.now_ts()),
)
conn.execute(
    """INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position)
       VALUES(1,'mcq_single','Q1',?,?,1,0)""",
    (json.dumps(["A","B"]), json.dumps([0])),
)
conn.execute(
    """INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,score,max_score,percentage)
       VALUES(1,'Tester',?,?,1,1,100)""",
    (db.now_ts(), db.now_ts()),
)
conn.commit()
conn.close()


print("Action bars stack on mobile:")
qf = read("templates/admin/quiz_form.html")
qr = read("templates/admin/quiz_results.html")
check("quiz_form header has flex-col + sm:flex-row",
      "flex-col gap-3 sm:flex-row sm:items-start sm:justify-between" in qf)
check("quiz_results header has flex-col + sm:flex-row",
      "flex-col gap-3 sm:flex-row sm:items-start sm:justify-between" in qr)
check("quiz_form title is break-words", 'class="text-2xl font-bold mt-1 break-words"' in qf)
check("quiz_form public URL has break-all", "text-xs break-all" in qf)
check("quiz_form has a Copy-link button wired with data-copy", "qf-copy-btn" in qf and "data-copy=" in qf)


print("\nQuestion card mobile layout:")
qc = read("templates/admin/_question_card.html")
check("question card uses flex-col then sm:flex-row", "flex flex-col sm:flex-row sm:items-start" in qc)
check("question text is break-words", "font-medium break-words" in qc)
check("edit button has aria-label", 'aria-label="Edit question' in qc)
check("delete button has aria-label", 'aria-label="Delete question' in qc)
check("action buttons stretch on mobile, column on desktop",
      "flex sm:flex-col gap-2 sm:gap-1 shrink-0 self-stretch sm:self-start" in qc)


print("\nJoin page mobile keyboard hints:")
jp = read("templates/student/join.html")
check("autocapitalize=characters", 'autocapitalize="characters"' in jp)
check("autocorrect=off", 'autocorrect="off"' in jp)
check("spellcheck=false", 'spellcheck="false"' in jp)
check("maxlength set", 'maxlength="12"' in jp)
check("explicit label (sr-only)", 'for="join-code"' in jp and 'class="sr-only"' in jp)
check("aria-describedby links the hint", 'aria-describedby="join-code-hint"' in jp)


print("\nSite features toggle a11y:")
sf = read("templates/admin/site_features.html")
check("toggle has aria-label", 'aria-label="Toggle ' in sf)
check("peer-focus-visible ring present", "peer-focus-visible:ring-2" in sf)


print("\nSnapshot grid lazy loading + alt text:")
ad = read("templates/admin/attempt_detail.html")
check("snapshot images have loading=lazy", 'loading="lazy"' in ad)
check("snapshot images have decoding=async", 'decoding="async"' in ad)
check("snapshot images have alt text", "alt=\"{{ s.kind.replace('_', ' ') }} snapshot\"" in ad)
check("snapshot grid uses aspect-square (no layout shift)", "aspect-square" in ad)
check("external snapshot link has rel=noopener", 'rel="noopener"' in ad)
check("anchor has aria-label", 'aria-label="View {{ s.kind' in ad)


print("\nbase.html + custom.css globals:")
bh = read("templates/base.html")
cs = read("static/css/custom.css")
check("theme-color meta tag present", '<meta name="theme-color"' in bh)
check("apple-mobile-web-app-capable", 'apple-mobile-web-app-capable' in bh)
check("global copy-link helper JS present", 'data-copy' in bh and 'navigator.clipboard' in bh)
check("scroll-margin-top rule for anchor targets",
      "scroll-margin-top: 80px" in cs)
check("qf-copy-btn done state", '.qf-copy-btn[data-copy-state="done"]' in cs)


print("\nFloating Add-Question FAB:")
check("FAB element present in quiz_form", 'id="add-q-fab"' in qf)
check("FAB hidden by default + sm:hidden", 'hidden sm:hidden' in qf)
check("FAB has aria-label", 'aria-label="Add' in qf)
check("FAB JS wires click-through to top button + IntersectionObserver",
      'IntersectionObserver' in qf and "topBtn.click()" in qf)


print("\nStudent cert URL break-all:")
sr = read("templates/student/results.html")
check("student cert verify URL container has break-all",
      "text-xs text-slate-500 mt-1 break-all" in sr)


print("\nLive admin pages render after the changes:")
with app.test_client() as c:
    c.post("/login", data={"email": "admin@test.local", "password": "pass1234"})
    for path, label in [
        ("/admin/quizzes/1",          "quiz editor"),
        ("/admin/quizzes/1/results",  "quiz results"),
        ("/admin/quizzes/1/attempts/1", "attempt detail"),
        ("/admin/site/features",      "site features"),
        ("/j",                        "join page"),
    ]:
        r = c.get(path)
        check(f"{label} returns 200 ({path})", r.status_code == 200, f"got {r.status_code}")


# Cleanup
if os.path.exists("test_smoke_ui2.db"):
    os.remove("test_smoke_ui2.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
