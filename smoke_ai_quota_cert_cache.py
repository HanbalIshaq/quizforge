"""Smoke tests for:
  #5 — Daily AI generation quota per user (plan-tiered, audit-logged)
  #6 — Certificate PDF cache (lazy-populated bytea column)
"""
import os
import sys
import json

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_quota.db"
if os.path.exists("test_smoke_quota.db"):
    os.remove("test_smoke_quota.db")

import bcrypt
import app as app_module
from app import app, PLANS, ai_generations_today, log_ai_generation
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


# Seed: a Pro user (allow_ai=True, ai_per_day=10) + a Free user
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,plan,is_approved) VALUES(?,?,?,?,?,1)",
    ("pro@test.local", pw, "Pro User", db.now_ts(), "pro"),
)
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,plan,is_approved) VALUES(?,?,?,?,?,1)",
    ("free@test.local", pw, "Free User", db.now_ts(), "free"),
)
# Each user gets a quiz to generate into
for uid in (1, 2):
    conn.execute(
        """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,is_published)
           VALUES(?,'AI Quiz','',?,'exam',?,?,1)""",
        (uid, f"AIQUIZ{uid}", db.now_ts(), db.now_ts()),
    )
# Also an attempt + certificate for testing the PDF cache
conn.execute(
    """INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,score,max_score,percentage)
       VALUES(1,'Cert Holder',?,?,5,5,100)""",
    (db.now_ts(), db.now_ts()),
)
conn.execute(
    """INSERT INTO certificates(attempt_id,quiz_id,serial,recipient_name,score,max_score,percentage,issued_at)
       VALUES(1,1,'CERT-TEST-12345','Cert Holder',5,5,100,?)""",
    (db.now_ts(),),
)
# Enable the AI feature flag site-wide
conn.execute(
    "INSERT INTO site_settings(key, value) VALUES(?, ?)",
    ("feature_ai_quiz_gen", "1"),
)
conn.commit()

# Verify the migration added the columns we expect
ai_table = conn.execute("SELECT * FROM ai_generations LIMIT 1").fetchall()
cert_cols = conn.execute("PRAGMA table_info(certificates)").fetchall()
col_names = {(r[1] if isinstance(r, tuple) else r["name"]) for r in cert_cols}
conn.close()


print("Migrations:")
check("ai_generations table exists and queryable", isinstance(ai_table, list))
check("certificates.pdf_bytes column exists", "pdf_bytes" in col_names,
      f"cols={sorted(col_names)}")


print("\nPLANS metadata:")
check("Pro plan: ai_per_day = 10", PLANS["pro"]["ai_per_day"] == 10)
check("Business plan: ai_per_day = 50", PLANS["business"]["ai_per_day"] == 50)
check("Enterprise plan: ai_per_day = 0 (unlimited)", PLANS["enterprise"]["ai_per_day"] == 0)
check("Free plan: allow_ai = False", PLANS["free"]["allow_ai"] is False)


print("\n#5 — AI quota counter:")
# No generations yet
check("user 1 has 0 generations today initially", ai_generations_today(1) == 0)
# Log 3 generations
for _ in range(3):
    log_ai_generation(1, 1, 5)
check("user 1 has 3 generations after logging 3", ai_generations_today(1) == 3)
# User 2's count should be independent
check("user 2 still has 0 (per-user counting)", ai_generations_today(2) == 0)


print("\n#5 — AI quota enforcement in the route:")
# Stub out the real LLM call so we don't burn an API key in tests
def fake_generate_questions(material, n=10, qtype="mcq_single"):
    return [
        {"type": qtype, "text": f"Q{i+1}", "options": ["A","B","C","D"],
         "correct_answers": [0], "points": 1}
        for i in range(n)
    ]
app_module.ai_generator.generate_questions = fake_generate_questions

with app.test_client() as c:
    # Log in as the Free user — should be blocked at the allow_ai check
    c.post("/login", data={"email": "free@test.local", "password": "pass1234"})
    c.get("/admin")  # mint CSRF token
    home = c.get("/admin")
    import re
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', home.data.decode("utf-8"))
    token = m.group(1) if m else ""
    r = c.post("/admin/quizzes/2/ai-generate", data={
        "material": "Photosynthesis is the process by which plants convert light energy to chemical energy.",
        "n": "3", "qtype": "mcq_single", "_csrf": token,
    }, follow_redirects=False)
    # Redirects back to editor with a flash. We just check that NO generations got logged.
    check("Free user POST does NOT log an AI generation",
          ai_generations_today(2) == 0, f"got {ai_generations_today(2)}")

with app.test_client() as c:
    c.post("/login", data={"email": "pro@test.local", "password": "pass1234"})
    home = c.get("/admin")
    import re
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', home.data.decode("utf-8"))
    token = m.group(1) if m else ""

    # Pro user has 3 prior generations logged. Limit is 10 → 7 remaining.
    # Make 7 more — all should succeed and log.
    for i in range(7):
        r = c.post("/admin/quizzes/1/ai-generate", data={
            "material": "Material here.", "n": "2", "qtype": "mcq_single", "_csrf": token,
        }, follow_redirects=False)
    check("Pro user can make 10 total generations today",
          ai_generations_today(1) == 10, f"got {ai_generations_today(1)}")

    # 11th should be blocked (no new row, no LLM call)
    before = ai_generations_today(1)
    r = c.post("/admin/quizzes/1/ai-generate", data={
        "material": "Material here.", "n": "2", "qtype": "mcq_single", "_csrf": token,
    }, follow_redirects=False)
    after = ai_generations_today(1)
    check("11th generation is BLOCKED (no new log row)",
          after == before, f"before={before} after={after}")


print("\n#5 — Per-call n cap:")
# Pro user is now at the daily limit, so we'd be blocked. Reset by clearing the log.
_conn = db.get_conn()
_conn.execute("DELETE FROM ai_generations WHERE user_id=1")
_conn.commit()
_conn.close()
# Capture what `n` actually gets passed to fake_generate_questions
captured = {"n": None}
def capturing(material, n=10, qtype="mcq_single"):
    captured["n"] = n
    return [{"type": qtype, "text": "Q", "options": [], "correct_answers": [], "points": 1}] * n
app_module.ai_generator.generate_questions = capturing
with app.test_client() as c:
    c.post("/login", data={"email": "pro@test.local", "password": "pass1234"})
    home = c.get("/admin")
    import re
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', home.data.decode("utf-8"))
    token = m.group(1) if m else ""
    r = c.post("/admin/quizzes/1/ai-generate", data={
        "material": "Material.", "n": "9999", "qtype": "mcq_single", "_csrf": token,
    }, follow_redirects=False)
    check("n=9999 gets clamped to max 50", captured["n"] == 50,
          f"captured n={captured['n']}")


print("\n#6 — Certificate PDF cache:")
import inspect
src = inspect.getsource(app.view_functions["cert_download"])
check("cert_download reads pdf_bytes", "pdf_bytes" in src)
check("cert_download writes pdf_bytes after first render",
      "UPDATE certificates SET pdf_bytes=" in src)

# First download — cache miss, populates pdf_bytes
with app.test_client() as c:
    r = c.get("/cert/CERT-TEST-12345.pdf")
    check("first download returns a PDF", r.status_code == 200, f"got {r.status_code}")
    check("response is application/pdf", r.headers.get("Content-Type") == "application/pdf")
    check("response body starts with %PDF-", r.data.startswith(b"%PDF-"))
    cached_size = len(r.data)

# Verify the bytes got cached
conn = db.get_conn()
row = conn.execute("SELECT pdf_bytes FROM certificates WHERE serial='CERT-TEST-12345'").fetchone()
pdf_in_db = row["pdf_bytes"]
if pdf_in_db is not None and not isinstance(pdf_in_db, (bytes, bytearray)):
    pdf_in_db = bytes(pdf_in_db)
conn.close()
check("pdf_bytes column populated after first download",
      pdf_in_db is not None and len(pdf_in_db) > 0,
      f"db len={len(pdf_in_db) if pdf_in_db else 0}")
check("cached bytes equal what we just served",
      pdf_in_db is not None and len(pdf_in_db) == cached_size)

# Second download — cache HIT. To prove it hit the cache and didn't re-render,
# monkey-patch render_certificate_pdf to raise — if cert_download still works,
# we know it didn't call the renderer.
def must_not_be_called(*a, **k):
    raise AssertionError("renderer was called on a cache hit")
app_module.certificates.render_certificate_pdf = must_not_be_called

with app.test_client() as c:
    r = c.get("/cert/CERT-TEST-12345.pdf")
    check("second download succeeds without re-rendering (cache hit)",
          r.status_code == 200, f"got {r.status_code}")
    check("served bytes match the cached bytes",
          r.data == pdf_in_db)


# Cleanup
if os.path.exists("test_smoke_quota.db"):
    os.remove("test_smoke_quota.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
