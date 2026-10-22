#!/bin/bash
#
# Starts the image with an empty catalogue and checks what each part does:
# nginx serves the catalogue as files, PHP serves the admin UI and webhooks,
# the worker builds what is queued, and no process serving HTTP holds
# COMPOSER_AUTH.
#
# Usage: docker/smoke-test.sh <image>

set -euo pipefail

image="${1:?usage: docker/smoke-test.sh <image>}"
port="${SMOKE_PORT:-8089}"
name="satisfy-smoke-$$"
dir="$(mktemp -d)"
base="http://127.0.0.1:$port"

cleanup() {
  docker rm -f "$name" >/dev/null 2>&1 || true
  rm -rf "$dir"
}
trap cleanup EXIT

fail() {
  echo "FAIL: $*" >&2
  docker logs "$name" 2>&1 | tail -40 >&2 || true
  exit 1
}

status_of() {
  curl -s -o /dev/null -w '%{http_code}' -X "${2:-GET}" "$base$1"
}

expect() {
  local want="$1" path="$2" method="${3:-GET}" got
  got="$(status_of "$path" "$method")"
  [ "$got" = "$want" ] || fail "$method $path answered $got, expected $want"
  echo "ok   $method $path -> $got"
}

cat > "$dir/satis.json" <<'EOF'
{
    "name": "smoke/test",
    "homepage": "http://localhost",
    "repositories": [],
    "require-all": true
}
EOF
chmod 666 "$dir/satis.json"

docker run -d --name "$name" -p "127.0.0.1:$port:80" \
  -v "$dir/satis.json:/var/www/html/satis.json" \
  -e GITLAB_SECRET=smoke \
  -e COMPOSER_AUTH='{"github-oauth":{"github.com":"smoke-token"}}' \
  "$image" >/dev/null

for _ in $(seq 1 60); do
  [ "$(status_of /admin)" = 200 ] && break
  sleep 1
done

expect 200 /admin
expect 200 /
expect 404 /packages.json
expect 404 /index.php
expect 400 /webhook/gitlab POST

# Queue a full build the way the web role does, and wait for the worker.
docker exec -u www-data "$name" sh -c 'printf "{\"repository\":null}" > "$SATISFY_QUEUE_DIR/full.json"'
for _ in $(seq 1 60); do
  [ "$(status_of /packages.json)" = 200 ] && break
  sleep 1
done
expect 200 /packages.json
curl -s "$base/" | grep -q 'smoke/test' || fail "/ is not the built catalogue"
echo "ok   the worker built the queued catalogue"

# The credential reaches the worker and nothing that answers HTTP.
environ_of() {
  docker exec "$name" sh -c '
    for d in /proc/[0-9]*; do
      case "$(tr "\0" " " < "$d/cmdline" 2>/dev/null)" in
        '"$1"'*) tr "\0" "\n" < "$d/environ" ;;
      esac
    done'
}
environ_of 'php-fpm' | grep -q '^COMPOSER_AUTH=' && fail "php-fpm holds COMPOSER_AUTH"
environ_of 'nginx' | grep -q '^COMPOSER_AUTH=' && fail "nginx holds COMPOSER_AUTH"
environ_of 'php /var/www/html/bin/console satisfy:worker' | grep -q '^COMPOSER_AUTH=' \
  || fail "the worker does not have COMPOSER_AUTH"
echo "ok   COMPOSER_AUTH reaches the worker only"

started=$(date +%s)
docker stop "$name" >/dev/null
[ $(( $(date +%s) - started )) -lt 10 ] || fail "the container took 10 seconds or more to stop"
echo "ok   the container stops on SIGTERM"

docker run --rm -v "$dir/satis.json:/var/www/html/satis.json" "$image" rebuild >/dev/null \
  || fail "the rebuild role failed"
echo "ok   the rebuild role builds and exits"

echo "smoke test passed"
