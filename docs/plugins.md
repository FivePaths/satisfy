# Plugins

A plugin is a Symfony bundle that attaches to Satisfy's events. Enable plugins by naming them in `SATISFY_PLUGINS`, comma-separated:

```
SATISFY_PLUGINS=authz,hookgate
```

A name is one of the plugins in this repository, or the class of a bundle installed with Composer. Each set of plugins gets its own compiled container, so changing the list takes effect on the next start without clearing the cache.

## Writing one

The plugins in this repository live in `plugins/<Name>/`, extend `Playbloom\Plugin\AbstractPlugin`, and are laid out as:

| Path | What it holds |
|---|---|
| `src/` | The bundle class and the plugin's code, namespace `Playbloom\Plugin\<Name>` |
| `config/services.yaml` | Loaded only while the plugin is enabled |
| `src/Controller/` | Controllers whose route attributes are loaded only while it is enabled |
| `templates/` | Twig templates, as `@<Name>/...` |

A service implementing `Playbloom\Plugin\AdminLinkInterface` adds buttons to the admin home page.

### Events

| Event | Dispatched | A listener can |
|---|---|---|
| `WebhookReceivedEvent` | For every webhook delivery that passed its secret check, before the repository is looked up | `skip()` it (200) or `accept()` it (202) and do the work itself, for example on `kernel.terminate` once the response has gone |
| `RepositoryAddingEvent` | Before a webhook auto-adds a repository it does not know | `refuse()` it; the delivery is answered 200 and nothing is written |
| `BuildEvent` | A webhook or the admin UI asks for a build | Stop it before the queue sees it |
| `BuildStartingEvent` | The worker is about to run Satis | `cancel()` the build |
| `BuildFinishedEvent` | Satis has exited | Read the exit code |

To change `satis.json`, call `Manager::modify()`, which applies a change to the file as it is once the lock is held, or edit the configuration and call `Manager::flush()` with the version it started from.

## authz

Per-token access to the catalogue, with per-package grants. Each principal (a site, or a developer's laptop) holds a token. A grant of `vendor/name` or `vendor/*` implies everything the granted packages require, computed from the catalogue's `p2/` metadata, so nobody maintains a list of dependencies. A request for a package a site is not granted is recorded for approval; while the site's package mode is observe it is allowed anyway, and while it is enforce it is refused with 403.

nginx asks `plugins/Authz/public/verify.php` about every catalogue request through `auth_request`. That endpoint runs without the kernel, because Composer calls it for every file of every update, and caches its answers in APCu for 60 seconds. In the Docker image the entrypoint wires it up whenever the plugin is enabled: `auth_request` on `packages.json`, `p2/`, `include/`, `dist/` and the HTML catalogue, and three locations of its own:

| Path | What it is |
|---|---|
| `/_authzcheck` | A canary that always enforces, whatever the global switch says. Poll it with a valid token |
| `/_authzpurge` | `POST` with `X-Authz-Admin-Key: <ADMIN_KEY>` to clear the cache at once |
| `/admin/authz` | Sites, tokens, grants and the approval queue, behind Satisfy's admin login |

| Variable | Meaning |
|---|---|
| `TOKEN_PEPPER` | Required. Mixed into every token hash; changing it invalidates every token |
| `ADMIN_KEY` | Guards `/_authzpurge` |
| `AUTHZ_DATA_DIR` | The database, the audit log and the enforcement switch. Default `var/authz`; `/var/www/authz` in the image, which is a volume. It is the only state that cannot be rebuilt: losing it means reissuing every token |
| `AUTHZ_CATALOGUE_DIR` | Where the catalogue's `p2/` is. Default `public/` |
| `AUTHZ_REALM` | The realm of the Basic challenge, which helps tell this login from the admin one. Default `Composer repository` |
| `ENFORCE` | `true` refuses requests without a valid token when the switch file has never been set |

`bin/console authz` manages it from the command line, with the subcommands of the former `authzctl`: `issue`, `reissue`, `revoke`, `restore`, `forget`, `list`, `note`, `enforce`, `grant`, `ungrant`, `entitlements`, `pkg-enforce`, `requests`, `approve`, `dismiss`, `check`, `packages`, `deps`, `audit`, `unauth`, `stats` and `selftest`. Run it as `www-data`: `docker exec -u www-data <container> bin/console authz list`. Changes made there reach the web server within 60 seconds, or at once after a purge; changes made in the admin UI take effect at once.

Turn on `admin.auth` in `parameters.yml` when this plugin is enabled: `/admin/authz` issues tokens.

### Coming from the standalone authz service

- The database schema and file names are the same. Mount the old `authz/data` directory at `AUTHZ_DATA_DIR`, and keep `TOKEN_PEPPER` and `ADMIN_KEY`: every token keeps working.
- `authzctl <args>` becomes `docker exec -u www-data <container> bin/console authz <args>`.
- The web UI moves from `/_authzui`, with its own Basic login, to `/admin/authz`, with Satisfy's.
- Credentials are printed as `COMPOSER_AUTH` and `auth.json`, for the host in `satis.json`'s homepage. Its synchronisation with support.fivepaths.com, which was never switched on, is not ported.

## hookgate

For GitLab system hooks, which fire for every project on an instance.

- **The registration gate.** With `gitlab.auto_add_repo` on, the GitLab webhook adds a repository it does not know. hookgate lets it only if some branch or tag has a `composer.json` declaring one of `HOOKGATE_PACKAGE_TYPES`. A repository without one would make the next build that widens Composer's pool die, and a site declaring `project` carries a copied `composer.json` that would otherwise register. When GitLab cannot be asked, or does not answer clearly, the repository is added, as it would be without the plugin.
- **Auto-require.** A tag pushed to a package not yet published adds it to `require`, and its repository if needed, so that pushing the tag is all publishing takes. It refuses a type outside the list, a Drupal 7 project, a `drupal/` name drupal.org serves, a name in `satis.json`'s `blacklist`, a `composer.json` without a valid name or whose `version` is not the tag, and an edit the checker refuses. In `on` mode it answers GitLab at once and does the work after the response; the check runs without the lock on `satis.json`, and the result is written only if the file has not changed meanwhile, or planned again if it has.

| Variable | Meaning |
|---|---|
| `HOOKGATE_GITLAB_API_TOKEN` | A `read_api` token. `GITLAB_API_TOKEN` is read too. Without one, both parts stand aside |
| `HOOKGATE_GITLAB_API_BASE` | Default `https://<the project's host>/api/v4`. `GITLAB_API_BASE` is read too |
| `HOOKGATE_AUTO_REQUIRE` | `off`, `observe` (the default: decide and log, write nothing) or `on` |
| `HOOKGATE_PACKAGE_TYPES` | The Composer types published, comma-separated. The default is the list in `PackageTypes::DEFAULT` |
| `HOOKGATE_CHECKER` | A command that checks a candidate `satis.json` before auto-require writes it. It gets `SATIS_JSON`, `SATIS_CHECK_SCOPE=file`, `GITLAB_API_TOKEN`, `GITLAB_API_BASE` and `PACKAGE_TYPES_FILE`; exit 0 or 4 lets the write go ahead |
| `HOOKGATE_DRUPAL_ORG_P2` | Where to ask whether drupal.org serves a `drupal/` name |

Every decision is one JSON line on standard error: `docker logs <container> 2>&1 | grep -E 'auto_require|skipped_|registered_'`.

### Coming from the standalone hookgate service

- Point GitLab's system hook at Satisfy's `/webhook/gitlab`, with the same secret (`GITLAB_SECRET`). The `/_hookgate` front and its forwarding are gone.
- The decisions `skipped_*` and `auto_require_*` keep their names. `forwarded_new_package`, `forwarded_undetermined` and `forwarded_no_api_token` become `registered_new_package`, `registered_undetermined` and `registered_no_api_token`.
- `auto_require_published` and `auto_require_not_visible` are not ported: the build runs in the worker after the delivery, and its result is in the worker's log.
