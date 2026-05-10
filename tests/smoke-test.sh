#!/usr/bin/env bash
set -euo pipefail

PORT=8080
IMAGE="scolta-smoke-$$"
PAGEFIND_ENTRY_URL="http://localhost:${PORT}/sites/default/files/scolta-pagefind/pagefind/pagefind-entry.json"
MIN_PAGES=130

echo "==> Building Docker image..."
docker build -t "$IMAGE" .

cleanup() {
  docker stop "$IMAGE" 2>/dev/null || true
  docker rm "$IMAGE" 2>/dev/null || true
  docker rmi "$IMAGE" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Starting container on port $PORT..."
docker run -d --name "$IMAGE" -p "${PORT}:8080" "$IMAGE"

echo "==> Waiting for HTTP server (up to 60s)..."
RESPONDED=0
for i in $(seq 1 30); do
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${PORT}/" 2>/dev/null || true)
  if [ -n "$HTTP_CODE" ] && [ "$HTTP_CODE" != "000" ]; then
    echo "==> Container responded: HTTP $HTTP_CODE — image build and start OK"
    RESPONDED=1
    break
  fi
  sleep 2
done

if [ "$RESPONDED" -eq 0 ]; then
  echo "==> FAIL: no HTTP response after 60s"
  docker logs "$IMAGE" || true
  exit 1
fi

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
