#!/usr/bin/env bash
set -euo pipefail

# This script bootstraps a minimal TYPO3 installation for running tests.
# It uses an SQLite database stored under var/sqlite.db and creates
# a basic site configuration pointing to DDEV when available, otherwise
# http://localhost for host-only unit development.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BIN="$ROOT_DIR/vendor/bin/typo3"
DB_PATH="$ROOT_DIR/var/sqlite.db"
SITE_URL="${DDEV_PRIMARY_URL:-http://localhost}"

mkdir -p "$ROOT_DIR/public" "$ROOT_DIR/var"

$BIN setup \
  --driver=sqlite \
  --dbname="$DB_PATH" \
  --admin-username=admin \
  --admin-user-password=Admin123! \
  --admin-email=admin@example.com \
  --project-name="TYPO3 MCP Server" \
  --create-site="$SITE_URL" \
  --server-type=other \
  --no-interaction \
  --force

# TYPO3 setup runs from CLI and otherwise trusts only localhost. In DDEV,
# derive an exact anchored host pattern from the hostnames DDEV actually
# provisioned; never use the development-wide `.*` pattern.
if [ -n "${DDEV_HOSTNAME:-}" ]; then
  DDEV_HOSTNAME="$DDEV_HOSTNAME" php -r '
    $file = "config/system/settings.php";
    $settings = include $file;
    $hosts = array_values(array_filter(array_map("trim", explode(",", getenv("DDEV_HOSTNAME") ?: ""))));
    $escaped = array_map(static fn(string $host): string => preg_quote($host, "/"), $hosts);
    $settings["SYS"]["trustedHostsPattern"] = "^(?:" . implode("|", $escaped) . ")$";
    file_put_contents($file, "<?php\nreturn " . var_export($settings, true) . ";\n");
  '
fi

if [ ! -f "$ROOT_DIR/public/index.php" ]; then
  cat > "$ROOT_DIR/public/index.php" <<'PHP'
<?php
call_user_func(static function () {
    $classLoader = require __DIR__ . '/../vendor/autoload.php';
    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(1);
    \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader, true)->get(\TYPO3\CMS\Core\Http\Application::class)->run();
});
PHP
fi
