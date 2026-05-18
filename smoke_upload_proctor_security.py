"""Security smoke tests for the audit findings.

Verifies all the lockdowns added in this audit:
  - /q/<code>/upload rejects .html / .svg / .exe / unknown extensions
  - /q/<code>/upload rejects content that doesn't match its extension (magic-byte)
  - /q/<code>/upload accepts a legitimate JPEG
  - /q/<code>/proctor rejects non-JPEG bytes
  - /q/<code>/proctor normalizes unknown 'kind' strings (no violation injection)
  - /q/<code>/proctor rejects submissions to already-finalized attempts
  - /q/<code>/violation rejects unknown 'type' strings (normalized to 'other')
  - /q/<code>/violation rejects submissions to already-finalized attempts
  - All hardened endpoints 404 on unpublished quizzes
"""
import os
import sys
import base64
import io

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_upload.db"
if os.path.exists("test_smoke_upload.db"):
    os.remove("test_smoke_upload.db")

import bcrypt
from app import app
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


# Smallest legal JPEG — header + minimal chunks. Used for both upload AND
# snapshot tests where we need bytes that pass the magic-byte check.
TINY_JPEG_BYTES = bytes.fromhex(
    "ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffdb0043010909090c0b0c180d0d1832211c213232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232ffc00011080001000103012200021101031101ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffc4001f0100030101010101010101010000000000000102030405060708090a0bffc400b51100020102040403040705040400010277000102031104052131061241510761711322328108144291a1b1c109233352f0156272d10a162434e125f11718191a262728292a35363738393a434445464748494a535455565758595a636465666768696a737475767778797a82838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae2e3e4e5e6e7e8e9eaf2f3f4f5f6f7f8f9faffda000c03010002110311003f00fbfcffd9"
)


# Seed a published proctored quiz, an unpublished one, and two attempts
# (one draft + one finalized) for the various reject-path checks.
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("teacher@test.local", pw, "Teacher", db.now_ts()),
)
conn.execute(
    """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,
                            is_published,camera_proctor,violation_limit)
       VALUES(1,'Sec Test','','SECTEST','exam',?,?,1,1,3)""",
    (db.now_ts(), db.now_ts()),
)
conn.execute(
    """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,is_published)
       VALUES(1,'Draft','','UNPUBLI','exam',?,?,0)""",
    (db.now_ts(), db.now_ts()),
)
conn.execute(
    """INSERT INTO attempts(quiz_id,student_name,started_at) VALUES(1,'Tester',?)""",
    (db.now_ts(),),
)
conn.execute(
    """INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,score,max_score,percentage)
       VALUES(1,'Done',?,?,5,5,100)""",
    (db.now_ts(), db.now_ts()),
)
conn.commit()
conn.close()


print("File upload — extension allowlist:")
with app.test_client() as c:
    bad_html = io.BytesIO(b"<script>alert(1)</script>")
    r = c.post("/q/SECTEST/upload", data={"file": (bad_html, "evil.html")})
    check("rejects .html (stored-XSS risk)", r.status_code == 400, f"got {r.status_code}")

    bad_svg = io.BytesIO(b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
    r = c.post("/q/SECTEST/upload", data={"file": (bad_svg, "evil.svg")})
    check("rejects .svg (stored-XSS risk)", r.status_code == 400)

    bad_exe = io.BytesIO(b"MZ\x90\x00malware")
    r = c.post("/q/SECTEST/upload", data={"file": (bad_exe, "malware.exe")})
    check("rejects .exe", r.status_code == 400)

    r = c.post("/q/SECTEST/upload", data={"file": (io.BytesIO(b"x"), "weird.xyz")})
    check("rejects unknown extension", r.status_code == 400)


print("\nFile upload — magic-byte check:")
with app.test_client() as c:
    fake_png = io.BytesIO(b"<html><script>alert(1)</script></html>")
    r = c.post("/q/SECTEST/upload", data={"file": (fake_png, "fake.png")})
    check("rejects HTML disguised as .png (magic-byte caught)",
          r.status_code == 400, f"got {r.status_code}")

    real = io.BytesIO(TINY_JPEG_BYTES)
    r = c.post("/q/SECTEST/upload", data={"file": (real, "ok.jpg")})
    check("accepts a legit JPEG", r.status_code == 200, f"got {r.status_code}")
    body = r.get_json()
    check("returns a url", body and body.get("url"))
    check("returns the size", body and body.get("size") == len(TINY_JPEG_BYTES))


print("\nFile upload — published quiz only:")
with app.test_client() as c:
    real = io.BytesIO(TINY_JPEG_BYTES)
    r = c.post("/q/UNPUBLI/upload", data={"file": (real, "ok.jpg")})
    check("rejects upload to unpublished quiz", r.status_code == 404)


print("\nProctor snapshot — validation:")
b64_jpeg = base64.b64encode(TINY_JPEG_BYTES).decode()
b64_html = base64.b64encode(b"<html>not a jpeg</html>").decode()

with app.test_client() as c:
    r = c.post("/q/SECTEST/proctor", json={
        "attempt_id": 1, "kind": "periodic", "image_b64": b64_jpeg,
    })
    check("accepts valid JPEG for in-progress attempt",
          r.status_code == 200, f"got {r.status_code}: {r.data[:200]}")

    r = c.post("/q/SECTEST/proctor", json={
        "attempt_id": 1, "kind": "periodic", "image_b64": b64_html,
    })
    check("rejects non-JPEG bytes", r.status_code == 400)

    n_before = db.get_conn().execute(
        "SELECT COUNT(*) AS c FROM violations WHERE attempt_id=1 AND type='evil_kind_injection'"
    ).fetchone()["c"]
    r = c.post("/q/SECTEST/proctor", json={
        "attempt_id": 1, "kind": "evil_kind_injection", "image_b64": b64_jpeg,
    })
    check("accepts request with unknown kind (normalized)", r.status_code == 200)
    n_after = db.get_conn().execute(
        "SELECT COUNT(*) AS c FROM violations WHERE attempt_id=1 AND type='evil_kind_injection'"
    ).fetchone()["c"]
    check("unknown kind didn't create a violation row", n_after == n_before)

    r = c.post("/q/SECTEST/proctor", json={
        "attempt_id": 2, "kind": "periodic", "image_b64": b64_jpeg,
    })
    check("rejects snapshot to finalized attempt", r.status_code == 400)


print("\nViolation endpoint — type allowlist:")
with app.test_client() as c:
    r = c.post("/q/SECTEST/violation", json={
        "attempt_id": 1, "type": "tab_switch", "details": "x",
    })
    check("accepts known type 'tab_switch'", r.status_code == 200)

    r = c.post("/q/SECTEST/violation", json={
        "attempt_id": 1, "type": "<script>alert(1)</script>", "details": "x",
    })
    body = r.get_json()
    check("accepts unknown type (normalized)", r.status_code == 200 and body and body["ok"])
    rows = db.get_conn().execute(
        "SELECT type FROM violations WHERE attempt_id=1 ORDER BY id DESC LIMIT 1"
    ).fetchall()
    check("the bad type was rewritten to 'other'",
          rows and rows[0]["type"] == "other",
          f"got type={rows[0]['type'] if rows else None}")

    r = c.post("/q/SECTEST/violation", json={
        "attempt_id": 2, "type": "tab_switch", "details": "x",
    })
    check("rejects violation to finalized attempt", r.status_code == 400)


# Cleanup
if os.path.exists("test_smoke_upload.db"):
    os.remove("test_smoke_upload.db")
import shutil
if os.path.isdir("static/uploads/quiz_1"):
    shutil.rmtree("static/uploads/quiz_1", ignore_errors=True)

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
