# Upgrading to Satisfy 4.0

4.0 is in development on the `4.x` branch. This file grows with it; `docs/4.0-architecture.md` describes the design.

## PHP 8.4 and Symfony 7.4

- Satisfy needs PHP 8.4 or later. Satis itself has required 8.4 since July 2026, so no release that updates its dependencies could run on less.
- Symfony 7.4, the long-term release with security fixes until November 2029, replaces 6.4.
- `doctrine/annotations`, `doctrine/instantiator`, `ramunasd/symfony-container-mocks` and `symfony/proxy-manager-bridge` are no longer dependencies.
- Forms still use session-based CSRF tokens, as in 3.x. The stateless tokens Symfony's recipe now proposes check the `Origin` header, which no longer matches the request behind a proxy that terminates TLS.
- For code that extends Satisfy: the form types, the data transformer, the validators and `ConfigurationNormalizer` carry Symfony 7's parameter and return types. `ConfigurationNormalizer` now builds `Configuration`'s repositories itself, because Symfony 7.4's type resolution tries `\ArrayIterator` first among the types `setRepositories()` accepts and so never asks for `RepositoryInterface[]`.

## The Docker image

- NGINX Unit is gone. The image is `php:8.4-fpm-bookworm` with nginx, php-fpm and the worker, built from `docker/Dockerfile`. It still listens on port 80 and keeps the application in `/var/www/html`, so the same volume mounts work.
- The first argument picks what runs: `all` (the default), `web` (nginx and php-fpm), `worker`, or `rebuild` to build once and exit. Anything else runs as given.
- nginx serves `packages.json`, `p2/`, `include/`, `dist/` and the HTML catalogue from `public/` directly. PHP serves only the admin UI, `/login` and `/webhook/*`.
- nginx and php-fpm start without `COMPOSER_AUTH`; only the worker gets it. A Composer `auth.json` mounted into `/var/www/.composer` is still readable by every process in the container, so mount it into a separate worker container if that matters to you (README, "Running the web server and the worker apart").
- The configuration is compiled afresh on every start, so a changed `parameters.yml` or environment takes effect without `cache:clear`.
- If `public/` is a volume, the entrypoint copies the image's `index.php` and assets back into it on every start, because a volume keeps whatever image first filled it, and 3.x's `index.php` does not run on 4.0. The catalogue's files are not touched. Set `SATISFY_SYNC_PUBLIC=0` to stop it, as `docker-compose.yml` does for development.
- In the image the queue is `/var/www/queue` (`SATISFY_QUEUE_DIR`), a volume of its own, so separate web and worker containers can share it.
- The image is published to GHCR by `.github/workflows/docker-image.yaml`, after `docker/smoke-test.sh` has passed against it.

## `config/parameters.yml` is read again

3.x read `config/parameters.yml` through `config/config.yml`; the move to Symfony Flex on `main` stopped reading it, so a mounted one was silently ignored. 4.0 reads it again, after `config/services.yaml`, so its values override the defaults there, as in 3.x.

A 3.x deployment that mounted its own `config/config.yml` has to move those overrides into a file under `config/packages/`.

## Builds run in a worker

- Run `bin/console satisfy:worker` alongside the web server. Webhooks and the admin UI queue builds; nothing builds until the worker takes them. `--once` runs whatever is queued and exits, which also suits a cron job.
- `SATISFY_QUEUE_DIR` (default `var/queue`, and `/var/www/queue` in the image) is where requests wait. The web server and the worker must both be able to write it.
- `SATISFY_BUILD_TIMEOUT` (default `600`) is how many seconds one Satis build may run.
- The worker writes the last build's output to `var/log/build.log`, and one JSON line per build to its own output.
- `bin/console satisfy:rebuild` still builds in the calling process. It now takes the same lock as the worker, so the two never run at once. With no output directory given it uses the one `satis.json` names, or Satisfy's default `public`, as the worker does; in 3.x it failed when `satis.json` named none.

## Webhooks answer before the build

- A webhook answers `202 Accepted` with the body `queued` once the build is queued. In 3.x it answered after the build, `200` with the exit code or `500`.
- If a listener stops `BuildEvent` before the queue sees it, the webhook answers `200` with `skipped`.
- Anything that read the webhook's response to learn the build result has to read the worker's output or `var/log/build.log` instead.

## The admin UI's build page

- Opening the page no longer starts a build. Its button queues a full build, and the page shows the queue and the last build's log.
- The streaming endpoint `/admin/satis/buildRun` is gone.

## For code that extends Satisfy

- `AbstractWebhook::handle()` returns the dispatched `BuildEvent` instead of an exit code.
- `SatisBuildRunner::run()` and `SatisBuildRunner::onBuild()` are gone. `SatisBuildRunner::build(BuildRequest)` runs one build and returns a `BuildResult`. Only the worker calls it.
- `BuildEvent` is now handled by `BuildQueue::onBuild()`, which queues the build and marks the event queued.
- `Playbloom\Http\ProcessResponse` is gone.
- New events: `BuildStartingEvent`, which a listener can cancel, and `BuildFinishedEvent`, with Satis's exit code.
