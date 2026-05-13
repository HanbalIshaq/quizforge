"""
One-time data migration: copy every row from a local SQLite DB into the Postgres
pointed at by DATABASE_URL.

Usage (Windows PowerShell):
    $env:DATABASE_URL = "postgresql://USER:PASS@HOST/DBNAME?sslmode=require"
    .\venv\Scripts\python.exe migrate_sqlite_to_postgres.py path/to/old.sqlite

Or pass --truncate to wipe existing Postgres tables first.
"""
import argparse
import os
import sqlite3
import sys

import db  # uses DATABASE_URL to pick the engine

TABLES_IN_ORDER = [
    # parents first, children after — respect foreign keys
    "users",
    "quizzes",
    "questions",
    "live_sessions",
    "attempts",
    "answers",
    "violations",
]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("sqlite_path", help="Path to source SQLite .db file")
    parser.add_argument("--truncate", action="store_true",
                        help="Wipe Postgres tables before importing")
    args = parser.parse_args()

    if not db.IS_POSTGRES:
        sys.exit("DATABASE_URL is not set or is not a postgres:// URL — set it first.")
    if not os.path.exists(args.sqlite_path):
        sys.exit(f"SQLite file not found: {args.sqlite_path}")

    src = sqlite3.connect(args.sqlite_path)
    src.row_factory = sqlite3.Row

    # Create schema in Postgres if needed
    db.init_db()

    dst = db.get_conn()
    try:
        if args.truncate:
            for t in reversed(TABLES_IN_ORDER):
                dst.execute(f"TRUNCATE TABLE {t} RESTART IDENTITY CASCADE")
            dst.commit()
            print(f"Truncated {len(TABLES_IN_ORDER)} tables.")

        for table in TABLES_IN_ORDER:
            rows = src.execute(f"SELECT * FROM {table}").fetchall()
            if not rows:
                print(f"  {table}: (empty)")
                continue
            cols = list(rows[0].keys())
            placeholders = ",".join(["?"] * len(cols))
            col_list = ",".join(cols)
            sql = f"INSERT INTO {table} ({col_list}) VALUES ({placeholders})"
            for r in rows:
                dst.execute(sql, tuple(r[c] for c in cols))
            # Re-sync the SERIAL sequence to the max id
            dst.execute(
                f"SELECT setval(pg_get_serial_sequence('{table}', 'id'), "
                f"COALESCE((SELECT MAX(id) FROM {table}), 1))"
            )
            print(f"  {table}: copied {len(rows)} row(s)")
        dst.commit()
        print("\nMigration complete.")
    finally:
        dst.close()
        src.close()


if __name__ == "__main__":
    main()
