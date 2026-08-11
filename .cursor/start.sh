#!/usr/bin/env bash
# Per-boot reconciliation: ensure the self-contained MariaDB is running.
# The WordPress dev server runs as a visible terminal (see serve.sh), not here.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=env.sh
source "$SCRIPT_DIR/env.sh"

db_start
echo "MariaDB ready on 127.0.0.1:${DB_PORT}. WordPress dev server is provided by the 'wp-server' terminal."
