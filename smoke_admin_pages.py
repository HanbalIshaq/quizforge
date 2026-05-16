"""Smoke test for the admin-page Internal Server Error fix.
Verifies:
  - DB connection retry / connect_timeout is in place
  - features_all() now uses a single SELECT IN(...) query
  - 500 handler returns a friendly page with error ID
  - super-admin sees the traceback, regular users don't
  - admin dashboard / site features / live list / bank still 200
"""
import os
import sys

# Force SQLite — never touch the real Neon DB during smoke tests
os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_admin.db"
if os.path.exists("test_smoke_admin.db"):
    os.remove("test_smoke_admin.db")

import bcrypt
from app import app, db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


# --- seed a super-admin user ---
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("admin@test.local", pw, "Admin", db.now_ts()),
)
conn.commit()
conn.close()


# --- register a route that intentionally crashes, to test the 500 handler ---
@app.route("/__smoke_crash__")
def __smoke_crash__():
    raise RuntimeError("intentional smoke test crash, ignore")


with app.test_client() as c:
    print("login + admin pages:")
    r = c.post("/login", data={"email": "admin@test.local", "password": "pass1234"})
    check("login redirects", r.status_code in (302, 303), f"got {r.status_code}")

    pages = [
        ("/admin",                "admin dashboard"),
        ("/admin/site/features",  "site features"),
        ("/admin/site/users",     "site users"),
        ("/admin/live",           "live list"),
        ("/admin/bank",           "bank list"),
    ]
    for path, name in pages:
        r = c.get(path)
        check(f"{name} {path}", r.status_code == 200, f"got {r.status_code}")

    print("\n500 error handler:")
    r = c.get("/__smoke_crash__")
    check("crash returns 500", r.status_code == 500, f"got {r.status_code}")
    body = r.data.decode("utf-8", errors="replace")
    check("response contains error_id", "err_" in body)
    check("super-admin sees traceback", "Traceback" in body or "RuntimeError" in body)
    check("response is the friendly HTML page", "Something went wrong" in body)

    print("\n404 still works (HTTPExceptions pass through):")
    r = c.get("/does-not-exist-xyz")
    check("missing route -> 404", r.status_code == 404, f"got {r.status_code}")

    # --- log out, hit the crash again — non-super should not see the traceback ---
    print("\n500 for non-super:")
    c.get("/logout")
    # Make a non-super user
    conn = db.get_conn()
    pw2 = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
    conn.execute(
        "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,0,1)",
        ("regular@test.local", pw2, "Regular", db.now_ts()),
    )
    conn.commit()
    conn.close()
    c.post("/login", data={"email": "regular@test.local", "password": "pass1234"})
    r = c.get("/__smoke_crash__")
    check("crash returns 500 (regular user)", r.status_code == 500, f"got {r.status_code}")
    body2 = r.data.decode("utf-8", errors="replace")
    check("error_id still present", "err_" in body2)
    check("traceback hidden from non-super", "RuntimeError" not in body2 and "Traceback" not in body2)


# --- features_all() should be at most a single connection now ---
# (Was 8 before the IN(...) collapse fix. Now it's 1 on cache miss, 0 on hit.)
print("\nfeatures_all() single-query check:")
from app import features_all, FEATURE_DEFAULTS, _SETTINGS_CACHE

calls = {"n": 0}
orig_get_conn = db.get_conn


def counting_get_conn():
    calls["n"] += 1
    return orig_get_conn()


# Clear the per-process settings cache so we can observe the cache-miss path
_SETTINGS_CACHE.clear()
db.get_conn = counting_get_conn
try:
    flags = features_all()
finally:
    db.get_conn = orig_get_conn
check("features_all uses at most 1 connection (was 8)", calls["n"] <= 1,
      f"opened {calls['n']} connections")
check("features_all returns all keys", set(flags) == set(FEATURE_DEFAULTS))


# --- DB retry config visible ---
print("\nDB retry config:")
check("PG_CONNECT_TIMEOUT >= 5", db._PG_CONNECT_TIMEOUT >= 5)
check("PG_CONNECT_RETRIES >= 2", db._PG_CONNECT_RETRIES >= 2)


# Cleanup
if os.path.exists("test_smoke_admin.db"):
    os.remove("test_smoke_admin.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
