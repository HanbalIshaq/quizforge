# Deploying QuizForge to Render.com (free)

This guide gets your QuizForge live on a public URL like
`https://quizforge-xyz.onrender.com` so students can take quizzes
without you running anything on your computer.

**Total time: ~15 minutes. No credit card required.**

You'll do two things:
1. Put the QuizForge folder on GitHub (free, web-only — no install)
2. Connect GitHub to Render and click Deploy

---

## Step 1 — Get a GitHub account & upload the folder

1. Open <https://github.com/signup> and create a free account.
2. Confirm your email.
3. After login, click the green **New** button (or go to <https://github.com/new>).
4. Fill in:
   - **Repository name:** `quizforge`
   - **Public** (selected) — required for Render free tier
   - Tick **Add a README file**
   - Click **Create repository**.
5. On the new repo page, click **Add file → Upload files**.
6. Open the folder `C:\Users\Aflat\QuizForge` in File Explorer.
7. Select **all files and subfolders** (Ctrl+A) but **exclude** these:
   - `venv` (huge, not needed)
   - `quizforge.db` (your local test data — Render makes its own)
   - `static/uploads` (will be empty anyway)
   - `.env` (if it exists — never upload secrets)
8. Drag them onto the GitHub upload page.
9. Scroll down, write the commit message "initial upload", click **Commit changes**.

You now have your code on GitHub. Copy the URL of the repo page
(e.g. `https://github.com/yourname/quizforge`) — you'll need it next.

---

## Step 2 — Sign up at Render.com

1. Go to <https://render.com> and click **Get Started**.
2. Sign in with GitHub (easiest) — this auto-links your repos.
3. Confirm your email if asked.

---

## Step 3 — Deploy

1. From the Render dashboard click the purple **+ New** button → **Web Service**.
2. Click **Connect** next to your `quizforge` repository.
   (If you don't see it, click **Configure GitHub App** and grant access.)
3. Render will read the `render.yaml` and pre-fill most settings. Confirm:
   - **Name:** `quizforge` (or anything; this becomes part of the URL)
   - **Branch:** `main`
   - **Runtime:** Python
   - **Build Command:** `pip install -r requirements.txt`
   - **Start Command:** `python app.py`
   - **Instance Type:** **Free**
4. Click **Create Web Service**.
5. Wait ~3 minutes. You'll see logs scroll. When you see lines like
   `Running on http://0.0.0.0:10000`, it's live.
6. Your URL is shown at the top of the page, e.g.
   `https://quizforge-abcd.onrender.com`. Open it.
7. Sign up inside QuizForge, create a quiz, and share that URL with students.

---

## Sharing with students

- **Async quiz:** open your quiz → copy the public link from the editor (e.g. `https://quizforge-abcd.onrender.com/q/2XUF4ZR`) and send it.
- **Live session:** click **Start Live Session** → tell students to go to
  `https://quizforge-abcd.onrender.com/j` and enter the 6-character code on screen.

---

## Free-tier caveats (read this!)

- **Sleeps after 15 minutes of no traffic.** First request after that takes ~30 seconds to wake. Students will see a brief "Loading…" — fine for occasional use.
- **750 hours of free instance time per month** — plenty for a single instance running 24/7.
- **Data lives on the server's disk.** It persists across normal sleeps. It is wiped on every redeploy (every time you push new code to GitHub). For lasting data, upgrade later or add a managed database.

---

## Updating later

Made a code change locally? Upload it the same way you did initially (Step 1 #5), and Render auto-redeploys within 2 minutes.

If anything goes wrong on Render, click **Logs** on the service page and paste the last 20 lines back to me.
