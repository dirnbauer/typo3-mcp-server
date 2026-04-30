#!/usr/bin/env bash
set -euo pipefail

#
# Run tests in a Docker container with the correct PHP version and extensions.
# Also supports local/Docker E2E runs.
#
# Usage:
#   Build/runTests.sh                          # Run all functional tests
#   Build/runTests.sh --filter=ReadTableTool   # Run specific tests
#   Build/runTests.sh -p 8.4                   # Use different PHP version
#   Build/runTests.sh --help                   # Show help
#

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_VERSION="8.3"
TEST_SUITE="functional"
NO_DOCKER=0
EXTRA_ARGS=()
RUN_ID="typo3-mcp-tests-$$"
CONTAINER_NAME="${RUN_ID}"
NETWORK_NAME="${RUN_ID}-net"
IMAGE=""

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- phpunit-args]

Options:
    -p <version>    PHP version: 8.2, 8.3 (default), 8.4, 8.5
    -s <suite>      Test suite: functional (default), lint, e2e
    -n, --no-docker Run e2e locally without Docker (uses host PHP, SQLite, local Playwright)
    -h, --help      Show this help

Any arguments after -- are passed directly to phpunit/paratest.
Short filter syntax (without --) is also supported:
    $(basename "$0") --filter=ReadTableTool

Examples:
    $(basename "$0")                                    # All functional tests
    $(basename "$0") --filter=ReadTableToolTest          # Filter by class
    $(basename "$0") -p 8.4                              # PHP 8.4
    $(basename "$0") -- --filter=testReadRecordsByPid    # Filter by method
EOF
    exit 0
}

# Parse arguments
while [ $# -gt 0 ]; do
    case "$1" in
        -p)
            PHP_VERSION="$2"
            shift 2
            ;;
        -s)
            TEST_SUITE="$2"
            shift 2
            ;;
        -n|--no-docker)
            NO_DOCKER=1
            shift
            ;;
        -h|--help)
            usage
            ;;
        --)
            shift
            EXTRA_ARGS+=("$@")
            break
            ;;
        *)
            EXTRA_ARGS+=("$1")
            shift
            ;;
    esac
done

IMAGE="php:${PHP_VERSION}-cli"

# Validate inputs
case "${PHP_VERSION}" in
    8.2|8.3|8.4|8.5) ;;
    *) echo "Error: unsupported PHP version '${PHP_VERSION}'. Use 8.2, 8.3, 8.4, or 8.5." >&2; exit 1 ;;
esac

case "${TEST_SUITE}" in
    functional|lint|e2e) ;;
    *) echo "Error: unsupported test suite '${TEST_SUITE}'. Use functional, lint, or e2e." >&2; exit 1 ;;
esac

# Auto-enable no-docker mode when docker is not installed or the daemon is unreachable.
if [ "${NO_DOCKER}" -eq 0 ] && [ "${TEST_SUITE}" = "e2e" ]; then
    if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
        echo "Note: docker unavailable, running e2e tests locally (--no-docker)." >&2
        NO_DOCKER=1
    fi
fi

# Cleanup function — removes containers and network (docker mode) or background PHP server (local mode)
LOCAL_WEB_PID=""
cleanup() {
    set +e
    if [ -n "${LOCAL_WEB_PID}" ]; then
        kill "${LOCAL_WEB_PID}" >/dev/null 2>&1
    fi
    if command -v docker >/dev/null 2>&1; then
        docker rm -f "${CONTAINER_NAME}" "${RUN_ID}-web" "${RUN_ID}-db" "${RUN_ID}-pw" >/dev/null 2>&1
        docker network rm "${NETWORK_NAME}" >/dev/null 2>&1
    fi
    set -e
}
trap cleanup EXIT

echo "Running ${TEST_SUITE} tests with PHP ${PHP_VERSION}..."

case "${TEST_SUITE}" in
    functional)
        docker run --rm \
            --name "${CONTAINER_NAME}" \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            -e "typo3DatabaseDriver=pdo_sqlite" \
            -e "typo3DatabaseName=typo3_test" \
            "${IMAGE}" \
            sh -c '
                set -e
                apt-get update -qq >/dev/null 2>&1 && \
                apt-get install -y -qq libsqlite3-dev libicu-dev libzip-dev unzip git >/dev/null 2>&1 && \
                docker-php-ext-install pdo_sqlite intl zip >/dev/null 2>&1 && \
                curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1 && \
                composer install --no-interaction --prefer-dist -q && \
                vendor/bin/phpunit -c phpunit.xml.dist "$@"
            ' sh "${EXTRA_ARGS[@]}"
        ;;
    lint)
        docker run --rm \
            --name "${CONTAINER_NAME}" \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${IMAGE}" \
            sh -c "
                find Classes Tests -name '*.php' -print0 | xargs -0 -n1 php -l
            "
        ;;
    e2e)
        if [ "${NO_DOCKER}" -eq 1 ]; then
            echo "Running E2E tests locally (host PHP + SQLite + Playwright)..."

            command -v php >/dev/null 2>&1 || { echo "Error: php is required for --no-docker mode." >&2; exit 1; }
            command -v composer >/dev/null 2>&1 || { echo "Error: composer is required for --no-docker mode." >&2; exit 1; }
            command -v npx >/dev/null 2>&1 || { echo "Error: npx (Node.js) is required for --no-docker mode." >&2; exit 1; }
            php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' \
                || { echo "Error: PHP extension pdo_sqlite is required for --no-docker mode." >&2; exit 1; }

            cd "${ROOT_DIR}"
            rm -rf var/cache var/log config/system/settings.php config/system/additional.php var/*.db 2>/dev/null || true

            echo "Installing composer dependencies..."
            composer install --no-interaction --prefer-dist --no-scripts -q

            echo "Setting up TYPO3 (SQLite)..."
            vendor/bin/typo3 setup \
                --driver=sqlite \
                --dbname="${ROOT_DIR}/var/sqlite.db" \
                --admin-username=admin \
                --admin-user-password=Admin123! \
                --admin-email=admin@example.com \
                --project-name=mcp-server-e2e \
                --server-type=other \
                --no-interaction \
                --force >/dev/null

            # Relax trusted-hosts / devIPmask for the built-in web server.
            php -r '$s=include"config/system/settings.php";$s["SYS"]["trustedHostsPattern"]=".*";$s["SYS"]["devIPmask"]="*";file_put_contents("config/system/settings.php","<?php\nreturn ".var_export($s,true).";\n");'
            rm -rf var/cache

            LOCAL_WEB_HOST="127.0.0.1"
            LOCAL_WEB_PORT="${TYPO3_E2E_PORT:-8080}"
            LOCAL_WEB_URL="http://${LOCAL_WEB_HOST}:${LOCAL_WEB_PORT}"

            echo "Starting PHP built-in web server at ${LOCAL_WEB_URL}..."
            mkdir -p var/log
            php -S "${LOCAL_WEB_HOST}:${LOCAL_WEB_PORT}" -t public/ >"${ROOT_DIR}/var/log/typo3-e2e-web.log" 2>&1 &
            LOCAL_WEB_PID=$!

            echo "Waiting for TYPO3..."
            for i in $(seq 1 60); do
                if ! kill -0 "${LOCAL_WEB_PID}" 2>/dev/null; then
                    echo "Web server exited unexpectedly. Logs:" >&2
                    tail -30 "${ROOT_DIR}/var/log/typo3-e2e-web.log" >&2
                    exit 1
                fi
                if curl -sf "${LOCAL_WEB_URL}/typo3/" -o /dev/null 2>&1; then
                    echo "TYPO3 is ready."
                    break
                fi
                if [ "$i" -eq 60 ]; then
                    echo "TYPO3 web server timeout. Logs:" >&2
                    tail -30 "${ROOT_DIR}/var/log/typo3-e2e-web.log" >&2
                    exit 1
                fi
                sleep 1
            done

            echo "Running Playwright tests..."
            cd "${ROOT_DIR}/Build"
            if [ ! -d node_modules ]; then
                npm ci
            fi
            # Idempotent: a no-op once the matching browser is cached.
            npx playwright install chromium
            TYPO3_BASE_URL="${LOCAL_WEB_URL}" CI="${CI:-}" \
                npx playwright test ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}
            exit 0
        fi

        echo "Running E2E tests (Docker: MySQL + PHP web server + Playwright)..."

        # Clean up stale state from previous runs (may be root-owned from Docker)
        docker run --rm -v "${ROOT_DIR}:/app" -w /app alpine sh -c \
            'rm -rf var/cache var/log config/system/settings.php config/system/additional.php var/*.db' 2>/dev/null || true

        # Create Docker network
        docker network create "${NETWORK_NAME}" >/dev/null 2>&1

        # Start MySQL container with tmpfs for speed
        echo "Starting MySQL..."
        docker run --rm -d \
            --name "${RUN_ID}-db" \
            --network "${NETWORK_NAME}" \
            --network-alias db \
            -e MYSQL_ROOT_PASSWORD=root \
            -e MYSQL_DATABASE=typo3 \
            --tmpfs /var/lib/mysql:rw,noexec,nosuid \
            mysql:8.0 >/dev/null

        for i in $(seq 1 30); do
            if docker exec "${RUN_ID}-db" mysqladmin ping -h localhost --silent 2>/dev/null; then
                echo "MySQL is ready."
                break
            fi
            [ "$i" -eq 30 ] && { echo "MySQL timeout" >&2; exit 1; }
            sleep 1
        done

        # Start TYPO3 web server in Docker (PHP 8.4 with MySQL extensions + native lazy objects)
        echo "Starting TYPO3 web server..."
        docker run --rm -d \
            --name "${RUN_ID}-web" \
            --network "${NETWORK_NAME}" \
            --network-alias web \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            -e "TYPO3_CONTEXT=Development" \
            "chialab/php:8.4" \
            bash -c 'set -e && rm -rf var/cache var/log && composer install --no-interaction --prefer-dist --no-scripts -q 2>&1 || composer update --no-interaction --prefer-dist --no-scripts -q 2>&1 && mkdir -p public && vendor/bin/typo3 setup --driver=mysqli --host=db --port=3306 --dbname=typo3 --username=root --password=root --admin-username=admin --admin-user-password=Admin123! --admin-email=admin@example.com --project-name=mcp-server-e2e --server-type=other --no-interaction --force && php -r '"'"'$s=include"config/system/settings.php";$s["SYS"]["trustedHostsPattern"]=".*";$s["SYS"]["devIPmask"]="*";file_put_contents("config/system/settings.php","<?php\nreturn ".var_export($s,true).";\n");'"'"' && rm -rf var/cache && exec php -S 0.0.0.0:8080 -t public/'

        echo "Waiting for TYPO3..."
        for i in $(seq 1 120); do
            # Check if container is still running
            if ! docker ps --format '{{.Names}}' | grep -q "${RUN_ID}-web"; then
                echo "Web container exited. Logs:" >&2
                docker logs "${RUN_ID}-web" 2>&1 | tail -30
                exit 1
            fi
            if docker exec "${RUN_ID}-web" curl -sf http://localhost:8080/typo3/ -o /dev/null 2>&1; then
                echo "TYPO3 is ready."
                break
            fi
            if [ "$i" -eq 120 ]; then
                echo "TYPO3 web server timeout. Logs:" >&2
                docker logs "${RUN_ID}-web" 2>&1 | tail -30
                exit 1
            fi
            sleep 2
        done

        # Run Playwright tests
        echo "Running Playwright tests..."
        mkdir -p "${ROOT_DIR}/Build/node_modules"
        docker run --rm \
            --name "${RUN_ID}-pw" \
            --network "${NETWORK_NAME}" \
            -v "${ROOT_DIR}/Build:/app" \
            -w /app \
            -e "TYPO3_BASE_URL=http://web:8080" \
            -e "CI=${CI:-}" \
            "mcr.microsoft.com/playwright:v1.52.0-noble" \
            /bin/bash -c "npm ci 2>/dev/null && npx playwright test ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}"
        ;;
    *)
        echo "Unknown test suite: ${TEST_SUITE}" >&2
        exit 1
        ;;
esac
