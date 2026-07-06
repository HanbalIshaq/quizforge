@echo off
REM Dev-only launcher: multi-worker PHP built-in server so the browser
REM preview doesn't deadlock on concurrent connections. NOT used in
REM production (production uses Apache + .htaccess).
set PHP_CLI_SERVER_WORKERS=6
"C:\xampp\php\php.exe" -S 127.0.0.1:8899 -t "C:\Users\Aflat\QuizForge\php" "C:\Users\Aflat\QuizForge\php\router-dev.php"
