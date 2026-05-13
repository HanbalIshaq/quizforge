#!/usr/bin/env bash
# QuizForge launcher for Unix.
set -e
if [ ! -d venv ]; then
  python3 -m venv venv
fi
source venv/bin/activate
pip install --disable-pip-version-check -q -r requirements.txt
if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example."
fi
echo "QuizForge running at http://localhost:5000"
python app.py
