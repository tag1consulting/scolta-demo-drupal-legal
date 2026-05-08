#!/usr/bin/env bash
set -euo pipefail

PORT=8080
IMAGE="scolta-smoke-$$"

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
for i in $(seq 1 30); do
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${PORT}/" 2>/dev/null || true)
  if [ -n "$HTTP_CODE" ] && [ "$HTTP_CODE" != "000" ]; then
    echo "==> Container responded: HTTP $HTTP_CODE — image build and start OK"
    exit 0
  fi
  sleep 2
done

echo "==> FAIL: no HTTP response after 60s"
docker logs "$IMAGE" || true
exit 1
