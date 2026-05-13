"""End-to-end smoke test for live mode scoring."""
import json
import sys
import time
import urllib.parse
import requests
import socketio
import sqlite3


BASE = "http://localhost:5000"


def main():
    # 1. Sign up teacher (or sign in)
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "teacher@test.com", "password": "testtest", "name": "T"})
    s.post(f"{BASE}/login", data={"email": "teacher@test.com", "password": "testtest"})

    # 2. Create quiz
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Live test", "kind": "exam"})
    quiz_url = r.url
    quiz_id = int(quiz_url.rsplit("/", 1)[-1])
    print(f"Created quiz {quiz_id}")

    # 3. Bulk import 2 MCQ questions
    text = """What is 2+2?
A) 3
B) 4
C) 5
ANSWER: B

What is the capital of France?
A) Berlin
B) Paris
C) Rome
ANSWER: B
"""
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/import", data={"format": "text", "content": text})

    # 4. Start a live session
    r = s.post(f"{BASE}/admin/quizzes/{quiz_id}/live/start")
    session_id = int(r.url.rsplit("/", 1)[-1])
    print(f"Started live session {session_id}")

    # Get the join code
    conn = sqlite3.connect("quizforge.db")
    conn.row_factory = sqlite3.Row
    join_code = conn.execute("SELECT join_code FROM live_sessions WHERE id=?", (session_id,)).fetchone()["join_code"]
    print(f"Join code: {join_code}")

    # 5. Connect host socket (with teacher cookie)
    host = socketio.Client()
    host_state = {"current_q": None, "leaderboard": []}

    @host.on("show_question")
    def _show(data):
        host_state["current_q"] = data["question"]
        print(f"  [host] show Q{data['index']+1}/{data['total']}: {data['question']['text']}")

    @host.on("answer_stats")
    def _stats(data):
        agg = data["aggregate"]
        print(f"  [host] stats: total={agg['total']}, counts={agg['counts']}")

    @host.on("session_ended")
    def _end(data):
        host_state["leaderboard"] = data.get("leaderboard", [])
        print(f"  [host] SESSION ENDED. Leaderboard: {host_state['leaderboard']}")

    @host.on("error_msg")
    def _err(data):
        print(f"  [host] ERROR: {data}")

    cookie_str = "; ".join(f"{c.name}={c.value}" for c in s.cookies)
    host.connect(BASE, transports=["websocket"], headers={"Cookie": cookie_str})
    host.emit("join_host", {"session_id": session_id})
    time.sleep(0.5)

    # 6. Connect student socket
    student = socketio.Client()
    student_state = {"session_id": None, "current_q": None}

    @student.on("student_joined")
    def _joined(data):
        student_state["session_id"] = data["session_id"]
        print(f"  [student] joined session {data['session_id']}")

    @student.on("show_question")
    def _show_q(data):
        student_state["current_q"] = data["question"]
        print(f"  [student] sees Q: {data['question']['text']}")

    @student.on("answer_received")
    def _ack(data):
        print(f"  [student] answer ack: is_correct={data['is_correct']}")

    @student.on("error_msg")
    def _err(data):
        print(f"  [student] ERROR: {data}")

    student.connect(BASE, transports=["websocket"])
    student.emit("join_student", {"join_code": join_code, "name": "Ali"})
    time.sleep(0.5)

    # 7. Host: Next → Q1
    host.emit("host_next", {"session_id": session_id})
    time.sleep(0.5)

    # 8. Student: answer Q1 correctly (index 1 = "B) 4")
    student.emit("student_answer", {
        "session_id": student_state["session_id"],
        "question_id": student_state["current_q"]["id"],
        "answer": 1,
    })
    time.sleep(0.5)

    # 9. Host: Next → Q2
    host.emit("host_next", {"session_id": session_id})
    time.sleep(0.5)

    # 10. Student: answer Q2 correctly (index 1 = "B) Paris")
    student.emit("student_answer", {
        "session_id": student_state["session_id"],
        "question_id": student_state["current_q"]["id"],
        "answer": 1,
    })
    time.sleep(0.5)

    # 11. Host: Next → ends session
    host.emit("host_next", {"session_id": session_id})
    time.sleep(0.8)

    host.disconnect()
    student.disconnect()
    time.sleep(0.3)

    # Verify leaderboard
    lb = host_state["leaderboard"]
    print("\nFinal leaderboard:", lb)
    leaderboard_ok = lb and lb[0]["name"] == "Ali" and lb[0]["score"] == 2

    # Verify attempt was persisted to DB
    rows = list(conn.execute(
        "SELECT id, student_name, score, max_score, percentage, submitted_at, live_session_id "
        "FROM attempts WHERE quiz_id=?",
        (quiz_id,),
    ).fetchall())
    print(f"DB attempts for quiz {quiz_id}: {[dict(r) for r in rows]}")
    db_ok = len(rows) == 1 and rows[0]["student_name"] == "Ali" and rows[0]["score"] == 2 and rows[0]["submitted_at"] is not None and rows[0]["live_session_id"] == session_id

    # Verify the Results page renders the live attempt
    r = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results")
    results_ok = "Ali" in r.text and "Live" in r.text
    print(f"Results page contains 'Ali' and 'Live' badge: {results_ok}")

    if leaderboard_ok and db_ok and results_ok:
        print("ALL PASS")
        sys.exit(0)
    else:
        print(f"FAIL — leaderboard={leaderboard_ok}, db={db_ok}, results_page={results_ok}")
        sys.exit(1)


if __name__ == "__main__":
    main()
