"""Smoke tests for the next round of fixes:
  #1 — /q/<code>/save now uses bulk queries + run_in_txn (no more N+1 / deadlock)
  #2 — Proctor snapshots stored as raw bytes (BYTEA), 33% smaller storage
  #3 — CSRF protection on /admin/* POST routes (with auto-injection JS)
"""
import os
import sys
import json
import base64
import io

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_save.db"
if os.path.exists("test_smoke_save.db"):
    os.remove("test_smoke_save.db")

import bcrypt
from app import app
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


TINY_JPEG_BYTES = bytes.fromhex(
    "ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffdb0043010909090c0b0c180d0d1832211c213232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232ffc00011080001000103012200021101031101ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffc4001f0100030101010101010101010000000000000102030405060708090a0bffc400b51100020102040403040705040400010277000102031104052131061241510761711322328108144291a1b1c109233352f0156272d10a162434e125f11718191a262728292a35363738393a434445464748494a535455565758595a636465666768696a737475767778797a82838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae2e3e4e5e6e7e8e9eaf2f3f4f5f6f7f8f9faffda000c03010002110311003f00fbfcffd9"
)


# Seed: teacher + a 5-question exam + draft attempt
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("admin@test.local", pw, "Admin", db.now_ts()),
)
conn.execute(
    """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,
                            is_published,camera_proctor)
       VALUES(1,'SaveTest','','SAVETEST','exam',?,?,1,1)""",
    (db.now_ts(), db.now_ts()),
)
for i in range(5):
    conn.execute(
        """INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position)
           VALUES(1,'mcq_single',?,?,?,1,?)""",
        (f"Q{i+1}", json.dumps(["A", "B", "C"]), json.dumps([0]), i),
    )
conn.commit()
# verify the bytea column was added by the migration
cols_row = conn.execute("PRAGMA table_info(proctor_snapshots)").fetchall()
col_names = {r[1] if isinstance(r, tuple) else r["name"] for r in cols_row}
conn.close()


print("Migration: image_bytes column added:")
check("image_bytes column exists on proctor_snapshots", "image_bytes" in col_names,
      f"columns={sorted(col_names)}")


print("\n#1 — /q/<code>/save uses bulk queries + run_in_txn:")
import inspect
from app import quiz_save_draft
src = inspect.getsource(quiz_save_draft)
check("uses db.run_in_txn", "db.run_in_txn" in src)
check("bulk SELECT (IN clause) over questions", "WHERE quiz_id=? AND id IN" in src)
check("bulk DELETE (IN clause) over question_ids", "DELETE FROM answers WHERE attempt_id=? AND question_id IN" in src)
check("batch INSERT via executemany", "conn.executemany" in src)
check("inserts sorted by question_id (FK lock order)", "key=lambda r: r[1]" in src)


print("\n#1 — end-to-end /save call:")
with app.test_client() as c:
    c.get("/q/SAVETEST")  # creates draft
    # Bulk save 5 answers
    qids = [r[0] if isinstance(r, tuple) else r["id"]
            for r in db.get_conn().execute("SELECT id FROM questions WHERE quiz_id=1 ORDER BY position").fetchall()]
    r = c.post("/q/SAVETEST/save", json={
        "attempt_id": 1,
        "student_name": "Saver",
        "student_email": "s@x.com",
        "answers": {str(qid): 0 for qid in qids},
    })
    check("bulk save returns 200", r.status_code == 200, f"got {r.status_code}: {r.data[:200]}")
    body = r.get_json()
    check("returns score = 5", body and body.get("score") == 5)
    n = db.get_conn().execute("SELECT COUNT(*) AS c FROM answers WHERE attempt_id=1").fetchone()["c"]
    check("all 5 answers written", n == 5, f"got {n}")
    # Second save (rewrite) — should DELETE old + INSERT new without duplication
    r = c.post("/q/SAVETEST/save", json={
        "attempt_id": 1,
        "answers": {str(qids[0]): 1, str(qids[1]): 2},  # change first two answers
    })
    check("rewrite-save returns 200", r.status_code == 200, f"got {r.status_code}")
    n = db.get_conn().execute("SELECT COUNT(*) AS c FROM answers WHERE attempt_id=1").fetchone()["c"]
    check("answers count still 5 (no duplicates)", n == 5, f"got {n}")
    # Confirm the new values stuck
    a = db.get_conn().execute(
        "SELECT answer FROM answers WHERE attempt_id=1 AND question_id=?", (qids[0],)
    ).fetchone()
    check("first answer updated to '1'", a["answer"] == "1")


print("\n#2 — Snapshots stored as raw bytes:")
b64_jpeg = base64.b64encode(TINY_JPEG_BYTES).decode()
with app.test_client() as c:
    # Take a snapshot
    r = c.post("/q/SAVETEST/proctor", json={
        "attempt_id": 1, "kind": "periodic", "image_b64": b64_jpeg,
    })
    check("snapshot accepted", r.status_code == 200, f"got {r.status_code}")
    # Verify it was stored as raw bytes, not base64 text
    row = db.get_conn().execute(
        "SELECT image_bytes, image_data FROM proctor_snapshots ORDER BY id DESC LIMIT 1"
    ).fetchone()
    raw = row["image_bytes"]
    if raw is not None and not isinstance(raw, (bytes, bytearray)):
        raw = bytes(raw)
    check("image_bytes is populated", raw is not None and len(raw) > 0,
          f"raw_len={len(raw) if raw else 0}")
    check("image_data (legacy column) is empty/null on new rows",
          row["image_data"] in (None, ""), f"image_data={row['image_data']!r}")
    check("stored bytes start with JPEG SOI marker",
          raw and raw.startswith(b"\xff\xd8\xff"))
    check("stored bytes are ~33% smaller than the base64 would be",
          len(raw) < len(b64_jpeg), f"raw={len(raw)} vs b64={len(b64_jpeg)}")


print("\n#2 — admin_snapshot_image serves raw bytes back as JPEG:")
with app.test_client() as c:
    c.post("/login", data={"email": "admin@test.local", "password": "pass1234"})
    snap_id = db.get_conn().execute("SELECT id FROM proctor_snapshots ORDER BY id DESC LIMIT 1").fetchone()["id"]
    r = c.get(f"/admin/snapshots/{snap_id}.jpg")
    check("snapshot image serves 200", r.status_code == 200, f"got {r.status_code}")
    check("content-type is image/jpeg", r.headers.get("Content-Type") == "image/jpeg")
    check("response body starts with JPEG SOI marker", r.data.startswith(b"\xff\xd8\xff"))
    check("served bytes match what we sent", r.data == TINY_JPEG_BYTES,
          f"got {len(r.data)} bytes, expected {len(TINY_JPEG_BYTES)}")


print("\n#2 — Legacy base64 snapshot still serves correctly:")
# Insert a legacy row directly (only image_data, no image_bytes)
conn = db.get_conn()
conn.execute(
    """INSERT INTO proctor_snapshots(attempt_id, captured_at, kind, notes, image_data)
       VALUES(1, ?, 'periodic', 'legacy', ?)""",
    (db.now_ts(), b64_jpeg),
)
conn.commit()
legacy_id = conn.execute("SELECT id FROM proctor_snapshots WHERE notes='legacy'").fetchone()["id"]
conn.close()
with app.test_client() as c:
    c.post("/login", data={"email": "admin@test.local", "password": "pass1234"})
    r = c.get(f"/admin/snapshots/{legacy_id}.jpg")
    check("legacy base64 snapshot still serves", r.status_code == 200)
    check("legacy response body is valid JPEG", r.data.startswith(b"\xff\xd8\xff"))


print("\n#3 — CSRF: state-changing admin POST without token is rejected:")
with app.test_client() as c:
    # Login + an initial GET to /admin so the session has a CSRF token minted
    # (this is what a real browser would do — load any page first).
    c.post("/login", data={"email": "admin@test.local", "password": "pass1234"})
    c.get("/admin")  # mint the CSRF token in session
    # POST to /admin/quizzes/new WITHOUT the _csrf field — should 403
    r = c.post("/admin/quizzes/new", data={"title": "No CSRF", "kind": "exam"})
    check("admin POST without _csrf is rejected with 403",
          r.status_code == 403, f"got {r.status_code}")

    # Now WITH the token
    home = c.get("/admin")
    # The csrf token is in the meta tag of base.html
    body = home.data.decode("utf-8")
    import re
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', body)
    check("admin page exposes csrf-token meta", m is not None)
    token = m.group(1) if m else ""
    r = c.post("/admin/quizzes/new", data={"title": "With CSRF", "kind": "exam", "_csrf": token})
    check("admin POST WITH _csrf succeeds", r.status_code in (200, 302, 303),
          f"got {r.status_code}")


print("\n#3 — CSRF: header-based token (for fetch/XHR) also works:")
with app.test_client() as c:
    c.post("/login", data={"email": "admin@test.local", "password": "pass1234"})
    home = c.get("/admin")
    body = home.data.decode("utf-8")
    import re
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', body)
    token = m.group(1) if m else ""
    # JSON POST with X-CSRF-Token header
    r = c.post("/admin/site/features", json={"key": "feature_billing", "value": True},
               headers={"X-CSRF-Token": token})
    check("admin JSON POST with X-CSRF-Token header succeeds",
          r.status_code in (200, 302), f"got {r.status_code}")
    # JSON POST without token
    r = c.post("/admin/site/features", json={"key": "feature_billing", "value": False})
    check("admin JSON POST WITHOUT token is rejected with 403",
          r.status_code == 403, f"got {r.status_code}")


print("\n#3 — CSRF: student/public POSTs are NOT protected (by design):")
with app.test_client() as c:
    # /q/<code>/* student endpoints should still work without a CSRF token
    r = c.post("/q/SAVETEST/save", json={"attempt_id": 1, "answers": {}})
    check("/q/<code>/save bypasses CSRF (student endpoint)",
          r.status_code == 200, f"got {r.status_code}")


# Cleanup
if os.path.exists("test_smoke_save.db"):
    os.remove("test_smoke_save.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
