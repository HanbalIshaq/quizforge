"""Smoke test for the deadlock-retry submit fix.

Verifies:
  - db.is_retryable_db_error correctly classifies psycopg-style errors
  - db.run_in_txn retries on retryable errors and gives up after N tries
  - db.run_in_txn does NOT retry on programming errors
  - The submit path uses run_in_txn under the hood
  - INSERT rows are sorted by question_id (consistent FK lock order)
  - A real submit still works end-to-end
  - A submit where the work function fakes a deadlock recovers on retry
"""
import os
import sys
import json
import time

os.environ.pop("DATABASE_URL", None)
os.environ["DATABASE_PATH"] = "test_smoke_deadlock.db"
if os.path.exists("test_smoke_deadlock.db"):
    os.remove("test_smoke_deadlock.db")

import bcrypt
from app import app
import db

failures = []


def check(label, ok, detail=""):
    mark = "OK" if ok else "FAIL"
    print(f"  [{mark}] {label}" + (f"   ({detail})" if detail else ""))
    if not ok:
        failures.append(label)


print("is_retryable_db_error classifies correctly:")


# Fake psycopg-style exceptions
class FakeDeadlock(Exception):
    sqlstate = "40P01"


class FakeSerFailure(Exception):
    sqlstate = "40001"


class FakeNonRetryable(Exception):
    sqlstate = "23505"  # unique_violation


class FakeByName(Exception):
    pass


FakeByName.__name__ = "DeadlockDetected"

check("sqlstate 40P01 -> retryable", db.is_retryable_db_error(FakeDeadlock()))
check("sqlstate 40001 -> retryable", db.is_retryable_db_error(FakeSerFailure()))
check("sqlstate 23505 -> NOT retryable", not db.is_retryable_db_error(FakeNonRetryable()))
check("class name DeadlockDetected -> retryable", db.is_retryable_db_error(FakeByName()))
check("plain ValueError -> NOT retryable", not db.is_retryable_db_error(ValueError("x")))
check("error message 'deadlock detected' -> retryable",
      db.is_retryable_db_error(Exception("deadlock detected on row")))


print("\nrun_in_txn retries on retryable errors:")
attempts = {"n": 0}


def flaky_work(conn):
    attempts["n"] += 1
    if attempts["n"] < 3:
        raise FakeDeadlock()
    return "ok"


# Use very short backoff for tests
result = db.run_in_txn(flaky_work, retries=5, backoff=0.01)
check("succeeded after 3 attempts", result == "ok" and attempts["n"] == 3,
      f"result={result}, attempts={attempts['n']}")


print("\nrun_in_txn gives up after `retries`:")
attempts2 = {"n": 0}


def always_deadlock(conn):
    attempts2["n"] += 1
    raise FakeDeadlock()


try:
    db.run_in_txn(always_deadlock, retries=3, backoff=0.01)
    check("raised after exhausting retries", False, "no exception")
except FakeDeadlock:
    check("raised after exhausting retries", True)
check("tried exactly `retries` times", attempts2["n"] == 3, f"tried {attempts2['n']}")


print("\nrun_in_txn does NOT retry programming errors:")
attempts3 = {"n": 0}


def buggy(conn):
    attempts3["n"] += 1
    raise ValueError("not a transient error")


try:
    db.run_in_txn(buggy, retries=5, backoff=0.01)
    check("re-raised ValueError immediately", False, "no exception")
except ValueError:
    check("re-raised ValueError immediately", True)
check("ran exactly once (no retry)", attempts3["n"] == 1, f"ran {attempts3['n']} times")


print("\nSubmit uses run_in_txn:")
import inspect
src = inspect.getsource(app.view_functions["take_quiz"])
check("take_quiz no longer inlines DELETE+INSERT", "DELETE FROM answers WHERE attempt_id=?" not in src)
check("take_quiz delegates to _submit_quiz_with_retry", "_submit_quiz_with_retry" in src)

from app import _submit_quiz_with_retry
src2 = inspect.getsource(_submit_quiz_with_retry)
check("_submit_quiz_with_retry uses db.run_in_txn", "db.run_in_txn" in src2)
check("inserts are sorted by question_id", "sorted(questions, key=lambda q: q[\"id\"])" in src2)
check("skips DELETE when no existing answers", "SELECT 1 FROM answers WHERE attempt_id" in src2)


print("\nEnd-to-end submit still works:")
conn = db.get_conn()
pw = bcrypt.hashpw(b"pass1234", bcrypt.gensalt()).decode()
conn.execute(
    "INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ("teacher@test.local", pw, "Teacher", db.now_ts()),
)
conn.execute(
    """INSERT INTO quizzes(user_id,title,description,share_code,kind,created_at,updated_at,is_published)
       VALUES(1,'DL Repro','','DLREPRO','exam',?,?,1)""",
    (db.now_ts(), db.now_ts()),
)
for i in range(3):
    conn.execute(
        """INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position)
           VALUES(1,'mcq_single',?,?,?,1,?)""",
        (f"Q{i+1}", json.dumps(["A", "B"]), json.dumps([0]), i),
    )
conn.commit()
conn.close()

with app.test_client() as c:
    c.get("/q/DLREPRO")  # create draft
    qs = db.get_conn().execute("SELECT id FROM questions WHERE quiz_id=1 ORDER BY id").fetchall()
    data = {"student_name": "Test", "student_email": "t@x.com"}
    for q in qs:
        data[f"q_{q['id']}"] = "0"
    r = c.post("/q/DLREPRO", data=data, follow_redirects=False)
    check("submit returns redirect", r.status_code in (302, 303), f"got {r.status_code}")
    conn = db.get_conn()
    n = conn.execute("SELECT COUNT(*) AS n FROM answers WHERE attempt_id=1").fetchone()["n"]
    check("answers were saved", n == 3, f"got {n}")
    a = conn.execute("SELECT score, max_score FROM attempts WHERE id=1").fetchone()
    check("score correctly computed", a["score"] == 3.0 and a["max_score"] == 3.0,
          f"score={a['score']}, max={a['max_score']}")
    conn.close()


print("\nSubmit recovers if the inner work fakes a deadlock once:")
# Monkey-patch _submit_quiz_with_retry's inner work via run_in_txn's first call
calls = {"n": 0}
orig_run = db.run_in_txn


def flaky_run(work, retries=3, backoff=0.1):
    def wrapped_work(conn):
        calls["n"] += 1
        if calls["n"] == 1:
            raise FakeDeadlock()
        return work(conn)
    return orig_run(wrapped_work, retries=retries, backoff=0.01)


db.run_in_txn = flaky_run
try:
    # Need a fresh attempt — submit another time
    with app.test_client() as c:
        c.get("/q/DLREPRO")
        data2 = {"student_name": "Test 2", "student_email": "t2@x.com"}
        for q in qs:
            data2[f"q_{q['id']}"] = "0"
        r2 = c.post("/q/DLREPRO", data=data2, follow_redirects=False)
        check("second submit retried past deadlock and returned redirect",
              r2.status_code in (302, 303), f"got {r2.status_code}")
        check("flaky_run was called at least twice (once failed, once succeeded)",
              calls["n"] >= 2, f"called {calls['n']} times")
finally:
    db.run_in_txn = orig_run


# Cleanup
if os.path.exists("test_smoke_deadlock.db"):
    os.remove("test_smoke_deadlock.db")

print()
if failures:
    print(f"FAILURES ({len(failures)}):")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)
print("All checks passed.")
