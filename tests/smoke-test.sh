#!/usr/bin/env bash
#
# Docker smoke test.
#
# Builds the image, stands up a database beside it, loads the committed dump,
# runs the container against it, and asserts:
#   1. the site root returns a 2xx,
#   2. the search route returns a 2xx,
#   3. the Pagefind index metadata is served,
#   4. the index reports at least MIN_PAGES pages,
#   5. the About page setup script is committed.
#
# Two notes on why this script looks the way it does.
#
# First, assertions 1 and 2 must require a success status. An earlier version
# treated any response other than the curl failure code 000 as liveness, so a
# site returning HTTP 500 on every request still printed "image build and start
# OK" and exited 0. A test that cannot fail on a broken site is worse than no
# test, because every later run inherits the false confidence.
#
# Second, this image has no database of its own: it ships a mysql client and
# reads DB_HOST and friends from the environment, exactly as the production
# deployment does. Running it bare therefore always yields HTTP 500, and a 2xx
# assertion would be unsatisfiable. So the test provisions the same thing the
# Helm chart provisions: a MariaDB instance loaded from db/dump.sql.gz. That
# makes a 500 here mean the demo is broken, which is the whole point.
set -euo pipefail

PORT=8080
IMAGE="scolta-smoke-$$"
NETWORK="scolta-smoke-net-$$"
DB_CONTAINER="scolta-smoke-db-$$"

DB_NAME="drupal"
DB_USERNAME="drupal"
DB_PASSWORD="drupal"
DUMP_FILE="db/dump.sql.gz"

BASE_URL="http://localhost:${PORT}"
SEARCH_URL="${BASE_URL}/search"
PAGEFIND_ENTRY_URL="${BASE_URL}/sites/default/files/scolta-pagefind/pagefind/pagefind-entry.json"
MIN_PAGES=130

cd "$(dirname "$0")/.."

cleanup() {
  docker stop "$IMAGE" "$DB_CONTAINER" 2>/dev/null || true
  docker rm "$IMAGE" "$DB_CONTAINER" 2>/dev/null || true
  docker rmi "$IMAGE" 2>/dev/null || true
  docker network rm "$NETWORK" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Building Docker image..."
docker build -t "$IMAGE" .

test -f "$DUMP_FILE" || { echo "FAIL: $DUMP_FILE is missing, cannot load the database"; exit 1; }

echo "==> Creating network and starting the database..."
docker network create "$NETWORK" >/dev/null
# The image's own healthcheck is the reliable readiness signal. MariaDB's
# entrypoint runs a temporary server while it initialises, which answers pings
# before the real server exists and before the root password is set, so probing
# by hand races that startup and intermittently gets "Access denied".
docker run -d --name "$DB_CONTAINER" --network "$NETWORK" \
  -e MARIADB_ROOT_PASSWORD=root \
  -e MARIADB_DATABASE="$DB_NAME" \
  -e MARIADB_USER="$DB_USERNAME" \
  -e MARIADB_PASSWORD="$DB_PASSWORD" \
  --health-cmd="healthcheck.sh --connect --innodb_initialized" \
  --health-interval=5s \
  --health-timeout=5s \
  --health-retries=24 \
  mariadb:11 >/dev/null

echo "==> Waiting for the database to become healthy (up to 120s)..."
DB_READY=0
for _ in $(seq 1 60); do
  if [ "$(docker inspect -f '{{.State.Health.Status}}' "$DB_CONTAINER" 2>/dev/null)" = "healthy" ]; then
    DB_READY=1
    break
  fi
  sleep 2
done
if [ "$DB_READY" -ne 1 ]; then
  echo "FAIL: the database never became healthy"
  docker logs "$DB_CONTAINER" 2>&1 | tail -30
  exit 1
fi

echo "==> Loading $DUMP_FILE into the database..."
gunzip -c "$DUMP_FILE" | docker exec -i "$DB_CONTAINER" mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_NAME"

echo "==> Starting container on port $PORT..."
docker run -d --name "$IMAGE" --network "$NETWORK" -p "${PORT}:8080" \
  -e DB_HOST="$DB_CONTAINER" \
  -e DB_PORT=3306 \
  -e DB_NAME="$DB_NAME" \
  -e DB_USERNAME="$DB_USERNAME" \
  -e DB_PASSWORD="$DB_PASSWORD" \
  -e DRUPAL_HASH_SALT="smoke-test-not-a-secret" \
  "$IMAGE" >/dev/null

# Poll a URL until it returns a 2xx, following redirects. Prints the last status
# code seen. Returns non-zero if no 2xx arrived before the timeout, so the caller
# can tell "never came up" (000) from "came up broken" (5xx).
await_success() {
  local url="$1" tries="${2:-45}" code="000"
  for _ in $(seq 1 "$tries"); do
    code=$(curl -sS -L --max-redirs 5 -o /dev/null -w "%{http_code}" "$url" 2>/dev/null || echo "000")
    case "$code" in
      2*) echo "$code"; return 0 ;;
    esac
    sleep 2
  done
  echo "$code"
  return 1
}

# Explain a terminal status, then dump logs and fail.
fail_status() {
  local what="$1" url="$2" code="$3"
  echo "FAIL: $what did not return a success status (last seen: HTTP $code) at $url"
  case "$code" in
    000) echo "      No HTTP response at all: the container never served a request." ;;
    5*)  echo "      A 5xx means Apache answered but the application cannot serve the site." \
              "This is a broken demo, not a slow one." ;;
    4*)  echo "      A 4xx means the route is not being served as expected." ;;
  esac
  docker logs "$IMAGE" 2>&1 | tail -40
  exit 1
}

echo "==> Waiting for the site root to return a success status (up to 90s)..."
ROOT_CODE=$(await_success "${BASE_URL}/") || fail_status "site root" "${BASE_URL}/" "$ROOT_CODE"
echo "PASS: site root returned HTTP $ROOT_CODE"

echo "==> Checking the search route..."
SEARCH_CODE=$(await_success "$SEARCH_URL" 15) || fail_status "search route" "$SEARCH_URL" "$SEARCH_CODE"
echo "PASS: search route returned HTTP $SEARCH_CODE"

echo "==> Verifying search index..."
META_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$PAGEFIND_ENTRY_URL" 2>/dev/null || true)
if [ "$META_CODE" != "200" ]; then
  echo "FAIL: Pagefind index metadata not found at $PAGEFIND_ENTRY_URL (HTTP $META_CODE)"
  docker logs "$IMAGE" 2>&1 | tail -20
  exit 1
fi
echo "PASS: Pagefind index metadata served (HTTP 200)"

PAGE_COUNT=$(curl -s "$PAGEFIND_ENTRY_URL" | python3 -c "
import sys, json
d = json.load(sys.stdin)
counts = [d['languages'][l]['page_count'] for l in d.get('languages', {})]
print(max(counts) if counts else 0)
" 2>/dev/null || echo "0")

if [ "$PAGE_COUNT" -lt "$MIN_PAGES" ]; then
  echo "FAIL: Only $PAGE_COUNT pages indexed (minimum: $MIN_PAGES)"
  exit 1
fi
echo "PASS: $PAGE_COUNT pages indexed (minimum: $MIN_PAGES)"

echo "==> Verifying About page setup script exists..."
test -f scripts/setup-about-page.php || (echo "FAIL: scripts/setup-about-page.php missing from repo" && exit 1)
echo "PASS: scripts/setup-about-page.php committed (About page created on ddev start)"

echo "==> ALL SMOKE TESTS PASSED"
