#!/bin/bash
#
# Starts the image with an empty catalogue and checks what each part does:
# nginx serves the catalogue as files, PHP serves the admin UI and webhooks,
# the worker builds what is queued, and no process serving HTTP holds
# COMPOSER_AUTH. Then the cases a 3.x deployment brings with it: a public/
# volume an older image filled, a mounted parameters.yml, and the web server
# and the worker in separate containers.
#
# Usage: docker/smoke-test.sh <image>

set -euo pipefail

image="${1:?usage: docker/smoke-test.sh <image>}"
port="${SMOKE_PORT:-8089}"
prefix="satisfy-smoke-$$"
dir="$(mktemp -d)"
base="http://127.0.0.1:$port"
name="$prefix"
containers=()
volumes=()

cleanup() {
  for c in "${containers[@]}"; do
    docker rm -f "$c" >/dev/null 2>&1 || true
  done
  for v in "${volumes[@]}"; do
    docker volume rm -f "$v" >/dev/null 2>&1 || true
  done
  rm -rf "$dir"
}
trap cleanup EXIT

fail() {
  echo "FAIL: $*" >&2
  docker logs "$name" 2>&1 | grep -v 'HTTP/1.1"' | tail -20 >&2 || true
  docker exec "$name" tail -20 var/log/build.log >&2 2>/dev/null || true
  exit 1
}

# Starts a container named $1 with the remaining arguments to docker run.
start() {
  name="$1"
  shift
  containers+=("$name")
  docker run -d --name "$name" "$@" >/dev/null
}

volume() {
  volumes+=("$1")
  docker volume create "$1" >/dev/null
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

wait_for() {
  local want="$1" path="$2"
  for _ in $(seq 1 60); do
    [ "$(status_of "$path")" = "$want" ] && return 0
    sleep 1
  done
}

# Queues a full build the way the web role does, through the container $1.
queue_full_build() {
  docker exec -u www-data "$1" sh -c 'printf "{\"repository\":null}" > "$SATISFY_QUEUE_DIR/full.json"'
}

# Prints the environment of each process in the current container whose
# command line starts with $1, read as the user it runs as, since a
# container's root cannot read another user's. "--" starts each one.
environ_of() {
  local user
  for user in root www-data; do
    docker exec -u "$user" "$name" sh -c '
      for d in /proc/[0-9]*; do
        case "$(tr "\0" " " < "$d/cmdline" 2>/dev/null)" in
          "$1"*)
            if env=$(tr "\0" "\n" 2>/dev/null < "$d/environ"); then
              echo "--"
              echo "$env"
            fi
            ;;
        esac
      done' sh "$1"
  done
}

expect_environ() {
  local process="$1" want="$2" environ
  environ="$(environ_of "$process")"
  grep -q '^--$' <<< "$environ" || fail "could not read the environment of $process"
  if grep -q '^COMPOSER_AUTH=' <<< "$environ"; then
    [ "$want" = with ] || fail "$process holds COMPOSER_AUTH"
  else
    [ "$want" = without ] || fail "$process does not have COMPOSER_AUTH"
  fi
}

running() {
  docker exec "$name" sh -c 'for d in /proc/[0-9]*; do tr "\0" " " < "$d/cmdline" 2>/dev/null; echo; done' \
    | grep -q "^$1"
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
auth='{"github-oauth":{"github.com":"smoke_token"}}'

echo "-- one container, every role"
start "$prefix" -p "127.0.0.1:$port:80" \
  -v "$dir/satis.json:/var/www/html/satis.json" \
  -e GITLAB_SECRET=smoke -e COMPOSER_AUTH="$auth" "$image"
wait_for 200 /admin
expect 200 /admin
expect 200 /
expect 404 /packages.json
expect 404 /index.php
expect 400 /webhook/gitlab POST

queue_full_build "$name"
wait_for 200 /packages.json
expect 200 /packages.json
curl -s "$base/" | grep -q 'smoke/test' || fail "/ is not the built catalogue"
echo "ok   the worker built the queued catalogue"

expect_environ 'php-fpm' without
expect_environ 'nginx' without
expect_environ 'php /var/www/html/bin/console satisfy:worker' with
echo "ok   COMPOSER_AUTH reaches the worker only"

started=$(date +%s)
docker stop "$name" >/dev/null
[ $(( $(date +%s) - started )) -lt 10 ] || fail "the container took 10 seconds or more to stop"
echo "ok   the container stops on SIGTERM"

docker run --rm -v "$dir/satis.json:/var/www/html/satis.json" "$image" rebuild >/dev/null \
  || fail "the rebuild role failed"
echo "ok   the rebuild role builds and exits"

echo "-- a public/ volume an older image filled, and a mounted parameters.yml"
volume "$prefix-public"
docker run --rm -v "$prefix-public:/p" --entrypoint sh "$image" -c '
  printf "<?php exit(1);\n" > /p/index.php
  printf "{\"packages\":[],\"smoke\":true}" > /p/packages.json
  chown -R www-data:www-data /p'
cat > "$dir/parameters.yml" <<'EOF'
parameters:
    admin.auth: true
    admin.users:
        admin: { password: smoke, roles: ROLE_ADMIN }
EOF
start "$prefix-upgrade" -p "127.0.0.1:$port:80" \
  -v "$dir/satis.json:/var/www/html/satis.json" \
  -v "$dir/parameters.yml:/var/www/html/config/parameters.yml:ro" \
  -v "$prefix-public:/var/www/html/public" "$image"
wait_for 200 /login
expect 200 /login
expect 302 /admin
curl -s "$base/packages.json" | grep -q '"smoke":true' || fail "the catalogue in the volume was not kept"
echo "ok   the old front controller was replaced and the catalogue kept"
docker rm -f "$name" >/dev/null

echo "-- the web server and the worker in separate containers"
volume "$prefix-output"
volume "$prefix-queue"
shared=(-v "$dir/satis.json:/var/www/html/satis.json"
  -v "$prefix-output:/var/www/html/public" -v "$prefix-queue:/var/www/queue")
start "$prefix-worker" "${shared[@]}" -e COMPOSER_AUTH="$auth" "$image" worker
running 'nginx' && fail "the worker container runs nginx"
start "$prefix-web" -p "127.0.0.1:$port:80" "${shared[@]}" "$image" web
wait_for 200 /admin
running 'php /var/www/html/bin/console satisfy:worker' && fail "the web container runs the worker"
queue_full_build "$name"
wait_for 200 /packages.json
expect 200 /packages.json
echo "ok   a build queued through the web container was built by the worker container"

echo "smoke test passed"
