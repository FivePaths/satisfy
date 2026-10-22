#!/bin/bash
#
# The container's entrypoint. The first argument is the role:
#
#   all       nginx, php-fpm and the worker (the default)
#   web       nginx and php-fpm: the catalogue, the admin UI and the webhooks
#   worker    satisfy:worker, which runs the builds the web role queues
#   rebuild   satisfy:rebuild once, with any further arguments
#
# Anything else runs as given. In every role the processes that answer HTTP
# requests start without COMPOSER_AUTH: they queue builds and never run them.

set -euo pipefail

app="${APP_PATH:-/var/www/html}"
pids=()

# Prefix that runs a command as www-data. setpriv replaces itself with the
# command rather than wrapping it, so signals reach the command itself.
as_app=()
if [ "$(id -u)" -eq 0 ]; then
  as_app=(env HOME=/var/www setpriv --reuid=www-data --regid=www-data --init-groups)
fi

prepare() {
  # A volume mounted on public/ keeps whatever image first filled it. Put this
  # image's front controller and assets back; the catalogue's files are left alone.
  if [ "${SATISFY_SYNC_PUBLIC:-1}" = 1 ]; then
    cp -a /usr/local/share/satisfy-public/. "$app/public/"
  fi
  queue="${SATISFY_QUEUE_DIR:-$app/var/queue}"
  mkdir -p "$app/var" "$queue"
  if [ "$(id -u)" -eq 0 ]; then
    chown www-data:www-data "$app/var" "$queue"
  fi

  # Compile the configuration on every start, so a changed parameters.yml or
  # environment takes effect without anyone running cache:clear.
  "${as_app[@]}" php "$app/bin/console" cache:clear --no-interaction
}

start_web() {
  env -u COMPOSER_AUTH php-fpm &
  pids+=($!)
  env -u COMPOSER_AUTH nginx -g 'daemon off;' &
  pids+=($!)
}

start_worker() {
  "${as_app[@]}" php "$app/bin/console" satisfy:worker &
  pids+=($!)
}

# The first process to end ends the container, so that a restart policy brings
# them all back together rather than leaving one half running.
supervise() {
  trap 'kill -TERM "${pids[@]}" 2>/dev/null || true' TERM INT
  set +e
  wait -n "${pids[@]}"
  status=$?
  kill -TERM "${pids[@]}" 2>/dev/null
  wait
  exit "$status"
}

role="${1:-all}"
case "$role" in
  all)
    prepare
    start_web
    start_worker
    supervise
    ;;
  web)
    prepare
    start_web
    supervise
    ;;
  worker)
    prepare
    exec "${as_app[@]}" php "$app/bin/console" satisfy:worker
    ;;
  rebuild)
    shift
    prepare
    exec "${as_app[@]}" php "$app/bin/console" satisfy:rebuild "$@"
    ;;
  *)
    exec "$@"
    ;;
esac
