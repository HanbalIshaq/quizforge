"""End-to-end test for the new Polls feature."""
import re
import sys
import requests

BASE = "http://localhost:5000"


def main():
    s = requests.Session()
    s.post(f"{BASE}/register", data={"email": "p@t.com", "password": "testtest", "name": "P"})
    s.post(f"{BASE}/login", data={"email": "p@t.com", "password": "testtest"})

    # Create a poll
    r = s.post(f"{BASE}/admin/quizzes/new", data={"title": "Lunch poll", "kind": "poll"})
    quiz_id = int(r.url.rsplit("/", 1)[-1])
    print(f"Poll created: id={quiz_id}")

    # Add a mixed set of poll-friendly questions: MCQ + NPS + open-text
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "mcq_single", "text": "What's for lunch?",
        "options": ["Pizza", "Sushi", "Tacos", "Salad"], "correct_answers": [], "points": 0,
    })
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "nps", "text": "How likely are you to recommend our cafe?",
        "options": [], "correct_answers": [], "points": 0,
    })
    s.post(f"{BASE}/admin/quizzes/{quiz_id}/questions", json={
        "type": "open_ended", "text": "Any feedback?",
        "options": [], "correct_answers": [], "points": 0,
    })
    print("3 questions added (MCQ, NPS, open-ended)")

    # Editor page should NOT show anti-cheating section for polls
    re_page = s.get(f"{BASE}/admin/quizzes/{quiz_id}").text
    anti_cheat_hidden = "Anti-cheating" not in re_page
    print(f"Anti-cheat section hidden for poll: {anti_cheat_hidden}")
    pass_mark_hidden = "Pass mark" not in re_page
    print(f"Pass-mark field hidden for poll: {pass_mark_hidden}")

    # Get the share code
    code = re.search(r"/q/([A-Z0-9]+)", re_page).group(1)
    print(f"Share code: {code}")

    # Collect 5 fake responses
    questions = [int(x) for x in re.findall(r'data-qid="(\d+)"', re_page)]
    if len(questions) < 3:
        # questions order in the file — fetch the public take-quiz page to get them in display order
        public = requests.get(f"{BASE}/q/{code}").text
        questions = [int(x) for x in re.findall(r'data-qid="(\d+)"', public)]
    q_mcq, q_nps, q_open = questions[0], questions[1], questions[2]

    responses = [
        ("Alice",  0, 10, "Loved the pizza"),
        ("Bob",    2,  9, "More tacos please"),
        ("Carol",  0,  8, "Pretty good overall"),
        ("Dave",   1,  5, "Sushi was meh"),
        ("Eve",    0,  3, "Pizza was burnt"),
    ]
    for (name, mcq, nps, txt) in responses:
        st = requests.Session()
        st.get(f"{BASE}/q/{code}")  # creates draft
        st.post(f"{BASE}/q/{code}", data={
            "student_name": name,
            f"q_{q_mcq}": str(mcq),
            f"q_{q_nps}": str(nps),
            f"q_{q_open}": txt,
        }, allow_redirects=False)
    print(f"Submitted {len(responses)} responses")

    # Verify the poll RESULTS PAGE is the new one (not the exam table)
    rresults = s.get(f"{BASE}/admin/quizzes/{quiz_id}/results").text
    poll_dashboard = ("Total responses" in rresults and "Real-time aggregated" in rresults)
    print(f"Poll-specific results dashboard rendered: {poll_dashboard}")
    nps_widget = "NPS Score" in rresults and "Promoters (9" in rresults
    print(f"NPS widget rendered: {nps_widget}")
    has_bar_chart = "bg-brand-500" in rresults  # MCQ bars
    print(f"MCQ horizontal bar charts: {has_bar_chart}")
    has_word_cloud = "Loved the pizza" in rresults  # raw response visible
    print(f"Open-text responses captured: {has_word_cloud}")
    has_copy_btn = "Copy link" in rresults
    print(f"Copy-link share button: {has_copy_btn}")

    # Dashboard filter
    rd_polls = s.get(f"{BASE}/admin?kind=poll").text
    poll_only = "Lunch poll" in rd_polls
    print(f"Dashboard filter shows the poll: {poll_only}")
    rd_exams = s.get(f"{BASE}/admin?kind=exam").text
    exams_filter_excludes_poll = "Lunch poll" not in rd_exams
    print(f"Exam filter excludes the poll: {exams_filter_excludes_poll}")

    all_ok = (anti_cheat_hidden and pass_mark_hidden
              and poll_dashboard and nps_widget and has_bar_chart and has_word_cloud
              and has_copy_btn and poll_only and exams_filter_excludes_poll)
    print("\nPASS" if all_ok else "\nFAIL")
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
