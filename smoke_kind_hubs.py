"""Smoke test for the per-kind hub pages.

Verifies:
  - /admin?kind=exam renders the exam hub with its distinctive copy
  - /admin?kind=poll renders the poll hub with its distinctive copy
  - /admin?kind=survey renders the survey hub
  - /admin?kind=form renders the form hub
  - /admin (no kind) still renders the original 5-card dashboard
  - Each kind's hero text, color theme and features are PRESENT and DISTINCT
  - /admin/live shows the refreshed rose-themed Live Sessions hero
  - An unknown ?kind=garbage falls through to the all-view safely
"""
import os
import sys
import json

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_hub.db"
if os.path.exists("test_smoke_hub.db"):
    os.remove("test_smoke_hub.db")

import bcrypt
from app import app, KIND_CONFIGS
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


# Seed a teacher and one quiz of each kind
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("teacher@test.local", pw, "Teacher", db.now_ts()),
)
for i, k in enumerate(("exam", "poll", "survey", "form")):
    conn.execute(
        """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,is_published)
           VALUES(1,?,'desc',?,?,?,?,1)""",
        (f"My {k} {i}", f"CODE{k.upper()}{i}", k, db.now_ts(), db.now_ts()),
    )
    conn.execute(
        """INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position)
           VALUES(?,'mcq_single','Q1',?,?,1,0)""",
        (i + 1, json.dumps(["A", "B"]), json.dumps([0])),
    )
    if k == "exam":
        # Submit one attempt with a score, so avg_pct is non-null
        conn.execute(
            """INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,score,max_score,percentage)
               VALUES(?,'Student',?,?,1,1,100)""",
            (i + 1, db.now_ts(), db.now_ts()),
        )
conn.commit()
conn.close()


print("Each kind hub renders DISTINCTIVE content:")
with app.test_client() as c:
    c.post("/login", data={"email": "teacher@test.local", "password": "pass1234"})

    pages = {}
    for kind in ("exam", "poll", "survey", "form"):
        r = c.get(f"/admin?kind={kind}")
        check(f"/admin?kind={kind} returns 200", r.status_code == 200, f"got {r.status_code}")
        pages[kind] = r.data.decode("utf-8", errors="replace")

    # Every kind shows its OWN tagline from KIND_CONFIGS
    for kind, page in pages.items():
        expected_tagline = KIND_CONFIGS[kind]["tagline"]
        check(f"{kind} hub shows its tagline '{expected_tagline[:30]}...'",
              expected_tagline in page)
        # And its OWN icon (don't put the emoji in the label — Windows cp1252 chokes)
        check(f"{kind} hub shows its icon",
              KIND_CONFIGS[kind]["icon"] in page)
        # And at least 3 of its 6 features show up
        feature_titles = [f["t"] for f in KIND_CONFIGS[kind]["features"]]
        present = [t for t in feature_titles if t in page]
        check(f"{kind} hub shows at least 3 of 6 features",
              len(present) >= 3, f"{len(present)}/6 present")
        # And its color theme appears in classes
        theme = KIND_CONFIGS[kind]["theme"]
        check(f"{kind} hub uses {theme}-* color classes",
              f"text-{theme}-700" in page or f"bg-{theme}-" in page)

    # Crucially, the four pages must be DIFFERENT
    seen = {pages["exam"][:5000], pages["poll"][:5000],
            pages["survey"][:5000], pages["form"][:5000]}
    check("the four hubs produce different HTML (not the same template)",
          len(seen) == 4, f"distinct count={len(seen)}")

    # Each hub doesn't leak the OTHER kinds' taglines (no cross-contamination)
    for kind, page in pages.items():
        for other_kind, other_conf in KIND_CONFIGS.items():
            if other_kind == kind:
                continue
            # The other tagline should NOT appear on this kind's page
            check(f"{kind} hub does NOT contain {other_kind}'s tagline",
                  other_conf["tagline"] not in page)

    # The "all" view still renders dashboard.html with its 5 cards
    r = c.get("/admin")
    check("/admin (no kind) returns 200", r.status_code == 200)
    body = r.data.decode("utf-8", errors="replace")
    check("'all' view shows the 5-card overview",
          "Exams &amp; Quizzes" in body and "Try a demo" in body)
    check("'all' view does NOT use the hub's hero", "What you can build with" not in body)

    # Unknown kind falls through to all-view safely
    r = c.get("/admin?kind=garbage")
    check("unknown ?kind=garbage doesn't 500", r.status_code == 200, f"got {r.status_code}")

    # Live sessions page got the rose hero
    r = c.get("/admin/live")
    check("/admin/live returns 200", r.status_code == 200, f"got {r.status_code}")
    live_body = r.data.decode("utf-8", errors="replace")
    check("live page shows new hero", "Real-time, classroom-style." in live_body)
    check("live page shows feature row", "Live leaderboard" in live_body and "Join code" in live_body)


print("\nExam hub uses kind-relevant stats:")
with app.test_client() as c:
    c.post("/login", data={"email": "teacher@test.local", "password": "pass1234"})
    r = c.get("/admin?kind=exam")
    body = r.data.decode("utf-8", errors="replace")
    check("exam hub shows Avg % column", "Avg %" in body)
    check("exam hub shows 100% from the seeded attempt", "100%" in body)


# Cleanup
if os.path.exists("test_smoke_hub.db"):
    os.remove("test_smoke_hub.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
