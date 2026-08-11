#!/usr/bin/env bash
# Long-running WordPress development server (visible terminal).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=env.sh
source "$SCRIPT_DIR/env.sh"

db_start
echo "Serving WordPress at ${WP_URL} (admin / admin)"
exec wp server --host=0.0.0.0 --port="$WP_PORT" --path="$WP_DIR"
