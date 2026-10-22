# Upgrading to Satisfy 4.0

4.0 is in development on the `4.x` branch. This file grows with it; `docs/4.0-architecture.md` describes the design.

## Builds run in a worker

- Run `bin/console satisfy:worker` alongside the web server. Webhooks and the admin UI queue builds; nothing builds until the worker takes them. `--once` runs whatever is queued and exits, which also suits a cron job.
- `SATISFY_QUEUE_DIR` (default `var/queue`) is where requests wait. The web server and the worker must both be able to write it.
- `SATISFY_BUILD_TIMEOUT` (default `600`) is how many seconds one Satis build may run.
- The worker writes the last build's output to `var/log/build.log`, and one JSON line per build to its own output.
- `bin/console satisfy:rebuild` still builds in the calling process. It now takes the same lock as the worker, so the two never run at once.

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
