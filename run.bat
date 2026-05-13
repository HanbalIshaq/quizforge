@echo off
REM QuizForge launcher for Windows.
setlocal ENABLEDELAYEDEXPANSION
cd /d "%~dp0"

echo ==========================================
echo  QuizForge starting up
echo ==========================================

REM Find Python
where python >nul 2>&1
if errorlevel 1 (
  echo ERROR: Python is not on your PATH.
  echo Install Python 3.10+ from https://www.python.org/downloads/
  echo Make sure to tick "Add Python to PATH" during install.
  pause
  exit /b 1
)

REM Create venv if missing
if not exist "venv\Scripts\python.exe" (
  echo Creating virtual environment...
  python -m venv venv
  if errorlevel 1 (
    echo ERROR: Failed to create virtual environment.
    pause
    exit /b 1
  )
)

REM Install dependencies
echo Installing/updating dependencies (this is fast after the first run)...
"venv\Scripts\python.exe" -m pip install --disable-pip-version-check -q -r requirements.txt
if errorlevel 1 (
  echo ERROR: Failed to install dependencies.
  pause
  exit /b 1
)

REM Create .env if missing
if not exist ".env" (
  copy /Y .env.example .env >nul
  echo Created .env from .env.example.
)

echo.
echo ==========================================
echo  QuizForge is running.
echo  Open http://localhost:5000 in your browser
echo  Press CTRL+C in this window to stop.
echo ==========================================
echo.

"venv\Scripts\python.exe" app.py
echo.
echo Server stopped. (If this was unexpected, the error is shown above.)
pause
