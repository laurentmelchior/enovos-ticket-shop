#!/usr/bin/env bash
# Idempotent environment install for the Enovos Concert Ticket Shop Importer.
# Prepares PHP + Poppler + a self-contained MariaDB, WordPress, WooCommerce and
# the plugin itself, then leaves the database ready for the start/serve scripts.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=env.sh
source "$SCRIPT_DIR/env.sh"

echo "==> [1/8] System packages"
if ! command -v php >/dev/null || ! command -v pdfseparate >/dev/null || ! command -v mariadbd >/dev/null; then
  sudo DEBIAN_FRONTEND=noninteractive apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    php-cli php-mysql php-gd php-imagick php-curl php-mbstring php-xml php-zip php-intl php-bcmath php-soap \
    mariadb-server mariadb-client \
    poppler-utils ghostscript imagemagick \
    unzip curl less jq ca-certificates
else
  echo "    PHP, Poppler and MariaDB already installed; skipping apt."
fi

echo "==> [2/8] WP-CLI"
if ! command -v wp >/dev/null; then
  sudo curl -sSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  sudo chmod +x /usr/local/bin/wp
fi
wp --info >/dev/null

echo "==> [3/8] MariaDB data directory"
if [ ! -d "$DB_DATA/mysql" ]; then
  mkdir -p "$DB_DATA"
  mariadb-install-db --no-defaults --datadir="$DB_DATA" \
    --auth-root-authentication-method=normal --skip-test-db >/dev/null
fi
db_start

echo "==> [4/8] Database + user"
mariadb --no-defaults -S "$DB_SOCK" -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> [5/8] WordPress core"
mkdir -p "$WP_DIR"
if [ ! -f "$WP_DIR/wp-load.php" ]; then
  wp_cli core download --version=latest --locale=en_US
fi
if [ ! -f "$WP_DIR/wp-config.php" ]; then
  wp_cli config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
    --dbhost="127.0.0.1:${DB_PORT}" --dbcharset=utf8mb4 --skip-check
  wp_cli config set WP_DEBUG true --raw --type=constant
  wp_cli config set WP_DEBUG_LOG true --raw --type=constant
  wp_cli config set WP_DEBUG_DISPLAY false --raw --type=constant
  wp_cli config set FS_METHOD direct --type=constant
fi
if ! wp_cli core is-installed >/dev/null 2>&1; then
  wp_cli core install --url="$WP_URL" --title="Enovos Ticket Shop Dev" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi

echo "==> [6/8] WooCommerce"
if ! wp_cli plugin is-installed woocommerce >/dev/null 2>&1; then
  wp_cli plugin install woocommerce --activate
else
  wp_cli plugin activate woocommerce >/dev/null 2>&1 || true
fi

echo "==> [7/8] Assemble + activate the Enovos plugin (includes/ layout)"
PLUGIN_DIR="$WP_DIR/wp-content/plugins/enovos-ticket-shop"
mkdir -p "$PLUGIN_DIR/includes"
# Real copy so plugin_dir_path(__FILE__) resolves to the plugin dir, not the repo.
cp -f "$REPO_DIR/enovos-ticket-shop.php" "$PLUGIN_DIR/enovos-ticket-shop.php"
ln -sf "$REPO_DIR/changelog.txt" "$PLUGIN_DIR/changelog.txt"
ln -sf "$REPO_DIR/README.md" "$PLUGIN_DIR/README.md"
for f in "$REPO_DIR"/class-enovos-ticket-*.php; do
  ln -sf "$f" "$PLUGIN_DIR/includes/$(basename "$f")"
done
wp_cli plugin activate enovos-ticket-shop >/dev/null 2>&1 || true

echo "==> [8/8] WooCommerce fixtures (VAT 3% tax class, den-atelier category, sample PDF)"
if ! wp_cli wc tax_class list --user=admin --field=slug 2>/dev/null | grep -qx "vat-3"; then
  wp_cli wc tax_class create --name="VAT 3%" --user=admin >/dev/null 2>&1 || true
fi
if ! wp_cli db query "SELECT tax_rate_id FROM wp_woocommerce_tax_rates WHERE tax_rate_class='vat-3';" 2>/dev/null | tail -n +2 | grep -q .; then
  wp_cli db query "INSERT INTO wp_woocommerce_tax_rates (tax_rate_country,tax_rate_state,tax_rate,tax_rate_name,tax_rate_priority,tax_rate_compound,tax_rate_shipping,tax_rate_order,tax_rate_class) VALUES ('','',3.0000,'VAT 3%',1,0,1,0,'vat-3');" >/dev/null 2>&1 || true
fi
if ! wp_cli term get product_cat --by=slug den-atelier >/dev/null 2>&1; then
  wp_cli wc product_cat create --name="den-atelier" --slug=den-atelier --user=admin >/dev/null 2>&1 || true
fi

# Sample multi-page ticket PDF used by verify-e2e.php.
if [ ! -f "$DEMO_PDF" ]; then
  mkdir -p "$(dirname "$DEMO_PDF")"
  PS_FILE="$(dirname "$DEMO_PDF")/tickets.ps"
  cat > "$PS_FILE" <<'PS'
%!PS-Adobe-3.0
/drawpage {
  /n exch def
  /Helvetica-Bold findfont 30 scalefont setfont
  72 720 moveto (den Atelier - Live Concert) show
  /Helvetica findfont 22 scalefont setfont
  72 670 moveto (Physical Ticket - PDF page ) show n 3 string cvs show
  72 630 moveto (Seat block A - General Admission) show
  72 590 moveto (Barcode: 1234-5678-90) show n 3 string cvs show
  showpage
} def
1 drawpage 2 drawpage 3 drawpage 4 drawpage 5 drawpage 6 drawpage
PS
  gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile="$DEMO_PDF" "$PS_FILE"
fi

echo ""
echo "Install complete."
echo "  WordPress:   $WP_URL  (admin / admin)"
echo "  Plugin admin: $WP_URL/wp-admin/admin.php?page=enovos-ticket-shop"
wp_cli plugin list --fields=name,status,version 2>/dev/null | grep -E "woocommerce|enovos-ticket-shop" || true
