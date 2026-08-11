#!/usr/bin/env bash
# Shared configuration and helpers for the Enovos Ticket Shop Cloud Agent environment.
# Sourced by install.sh, start.sh and serve.sh.

# Resolve the repository root from this file's location (<repo>/.cursor/env.sh).
ENOVOS_CURSOR_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export REPO_DIR="$(cd "$ENOVOS_CURSOR_DIR/.." && pwd)"

# WordPress lives outside the repository so the plugin repo stays clean.
export WP_DIR="${WP_DIR:-$HOME/wordpress}"
export WP_PORT="${WP_PORT:-8080}"
export WP_URL="http://localhost:${WP_PORT}"

# Self-contained MariaDB instance (runs as the current user, no systemd required).
export DB_DIR="${DB_DIR:-$HOME/wp-db}"
export DB_DATA="$DB_DIR/data"
export DB_SOCK="$DB_DIR/mysqld.sock"
export DB_PID="$DB_DIR/mysqld.pid"
export DB_LOG="$DB_DIR/mariadbd.log"
export DB_PORT="${DB_PORT:-3306}"
export DB_NAME="${DB_NAME:-wordpress}"
export DB_USER="${DB_USER:-wordpress}"
export DB_PASS="${DB_PASS:-wordpress}"

# Sample multi-page ticket PDF used by the end-to-end verification.
export DEMO_PDF="${DEMO_PDF:-$HOME/sample-pdf/tickets.pdf}"

export PATH="/usr/local/bin:$PATH"

# wp-cli helper (WordPress emits deprecation notices under WP_DEBUG that are noise on CLI).
wp_cli() { wp --path="$WP_DIR" "$@"; }

db_running() {
  [ -S "$DB_SOCK" ] && mariadb --no-defaults -S "$DB_SOCK" -u root -e "SELECT 1;" >/dev/null 2>&1
}

db_start() {
  if db_running; then
    return 0
  fi
  mkdir -p "$DB_DIR"
  echo "Starting MariaDB (datadir=$DB_DATA)..."
  nohup /usr/sbin/mariadbd --no-defaults \
    --datadir="$DB_DATA" \
    --socket="$DB_SOCK" \
    --pid-file="$DB_PID" \
    --bind-address=127.0.0.1 \
    --port="$DB_PORT" \
    --skip-name-resolve \
    >"$DB_LOG" 2>&1 &
  db_wait
}

db_wait() {
  for _ in $(seq 1 60); do
    if db_running; then
      echo "MariaDB is ready."
      return 0
    fi
    sleep 1
  done
  echo "ERROR: MariaDB did not become ready in time. Last log lines:" >&2
  tail -20 "$DB_LOG" >&2 || true
  return 1
}
