"""Smoke test for the attempt_detail TypeError fix + low-resource caching.

Verifies:
  - attempt_detail renders without crashing even when answer.answer is stored
    as a list of strings (`["0","1"]`) instead of ints
  - opt_label() helper handles string/int/None/out-of-range/empty options
  - _coerce_to_int and _coerce_to_int_list handle all the messy inputs
  - The int_list Jinja filter works in templates
  - Settings cache: 2nd identical call doesn't hit the DB
  - Settings cache: _settings_set invalidates the cache
"""
import os
import sys
import json
import time

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_detail.db"
if os.path.exists("test_smoke_detail.db"):
    os.remove("test_smoke_detail.db")

import bcrypt
from app import app, opt_label, _coerce_to_int, _coerce_to_int_list, _settings_set, _SETTINGS_CACHE
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


# Seed a quiz with an MCQ-multi where the stored answer is a list of STRINGS
# (the exact shape that crashed the production attempt_detail page).
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("teacher@test.local", pw, "Teacher", db.now_ts()),
)
conn.execute(
    """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,is_published)
       VALUES(1,'Crash Repro','','REPRO01','exam',?,?,1)""",
    (db.now_ts(), db.now_ts()),
)
# Question: MCQ multi with correct=["0","1"] (STRING indices, the legacy bug)
conn.execute(
    """INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position)
       VALUES(1,'mcq_multi','Pick A and B',?,?,2,0)""",
    (json.dumps(["A", "B", "C", "D"]), json.dumps(["0", "1"])),  # strings, not ints
)
# Also a single-MCQ with correct=["2"] (string index)
conn.execute(
    """INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position)
       VALUES(1,'mcq_single','Pick C',?,?,1,1)""",
    (json.dumps(["A", "B", "C"]), json.dumps(["2"])),
)
# Attempt + answers where the saved answer is also a list of strings
conn.execute(
    """INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,score,max_score,percentage)
       VALUES(1,'Student X',?,?,3,3,100)""",
    (db.now_ts(), db.now_ts()),
)
conn.execute(
    """INSERT INTO answers(attempt_id,question_id,answer,is_correct,points_earned,graded)
       VALUES(1,1,?,1,2,1)""",
    (json.dumps(["0", "1"]),),  # again, strings
)
conn.execute(
    """INSERT INTO answers(attempt_id,question_id,answer,is_correct,points_earned,graded)
       VALUES(1,2,?,1,1,1)""",
    (json.dumps("2"),),  # string scalar
)
conn.commit()
conn.close()


print("attempt_detail renders with string-index legacy data:")
with app.test_client() as c:
    c.post("/login", data={"email": "teacher@test.local", "password": "pass1234"})
    r = c.get("/admin/quizzes/1/attempts/1")
    check("page returns 200 (was 500 before fix)", r.status_code == 200, f"got {r.status_code}")
    body = r.data.decode("utf-8", errors="replace")
    check("rendered option labels", "A" in body and "B" in body and "C" in body)
    check("rendered the question text", "Pick A and B" in body)
    check("no TypeError leaked to the page", "TypeError" not in body)


print("\nopt_label() helper handles bad inputs:")
check("int index in range", opt_label(["A", "B", "C"], 1) == "B")
check("string index in range", opt_label(["A", "B", "C"], "1") == "B")
check("string with whitespace", opt_label(["A", "B", "C"], " 1 ") == "B")
check("out-of-range int returns the index", opt_label(["A", "B"], 5) == 5)
check("out-of-range string returns the index", opt_label(["A", "B"], "5") == "5")
check("non-numeric string returns as-is", opt_label(["A", "B"], "xyz") == "xyz")
check("None index returns empty string", opt_label(["A", "B"], None) == "")
check("None options returns the index", opt_label(None, 0) == 0)
check("empty options returns the index", opt_label([], 0) == 0)
check("float index coerces", opt_label(["A", "B", "C"], 1.0) == "B")


print("\n_coerce_to_int / _coerce_to_int_list:")
check("coerce '5' to 5", _coerce_to_int("5") == 5)
check("coerce '5.7' to 5", _coerce_to_int("5.7") == 5)
check("coerce 5 to 5", _coerce_to_int(5) == 5)
check("coerce 'abc' returns default", _coerce_to_int("abc", default=-1) == -1)
check("coerce None returns default", _coerce_to_int(None, default=-1) == -1)
check("coerce list of mixed", _coerce_to_int_list(["0", 1, "2", "xyz", None, 3]) == [0, 1, 2, 3])
check("coerce None list to empty", _coerce_to_int_list(None) == [])


print("\nint_list Jinja filter:")
with app.test_request_context():
    rendered = app.jinja_env.from_string(
        "{{ ['0','1','xyz',2]|int_list|join(',') }}"
    ).render()
    check("filter works in templates", rendered == "0,1,2", f"got {rendered!r}")


print("\nSettings cache (low-resource win):")
# Clear cache to start clean
_SETTINGS_CACHE.clear()

# Count connection opens during settings reads
calls = {"n": 0}
orig_get_conn = db.get_conn


def counting_get_conn():
    calls["n"] += 1
    return orig_get_conn()


db.get_conn = counting_get_conn
try:
    from app import features_all
    # First call: cache miss, should open one connection
    features_all()
    first = calls["n"]
    # Second call: cache hit, should open ZERO connections
    features_all()
    second_delta = calls["n"] - first
    check("first features_all hits DB", first >= 1, f"opened {first} conn")
    check("second features_all is cached (0 new conn)", second_delta == 0,
          f"opened {second_delta} extra conn")

    # _settings_set should invalidate the cache for that key
    _settings_set("feature_billing", "1")
    # next read should reflect the new value WITHOUT a DB hit (we set the cache in _settings_set)
    n_before_3rd = calls["n"]
    flags = features_all()
    check("set value visible immediately after _settings_set", flags["feature_billing"] is True)
    # We invalidated the cache for ONE key, the other 7 stay cached, so 0 extra DB calls
    check("partial cache reuse after _settings_set", (calls["n"] - n_before_3rd) <= 1,
          f"opened {calls['n'] - n_before_3rd} extra conn")
finally:
    db.get_conn = orig_get_conn


# Cleanup
if os.path.exists("test_smoke_detail.db"):
    os.remove("test_smoke_detail.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
