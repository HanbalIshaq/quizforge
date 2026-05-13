# QuizForge

A self-hostable quiz, exam & live-poll platform. One app covers two modes:

- **Exam mode** — share a link, students take at their own pace, auto-graded.
- **Live mode** — host a real-time session (Kahoot/Mentimeter style) with a join code; see responses on a live dashboard.

## Features

- 10 question types: MCQ (single/multi), True/False, Short Answer, Long Answer/Essay, Fill-in-the-blank, Rating, Poll, Open-ended, Word Cloud
- Bulk import from `.docx`, `.csv`, or plain text (Aiken format)
- Real-time live session: join code, live participant list, live response aggregates, reveal answers, leaderboard
- Auto-grading for objective items; manual grading UI for essays
- Per-quiz settings: time limit, pass mark, max attempts, randomize Qs/options, require name/email, publish toggle
- Per-question and per-student analytics
- Export results to **CSV** and **Excel (.xlsx)**
- Multi-user — each teacher manages their own quizzes
- Self-contained: Python + SQLite, no external services

## Quick start (Windows)

```cmd
cd C:\Users\Aflat\QuizForge
run.bat
```

Then open <http://localhost:5000>.

## Quick start (macOS / Linux)

```bash
cd QuizForge
chmod +x run.sh
./run.sh
```

## Manual setup

```bash
python -m venv venv
# Windows:  venv\Scripts\activate
# Unix:     source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env       # edit SECRET_KEY
python app.py
```

## Usage

1. Open <http://localhost:5000> and sign up.
2. From the dashboard, type a title and click **+ Create**.
3. On the quiz editor:
   - Click **+ Add question** to build questions one at a time, OR
   - Expand **Bulk import** to paste/upload a Word/CSV/text file.
4. Configure settings (time limit, pass mark, etc.) and click **Save settings**.
5. **Share asynchronously** — copy the public link at the top of the editor and send it to students.
   They take the quiz at any time. Results appear under **Results** → exportable as CSV or Excel.
6. **Run a live session** — click **Start Live Session**. A 6-character **join code** appears.
   Students go to <http://yourdomain/j>, enter the code, type their name, and see questions as you push them.
   You see responses pour in live and reveal correct answers per question.

## Import formats

### Aiken text format (one block per question)

```
What is the capital of France?
A) Berlin
B) Madrid
C) Paris
D) Rome
ANSWER: C

The earth is flat.
ANSWER: False

Q: What does HTTP stand for?
A: Hypertext Transfer Protocol
```

### CSV

```
type,text,option1,option2,option3,option4,correct,points
mcq_single,Capital of France?,Berlin,Madrid,Paris,Rome,C,1
true_false,Earth is flat,,,,,False,1
short_answer,HTTP stands for?,,,,,Hypertext Transfer Protocol,1
mcq_multi,Pick prime numbers,2,3,4,5,A|B|D,2
```

### Word (.docx)

Same as the Aiken text format. The importer reads paragraphs and parses them like text.

## Deployment

Works on any host that runs Python 3.10+. Examples:

- **VPS / shared hosting:** run `gunicorn -k eventlet -w 1 app:app` behind nginx, or use `python app.py` for small loads.
- **Render / Railway / Fly.io:** create a Python service, point the start command to `python app.py`, expose port `5000`.
- **PythonAnywhere:** create a WSGI app pointing to `app:app`. (Live mode requires websockets — verify your host supports them.)

Set `SECRET_KEY` to a long random string in `.env` before going public.

## Tech stack

Flask · Flask-SocketIO · SQLite · Jinja2 · Tailwind CSS (CDN) · Socket.IO (CDN) · python-docx · openpyxl · bcrypt

## License

MIT — use it freely, modify it, sell it. No warranty.
