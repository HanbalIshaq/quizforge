<?php
/**
 * Portable database layer for QuizForge (PHP edition).
 *
 * Mirrors the Python db.py design: pick the engine from config, expose one
 * simple API so the rest of the app never cares which database is running.
 *
 *   - mysql  : shared hosting / production
 *   - sqlite : zero-setup local testing
 *
 * Uses PDO with `?` placeholders (identical syntax on both engines).
 * A tiny type helper (col_*) emits engine-appropriate column DDL so the
 * schema file stays single-source.
 */

declare(strict_types=1);

class DB
{
    private static ?PDO $pdo = null;
    private static string $driver = 'mysql';

    /** Boot the connection from the config array. Call once at startup. */
    public static function boot(array $cfg): void
    {
        self::$driver = $cfg['db_driver'] ?? 'mysql';

        if (self::$driver === 'sqlite') {
            $path = $cfg['sqlite_path'] ?? (__DIR__ . '/../data/quizforge.sqlite');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            self::$pdo = new PDO('sqlite:' . $path);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['db_host'] ?? 'localhost',
                (int)($cfg['db_port'] ?? 3306),
                $cfg['db_name'] ?? 'quizforge',
                $cfg['db_charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO(
                $dsn,
                $cfg['db_user'] ?? 'root',
                $cfg['db_pass'] ?? ''
            );
        }

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * Connect to the MySQL SERVER (no dbname) — used by the installer to
     * create the database if it doesn't exist yet.
     */
    public static function serverConnect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $cfg['db_host'] ?? 'localhost',
            (int)($cfg['db_port'] ?? 3306),
            $cfg['db_charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $cfg['db_user'] ?? 'root', $cfg['db_pass'] ?? '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('DB not booted. Call DB::boot() first.');
        }
        return self::$pdo;
    }

    /** Run a statement, return the PDOStatement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch a single row (assoc array) or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch all rows as an array of assoc arrays. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Fetch a single scalar value (first column of first row). */
    public static function scalar(string $sql, array $params = [])
    {
        $val = self::run($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    /** INSERT and return the new row id. */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Run many inserts in one transaction (batch). */
    public static function insertMany(string $sql, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $pdo = self::pdo();
        $stmt = $pdo->prepare($sql);
        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            foreach ($rows as $params) {
                $stmt->execute($params);
            }
            if ($ownTxn) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function begin(): void  { self::pdo()->beginTransaction(); }
    public static function commit(): void { self::pdo()->commit(); }
    public static function rollback(): void { if (self::pdo()->inTransaction()) self::pdo()->rollBack(); }

    // ── engine-aware column type helpers (used by schema.php) ─────────────

    /** Auto-increment primary key column definition. */
    public static function colPk(): string
    {
        return self::$driver === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'INT AUTO_INCREMENT PRIMARY KEY';
    }

    /** Binary blob column (for JPEG snapshots, cached PDFs). */
    public static function colBlob(): string
    {
        return self::$driver === 'sqlite' ? 'BLOB' : 'LONGBLOB';
    }

    /** A big-integer timestamp column (we store unix seconds). */
    public static function colTs(): string
    {
        return 'BIGINT';
    }

    /** TEXT column (long strings). */
    public static function colText(): string
    {
        return 'TEXT';
    }

    /** Table engine suffix (MySQL wants InnoDB + utf8mb4). */
    public static function tableSuffix(): string
    {
        return self::$driver === 'sqlite'
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }
}

// ── shared time + code helpers (mirror db.now_ts / unique_code) ──────────

/** Current unix timestamp (seconds). */
function now_ts(): int
{
    return time();
}

/** A random URL-safe token. */
function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Generate a share/join code unique within the given table+column.
 * Uppercase A-Z0-9, avoids ambiguous chars.
 */
function unique_code(string $table, string $col, int $len = 7): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no I,L,O,0,1
    for ($tries = 0; $tries < 40; $tries++) {
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $exists = DB::scalar("SELECT 1 FROM {$table} WHERE {$col} = ?", [$code]);
        if (!$exists) {
            return $code;
        }
    }
    // Fallback: extremely unlikely
    return strtoupper(substr(random_token(6), 0, $len));
}
